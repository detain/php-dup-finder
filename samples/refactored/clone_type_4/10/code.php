<?php

declare(strict_types=1);

namespace App\Caching;

use Psr\Log\LoggerInterface;

interface CacheStrategyInterface
{
    public function get(string $key): mixed;
    public function set(string $key, mixed $value, int $ttl = 3600): void;
    public function delete(string $key): void;
    public function clear(): void;
    public function pruneExpired(): int;
}

abstract class AbstractCacheService implements CacheStrategyInterface
{
    protected array $cache = [];

    public function __construct(
        protected readonly LoggerInterface $logger,
    ) {}

    public function get(string $key): mixed
    {
        if (!$this->has($key)) {
            $this->logger->debug('Cache miss', ['key' => $key]);
            return null;
        }

        $this->logger->debug('Cache hit', ['key' => $key]);
        return $this->cache[$key]['value'];
    }

    public function set(string $key, mixed $value, int $ttl = 3600): void
    {
        $this->cache[$key] = [
            'value' => $value,
            'created_at' => microtime(true),
            'expires_at' => microtime(true) + $ttl,
        ];
    }

    public function delete(string $key): void
    {
        unset($this->cache[$key]);
    }

    public function clear(): void
    {
        $this->cache = [];
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

        return $pruned;
    }

    protected function has(string $key): bool
    {
        return isset($this->cache[$key]) && $this->cache[$key]['expires_at'] >= microtime(true);
    }
}

final class LruCacheService extends AbstractCacheService
{
    private int $maxSize;

    public function __construct(LoggerInterface $logger, int $maxSize = 100)
    {
        parent::__construct($logger);
        $this->maxSize = $maxSize;
    }

    public function get(string $key): mixed
    {
        if (!$this->has($key)) {
            return null;
        }

        $this->cache[$key]['accessed_at'] = microtime(true);
        return $this->cache[$key]['value'];
    }

    public function set(string $key, mixed $value, int $ttl = 3600): void
    {
        if (count($this->cache) >= $this->maxSize && !isset($this->cache[$key])) {
            $this->evictLRU();
        }

        parent::set($key, $value, $ttl);
        $this->cache[$key]['accessed_at'] = microtime(true);
    }

    private function evictLRU(): void
    {
        $oldestKey = null;
        $oldestTime = PHP_FLOAT_MAX;

        foreach ($this->cache as $key => $entry) {
            if (($entry['accessed_at'] ?? 0) < $oldestTime) {
                $oldestTime = $entry['accessed_at'] ?? 0;
                $oldestKey = $key;
            }
        }

        if ($oldestKey !== null) {
            unset($this->cache[$oldestKey]);
        }
    }
}

final class LfuCacheService extends AbstractCacheService
{
    private int $maxSize;

    public function __construct(LoggerInterface $logger, int $maxSize = 100)
    {
        parent::__construct($logger);
        $this->maxSize = $maxSize;
    }

    public function get(string $key): mixed
    {
        if (!$this->has($key)) {
            return null;
        }

        $this->cache[$key]['frequency']++;
        return $this->cache[$key]['value'];
    }

    public function set(string $key, mixed $value, int $ttl = 3600): void
    {
        if (count($this->cache) >= $this->maxSize && !isset($this->cache[$key])) {
            $this->evictLFU();
        }

        parent::set($key, $value, $ttl);
        $this->cache[$key]['frequency'] = 0;
    }

    private function evictLFU(): void
    {
        $lfuKey = null;
        $minFreq = PHP_INT_MAX;

        foreach ($this->cache as $key => $entry) {
            if (($entry['frequency'] ?? 0) < $minFreq) {
                $minFreq = $entry['frequency'] ?? 0;
                $lfuKey = $key;
            }
        }

        if ($lfuKey !== null) {
            unset($this->cache[$lfuKey]);
        }
    }
}
