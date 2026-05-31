<?php

declare(strict_types=1);

namespace App\Services\Cache;

use Redis;
use RedisException;
use Psr\Log\LoggerInterface;

final class UserSessionCache
{
    private const CACHE_TIMEOUT = 3600;
    private const CACHE_RETRIES = 3;
    private const CACHE_RETRY_DELAY = 100;
    private const CACHE_POOL_SIZE = 32;
    private const CACHE_PREFIX = 'user_session:';
    private const CACHE_COMPRESSION = 'gzip';

    private ?Redis $connection = null;
    private readonly string $host;
    private readonly int $port;
    private readonly ?string $password;

    public function __construct(
        private readonly LoggerInterface $logger,
        string $redisHost = '127.0.0.1',
        int $redisPort = 6379,
        ?string $redisPassword = null
    ) {
        $this->host = $redisHost;
        $this->port = $redisPort;
        $this->password = $redisPassword;
    }

    public function get(string $userId): ?array
    {
        $this->ensureConnection();

        $key = self::CACHE_PREFIX . $userId;
        $attempts = 0;

        while ($attempts < self::CACHE_RETRIES) {
            try {
                $data = $this->connection->get($key);

                if ($data === false) {
                    return null;
                }

                $decoded = msgpack_unpack($data);

                if ($decoded === false) {
                    $this->logger->warning('Failed to decode cache data', [
                        'key' => $key,
                        'user_id' => $userId,
                    ]);
                    return null;
                }

                $this->logger->debug('Cache hit for user session', [
                    'user_id' => $userId,
                    'attempts' => $attempts + 1,
                ]);

                return $decoded;
            } catch (RedisException $e) {
                $attempts++;
                $this->logger->error('Redis read error', [
                    'error' => $e->getMessage(),
                    'attempt' => $attempts,
                    'max_attempts' => self::CACHE_RETRIES,
                    'user_id' => $userId,
                ]);

                if ($attempts < self::CACHE_RETRIES) {
                    usleep(self::CACHE_RETRY_DELAY * 1000);
                    $this->reconnect();
                }
            }
        }

        $this->logger->critical('Cache read failed after all retries', [
            'user_id' => $userId,
            'total_attempts' => self::CACHE_RETRIES,
        ]);

        return null;
    }

    public function set(string $userId, array $sessionData, ?int $ttl = null): bool
    {
        $this->ensureConnection();

        $key = self::CACHE_PREFIX . $userId;
        $ttl = $ttl ?? self::CACHE_TIMEOUT;
        $encoded = msgpack_pack($sessionData);

        $attempts = 0;

        while ($attempts < self::CACHE_RETRIES) {
            try {
                $result = $this->connection->setex($key, $ttl, $encoded);

                if ($result) {
                    $this->logger->info('User session cached', [
                        'user_id' => $userId,
                        'ttl' => $ttl,
                        'pool_size' => self::CACHE_POOL_SIZE,
                    ]);
                    return true;
                }

                $this->logger->warning('Cache set returned false', [
                    'user_id' => $userId,
                ]);
                return false;
            } catch (RedisException $e) {
                $attempts++;
                $this->logger->error('Redis write error', [
                    'error' => $e->getMessage(),
                    'attempt' => $attempts,
                    'user_id' => $userId,
                ]);

                if ($attempts < self::CACHE_RETRIES) {
                    usleep(self::CACHE_RETRY_DELAY * 1000);
                    $this->reconnect();
                }
            }
        }

        return false;
    }

    public function delete(string $userId): bool
    {
        $this->ensureConnection();

        $key = self::CACHE_PREFIX . $userId;

        try {
            $result = $this->connection->del($key) > 0;

            $this->logger->info('User session cache invalidated', [
                'user_id' => $userId,
                'deleted' => $result,
            ]);

            return $result;
        } catch (RedisException $e) {
            $this->logger->error('Failed to delete cache key', [
                'error' => $e->getMessage(),
                'user_id' => $userId,
            ]);
            return false;
        }
    }

    private function ensureConnection(): void
    {
        if ($this->connection !== null && $this->connection->ping()) {
            return;
        }

        $this->connect();
    }

    private function connect(): void
    {
        $this->connection = new Redis();
        $this->connection->setOption(Redis::OPT_COMPRESSION, self::CACHE_COMPRESSION);
        $this->connection->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_MSGPACK);

        try {
            $this->connection->connect(
                $this->host,
                $this->port,
                self::CACHE_TIMEOUT,
                null,
                self::CACHE_RETRIES,
                self::CACHE_RETRY_DELAY
            );

            if ($this->password !== null) {
                $this->connection->auth($this->password);
            }

            $this->connection->setOption(Redis::OPT_READ_TIMEOUT, (string) self::CACHE_TIMEOUT);

            $this->logger->info('Redis connection established', [
                'host' => $this->host,
                'port' => $this->port,
                'pool_size' => self::CACHE_POOL_SIZE,
            ]);
        } catch (RedisException $e) {
            $this->logger->error('Failed to connect to Redis', [
                'error' => $e->getMessage(),
                'host' => $this->host,
                'port' => $this->port,
            ]);
            throw $e;
        }
    }

    private function reconnect(): void
    {
        $this->connection = null;
        $this->connect();
    }
}
