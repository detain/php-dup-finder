<?php
declare(strict_types=1);

namespace App\Caching;

use App\Service\CacheService;
use App\Service\CacheKeyBuilder;
use App\Service\MetricsService;
use Psr\Log\LoggerInterface;

abstract class AbstractReportCacheHandler
{
    protected const CACHE_PREFIX = 'report';
    protected const DEFAULT_TTL = 3600;
    protected const STALE_TTL = 1800;

    public function __construct(
        protected readonly CacheService $cache,
        protected readonly CacheKeyBuilder $keyBuilder,
        protected readonly MetricsService $metrics,
        protected readonly LoggerInterface $logger,
    ) {
    }

    public function get(int $id, bool $allowStale = false): ?array
    {
        $cacheKey = $this->buildCacheKey($id);
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('cache.hit', ['type' => $this->getCacheType()]);
            return $cached;
        }

        $this->metrics->increment('cache.miss', ['type' => $this->getCacheType()]);
        return null;
    }

    public function set(int $id, array $data, ?int $ttl = null): void
    {
        $cacheKey = $this->buildCacheKey($id);
        $ttl = $ttl ?? static::DEFAULT_TTL;
        $this->cache->set($cacheKey, $data, $ttl);
    }

    public function invalidate(int $id): void
    {
        $this->cache->delete($this->buildCacheKey($id));
    }

    public function refresh(int $id, callable $fetcher): void
    {
        $data = $fetcher($id);
        if ($data === null) {
            $this->cache->delete($this->buildCacheKey($id));
            return;
        }
        $this->set($id, $data);
    }

    public function warm(int $id): void
    {
        $data = $this->fetch($id);
        if ($data !== null) {
            $this->set($id, $data, static::DEFAULT_TTL);
        }
    }

    public function getOrSet(int $id, callable $fetcher, ?int $ttl = null): array
    {
        $cached = $this->get($id);
        if ($cached !== null) {
            return $cached;
        }

        $data = $fetcher($id);
        if ($data !== null) {
            $this->set($id, $data, $ttl ?? static::DEFAULT_TTL);
        }
        return $data;
    }

    abstract protected function buildCacheKey(int $id): string;
    abstract protected function getCacheType(): string;
    abstract protected function fetch(int $id): ?array;
}
