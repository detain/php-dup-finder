<?php

declare(strict_types=1);

namespace App\Caching;

use Psr\Log\LoggerInterface;

final class LruCacheService
{
    private array $cache = [];
    private int $maxSize;

    public function __construct(
        private readonly LoggerInterface $logger,
        int $maxSize = 100,
    ) {
        $this->maxSize = $maxSize;
    }

    /**
     * Retrieves value from cache, updating access order for LRU.
     */
    public function get(string $key): mixed
    {
        if (!isset($this->cache[$key])) {
            $this->logger->debug('Cache miss', ['key' => $key]);
            return null;
        }

        $value = $this->cache[$key]['value'];
        $this->cache[$key]['accessed_at'] = microtime(true);

        $this->logger->debug('Cache hit', ['key' => $key]);

        return $value;
    }

    /**
     * Stores value in cache, evicting least recently used if needed.
     */
    public function set(string $key, mixed $value, int $ttl = 3600): void
    {
        if (count($this->cache) >= $this->maxSize && !isset($this->cache[$key])) {
            $this->evictLeastRecentlyUsed();
        }

        $this->cache[$key] = [
            'value' => $value,
            'created_at' => microtime(true),
            'accessed_at' => microtime(true),
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

    private function evictLeastRecentlyUsed(): void
    {
        $oldestKey = null;
        $oldestTime = PHP_FLOAT_MAX;

        foreach ($this->cache as $key => $entry) {
            if ($entry['accessed_at'] < $oldestTime) {
                $oldestTime = $entry['accessed_at'];
                $oldestKey = $key;
            }
        }

        if ($oldestKey !== null) {
            unset($this->cache[$oldestKey]);
            $this->logger->debug('LRU eviction', [
                'key' => $oldestKey,
                'time' => $oldestTime,
            ]);
        }
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
