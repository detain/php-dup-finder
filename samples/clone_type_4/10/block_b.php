<?php

declare(strict_types=1);

namespace App\Caching;

use Psr\Log\LoggerInterface;

final class LfuCacheService
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
     * Retrieves value from cache, incrementing frequency counter.
     */
    public function get(string $key): mixed
    {
        if (!isset($this->cache[$key])) {
            $this->logger->debug('Cache miss', ['key' => $key]);
            return null;
        }

        $this->cache[$key]['frequency']++;
        $value = $this->cache[$key]['value'];

        $this->logger->debug('Cache hit', ['key' => $key]);

        return $value;
    }

    /**
     * Stores value in cache, evicting least frequently used if needed.
     */
    public function set(string $key, mixed $value, int $ttl = 3600): void
    {
        if (count($this->cache) >= $this->maxSize && !isset($this->cache[$key])) {
            $this->evictLeastFrequentlyUsed();
        }

        $this->cache[$key] = [
            'value' => $value,
            'frequency' => 0,
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

    private function evictLeastFrequentlyUsed(): void
    {
        $lfuKey = null;
        $minFrequency = PHP_INT_MAX;

        foreach ($this->cache as $key => $entry) {
            if ($entry['frequency'] < $minFrequency) {
                $minFrequency = $entry['frequency'];
                $lfuKey = $key;
            }
        }

        if ($lfuKey !== null) {
            unset($this->cache[$lfuKey]);
            $this->logger->debug('LFU eviction', [
                'key' => $lfuKey,
                'frequency' => $minFrequency,
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
