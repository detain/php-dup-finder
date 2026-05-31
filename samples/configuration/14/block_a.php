<?php

declare(strict_types=1);

namespace App\Services\Database;

use PDO;
use PDOException;
use Psr\Log\LoggerInterface;

final class DatabaseConnectionPool
{
    private const POOL_MIN_SIZE = 5;
    private const POOL_MAX_SIZE = 20;
    private const POOL_ACQUIRE_TIMEOUT = 5;
    private const POOL_IDLE_TIMEOUT = 600;
    private const CONNECTION_TIMEOUT = 10;
    private const COMMAND_TIMEOUT = 30;
    private const RETRY_ATTEMPTS = 3;
    private const RETRY_DELAY = 100;
    private const QUERY_CACHE_SIZE = 100;
    private const STATEMENT_CACHE_SIZE = 50;

    /** @var PDO[] */
    private array $connections = [];
    private int $activeConnections = 0;
    private int $idleConnections = 0;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $host,
        private readonly int $port,
        private readonly string $database,
        private readonly string $username,
        private readonly string $password
    ) {}

    public function acquire(): PDO
    {
        $attempts = 0;

        while ($attempts < self::RETRY_ATTEMPTS) {
            try {
                if ($this->hasAvailableConnection()) {
                    $connection = $this->getConnectionFromPool();
                    $this->activeConnections++;

                    $this->logger->debug('Connection acquired from pool', [
                        'active' => $this->activeConnections,
                        'idle' => $this->idleConnections,
                        'pool_min' => self::POOL_MIN_SIZE,
                        'pool_max' => self::POOL_MAX_SIZE,
                        'acquire_timeout' => self::POOL_ACQUIRE_TIMEOUT,
                    ]);

                    return $connection;
                }

                if ($this->activeConnections < self::POOL_MAX_SIZE) {
                    $connection = $this->createNewConnection();
                    $this->connections[] = [
                        'connection' => $connection,
                        'in_use' => true,
                        'created_at' => time(),
                        'last_used_at' => time(),
                    ];
                    $this->activeConnections++;

                    $this->logger->info('New connection created', [
                        'active' => $this->activeConnections,
                        'pool_size' => count($this->connections),
                        'connection_timeout' => self::CONNECTION_TIMEOUT,
                    ]);

                    return $connection;
                }

                $this->logger->warning('Pool exhausted, waiting for connection', [
                    'active' => $this->activeConnections,
                    'max' => self::POOL_MAX_SIZE,
                    'timeout' => self::POOL_ACQUIRE_TIMEOUT,
                ]);

                sleep(1);
                $attempts++;
            } catch (PDOException $e) {
                $attempts++;
                $this->logger->error('Failed to acquire connection', [
                    'attempt' => $attempts,
                    'max_attempts' => self::RETRY_ATTEMPTS,
                    'error' => $e->getMessage(),
                    'retry_delay' => self::RETRY_DELAY,
                ]);

                if ($attempts < self::RETRY_ATTEMPTS) {
                    usleep(self::RETRY_DELAY * 1000);
                }
            }
        }

        throw new \RuntimeException(
            sprintf('Failed to acquire database connection after %d attempts', self::RETRY_ATTEMPTS)
        );
    }

    public function release(PDO $connection): void
    {
        foreach ($this->connections as &$conn) {
            if ($conn['connection'] === $connection) {
                $conn['in_use'] = false;
                $conn['last_used_at'] = time();
                $this->activeConnections--;
                $this->idleConnections++;

                $this->logger->debug('Connection released to pool', [
                    'active' => $this->activeConnections,
                    'idle' => $this->idleConnections,
                    'idle_timeout' => self::POOL_IDLE_TIMEOUT,
                ]);

                return;
            }
        }
    }

    private function hasAvailableConnection(): bool
    {
        foreach ($this->connections as $conn) {
            if (!$conn['in_use']) {
                return true;
            }
        }
        return false;
    }

    private function getConnectionFromPool(): PDO
    {
        foreach ($this->connections as &$conn) {
            if (!$conn['in_use']) {
                $conn['in_use'] = true;
                $conn['last_used_at'] = time();
                $this->idleConnections--;

                if ($this->isConnectionStale($conn)) {
                    $this->logger->info('Replacing stale connection', [
                        'idle_time' => time() - $conn['last_used_at'],
                        'stale_threshold' => self::POOL_IDLE_TIMEOUT,
                    ]);

                    $key = array_search($conn, $this->connections, true);
                    unset($this->connections[$key]);

                    return $this->createNewConnection();
                }

                return $conn['connection'];
            }
        }

        throw new \RuntimeException('No available connections in pool');
    }

    private function isConnectionStale(array $conn): bool
    {
        return (time() - $conn['last_used_at']) > self::POOL_IDLE_TIMEOUT;
    }

    private function createNewConnection(): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4;timeout=%d',
            $this->host,
            $this->port,
            $this->database,
            self::CONNECTION_TIMEOUT
        );

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci',
            PDO::MYSQL_ATTR_FOUND_ROWS => true,
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
            PDO::ATTR_PERSISTENT => false,
        ];

        try {
            $connection = new PDO(
                $dsn,
                $this->username,
                $this->password,
                $options
            );

            $this->logger->info('Database connection established', [
                'host' => $this->host,
                'database' => $this->database,
                'command_timeout' => self::COMMAND_TIMEOUT,
                'query_cache_size' => self::QUERY_CACHE_SIZE,
                'statement_cache_size' => self::STATEMENT_CACHE_SIZE,
            ]);

            return $connection;
        } catch (PDOException $e) {
            $this->logger->error('Database connection failed', [
                'host' => $this->host,
                'port' => $this->port,
                'database' => $this->database,
                'error' => $e->getMessage(),
                'connection_timeout' => self::CONNECTION_TIMEOUT,
                'retry_attempts' => self::RETRY_ATTEMPTS,
            ]);
            throw $e;
        }
    }

    public function getStats(): array
    {
        return [
            'total' => count($this->connections),
            'active' => $this->activeConnections,
            'idle' => $this->idleConnections,
            'pool_min' => self::POOL_MIN_SIZE,
            'pool_max' => self::POOL_MAX_SIZE,
        ];
    }

    public function closeAll(): void
    {
        foreach ($this->connections as $conn) {
            $conn['connection'] = null;
        }
        $this->connections = [];
        $this->activeConnections = 0;
        $this->idleConnections = 0;

        $this->logger->info('All connections closed', [
            'total_closed' => count($this->connections),
        ]);
    }
}
