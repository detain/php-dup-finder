<?php

declare(strict_types=1);

namespace App\Caching;

use Psr\Log\LoggerInterface;

final class TtlCacheService
{
    private array $cache = [];

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Retrieves value from cache if not expired.
     */
    public function get(string $key): mixed
    {
        if (!isset($this->cache[$key])) {
            $this->logger->debug('Cache miss', ['key' => $key]);
            return null;
        }

        $entry = $this->cache[$key];

        if ($entry['expires_at'] < microtime(true)) {
            unset($this->cache[$key]);
            $this->logger->debug('Cache expired', ['key' => $key]);
            return null;
        }

        $this->logger->debug('Cache hit', ['key' => $key]);

        return $entry['value'];
    }

    /**
     * Stores value in cache with TTL.
     */
    public function set(string $key, mixed $value, int $ttl = 3600): void
    {
        $this->cache[$key] = [
            'value' => $value,
            'created_at' => microtime(true),
            'expires_at' => microtime(true) + $ttl,
        ];

        $this->logger->debug('Cache set', [
            'key' => $key,
            'ttl' => $ttl,
        ]);
    }

    /**
     * Removes specific key from cache.
     */
    public function delete(string $key): void
    {
        if (isset($this->cache[$key])) {
            unset($this->cache[$key]);
            $this->logger->debug('Cache delete', ['key' => $key]);
        }
    }

    /**
     * Clears all cache entries.
     */
    public function clear(): void
    {
        $this->cache = [];
        $this->logger->info('Cache cleared');
    }

    public function pruneExpired(): int
    {
        $now = microtime(true);
        $pruned = 0;

        foreach ($this->cache as $key => $entry) {
            if ($entry['expires_at'] < $now) {
                unset($this->cache[$key]);
                $pruned++;
            }
        }

        if ($pruned > 0) {
            $this->logger->debug('Expired entries pruned', ['count' => $pruned]);
        }

        return $pruned;
    }
}
