<?php
declare(strict_types=1);

namespace App\Caching;

use App\Service\CacheService;
use App\Service\CacheKeyBuilder;
use App\Service\MetricsService;
use Psr\Log\LoggerInterface;

abstract class AbstractMetricsCacheHandler
{
    protected const CACHE_PREFIX = 'metrics';
    protected const DEFAULT_TTL = 60;

    public function __construct(
        protected readonly CacheService $cache,
        protected readonly CacheKeyBuilder $keyBuilder,
        protected readonly MetricsService $metrics,
        protected readonly LoggerInterface $logger,
    ) {
    }

    public function get(string $key, bool $allowStale = false): ?array
    {
        $cacheKey = $this->buildCacheKey($key);
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('cache.hit', ['type' => $this->getCacheType()]);
            return $cached;
        }

        $this->metrics->increment('cache.miss', ['type' => $this->getCacheType()]);
        return null;
    }

    public function set(string $key, array $data, ?int $ttl = null): void
    {
        $cacheKey = $this->buildCacheKey($key);
        $ttl = $ttl ?? static::DEFAULT_TTL;
        $this->cache->set($cacheKey, $data, $ttl);
    }

    public function invalidate(string $key): void
    {
        $this->cache->delete($this->buildCacheKey($key));
    }

    public function refresh(string $key, callable $fetcher): void
    {
        $data = $fetcher($key);
        if ($data === null) {
            $this->cache->delete($this->buildCacheKey($key));
            return;
        }
        $this->set($key, $data);
    }

    public function warm(array $keys): void
    {
        foreach ($keys as $key) {
            $data = $this->fetch($key);
            if ($data !== null) {
                $this->set($key, $data);
            }
        }
    }

    public function getOrSet(string $key, callable $fetcher, ?int $ttl = null): array
    {
        $cached = $this->get($key);
        if ($cached !== null) {
            return $cached;
        }

        $data = $fetcher($key);
        if ($data !== null) {
            $this->set($key, $data, $ttl);
        }
        return $data;
    }

    abstract protected function buildCacheKey(string $key): string;
    abstract protected function getCacheType(): string;
    abstract protected function fetch(string $key): ?array;
}
