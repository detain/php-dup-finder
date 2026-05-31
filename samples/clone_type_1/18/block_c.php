<?php

declare(strict_types=1);

namespace App\Cache\Redis;

use App\Service\CacheBackendInterface;
use Psr\Log\LoggerInterface;
use Redis;

final class RedisCacheService implements CacheBackendInterface
{
    private Redis $redis;

    public function __construct(
        private readonly LoggerInterface $logger,
        string $host = 'localhost',
        int $port = 6379,
    ) {
        $this->redis = new Redis();
        $this->redis->connect($host, $port);
    }

    public function get(string $key): mixed
    {
        $value = $this->redis->get($key);

        if ($value === false) {
            $this->logger->debug('Redis cache miss', ['key' => $key]);
            return null;
        }

        $this->logger->debug('Redis cache hit', ['key' => $key]);
        return unserialize($value);
    }

    public function set(string $key, mixed $value, int $ttl = 3600): void
    {
        $this->redis->setex($key, $ttl, serialize($value));

        $this->logger->debug('Redis cache set', [
            'key' => $key,
            'ttl' => $ttl,
        ]);
    }

    public function delete(string $key): void
    {
        $this->redis->del($key);
        $this->logger->debug('Redis cache delete', ['key' => $key]);
    }

    public function clear(): void
    {
        $this->redis->flushDB();
        $this->logger->info('Redis cache cleared');
    }

    public function has(string $key): bool
    {
        return $this->redis->exists($key) === 1;
    }

    public function prune(): int
    {
        return 0;
    }
}
