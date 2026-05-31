<?php
declare(strict_types=1);

namespace Shared\Infrastructure;

final class DatabaseConnectionConfig
{
    public const DEFAULT_HOST = 'localhost';
    public const DEFAULT_PORT = 3306;
    public const DEFAULT_CHARSET = 'utf8mb4';

    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $database,
        public readonly string $username,
        public readonly string $password,
    ) {}

    public function toDsn(): string
    {
        return sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $this->host,
            $this->port,
            $this->database,
            self::DEFAULT_CHARSET
        );
    }

    public function toPdoOptions(): array
    {
        return [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ];
    }
}

final class ServiceEndpointConfig
{
    public const DEFAULT_TIMEOUT = 30;
    public const DEFAULT_RETRIES = 3;

    public function __construct(
        public readonly string $baseUrl,
        public readonly string $apiKey,
        public readonly int $timeoutSeconds = self::DEFAULT_TIMEOUT,
        public readonly int $retryAttempts = self::DEFAULT_RETRIES,
    ) {}
}

final class CacheConfig
{
    public const DEFAULT_TTL = 3600;

    public function __construct(
        public readonly int $ttlSeconds = self::DEFAULT_TTL,
        public readonly string $prefix = '',
    ) {}
}

final class RateLimitConfig
{
    public const DEFAULT_LIMIT_PER_MINUTE = 100;
    public const DEFAULT_BATCH_SIZE = 50;

    public function __construct(
        public readonly int $limitPerMinute = self::DEFAULT_LIMIT_PER_MINUTE,
        public readonly int $batchSize = self::DEFAULT_BATCH_SIZE,
        public readonly int $timeoutSeconds = 60,
    ) {}
}

interface ServiceWithConnectionInterface
{
    public function createConnection(): \PDO;
    public function checkRateLimit(): void;
    public function getFromCache(string $key): mixed;
    public function setInCache(string $key, mixed $value, ?int $ttl = null): void;
}

trait ServiceConnectionLogic
{
    private DatabaseConnectionConfig $dbConfig;
    private CacheConfig $cacheConfig;
    private RateLimitConfig $rateLimitConfig;

    public function createConnection(): \PDO
    {
        return new \PDO(
            $this->dbConfig->toDsn(),
            $this->dbConfig->username,
            $this->dbConfig->password,
            $this->dbConfig->toPdoOptions()
        );
    }

    public function checkRateLimit(): void
    {
        $counterKey = 'rate_limit_' . static::class;
        $currentCount = apcu_inc($counterKey, 1, $success);

        if (!$success) {
            apcu_store($counterKey, 1, 60);
            $currentCount = 1;
        }

        if ($currentCount > $this->rateLimitConfig->limitPerMinute) {
            throw new \RuntimeException('Rate limit exceeded for ' . static::class);
        }
    }

    public function getFromCache(string $key): mixed
    {
        $fullKey = $this->cacheConfig->prefix . $key;
        $cached = apcu_fetch($fullKey, $success);

        return $success ? unserialize($cached) : null;
    }

    public function setInCache(string $key, mixed $value, ?int $ttl = null): void
    {
        $fullKey = $this->cacheConfig->prefix . $key;
        apcu_store($fullKey, serialize($value), $ttl ?? $this->cacheConfig->ttlSeconds);
    }
}
