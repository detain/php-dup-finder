<?php
declare(strict_types=1);

namespace App\Caching;

use App\Service\CacheService;
use App\Service\CacheKeyBuilder;
use App\Service\MetricsService;
use Psr\Log\LoggerInterface;

abstract class AbstractCacheHandler
{
    protected const CACHE_PREFIX = 'cache';
    protected const DEFAULT_TTL = 3600;
    protected const STALE_TTL = 300;

    public function __construct(
        protected readonly CacheService $cache,
        protected readonly CacheKeyBuilder $keyBuilder,
        protected readonly MetricsService $metrics,
        protected readonly LoggerInterface $logger,
    ) {
    }

    public function get(int|string $id, bool $allowStale = false): ?array
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

    public function set(int|string $id, array $data, ?int $ttl = null): void
    {
        $cacheKey = $this->buildCacheKey($id);
        $ttl = $ttl ?? static::DEFAULT_TTL;
        $this->cache->set($cacheKey, $data, $ttl);
    }

    public function invalidate(int|string $id): void
    {
        $this->cache->delete($this->buildCacheKey($id));
        $this->logger->debug('Invalidated cache', ['key' => $this->buildCacheKey($id)]);
    }

    public function setWithStale(int|string $id, array $data): void
    {
        $cacheKey = $this->buildCacheKey($id);
        $staleKey = $cacheKey . ':stale';

        $this->cache->set($staleKey, $data, static::DEFAULT_TTL + static::STALE_TTL);
        $this->cache->set($cacheKey, $data, static::DEFAULT_TTL);
    }

    public function getOrSet(int|string $id, callable $fetcher, ?int $ttl = null): array
    {
        $cached = $this->get($id);
        if ($cached !== null) {
            return $cached;
        }

        $data = $fetcher($id);
        if ($data !== null) {
            $this->set($id, $data, $ttl);
        }
        return $data;
    }

    abstract protected function buildCacheKey(int|string $id): string;
    abstract protected function getCacheType(): string;
    abstract protected function serialize(mixed $entity): array;
}
