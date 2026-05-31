<?php
declare(strict_types=1);

namespace App\Caching;

use App\Service\CacheService;
use App\Service\CacheKeyBuilder;
use App\Service\MetricsService;
use Psr\Log\LoggerInterface;

abstract class AbstractEntityCacheHandler
{
    protected const CACHE_PREFIX = 'entity';
    protected const DEFAULT_TTL = 3600;
    protected const STALE_TTL = 300;

    public function __construct(
        protected readonly CacheService $cache,
        protected readonly CacheKeyBuilder $keyBuilder,
        protected readonly MetricsService $metrics,
        protected readonly LoggerInterface $logger,
    ) {
    }

    public function get(int $id, bool $allowStale = false): ?array
    {
        $cacheKey = $this->buildEntityCacheKey($id);
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('cache.hit', ['type' => $this->getEntityType()]);
            return $cached;
        }

        $this->metrics->increment('cache.miss', ['type' => $this->getEntityType()]);
        return null;
    }

    public function set(int $id, array $data, ?int $ttl = null): void
    {
        $cacheKey = $this->buildEntityCacheKey($id);
        $ttl = $ttl ?? static::DEFAULT_TTL;
        $this->cache->set($cacheKey, $data, $ttl);
    }

    public function invalidate(int $id): void
    {
        $this->cache->delete($this->buildEntityCacheKey($id));
        $this->logger->debug('Invalidated cache', ['key' => $this->buildEntityCacheKey($id)]);
    }

    public function invalidateMultiple(array $ids): void
    {
        $keys = array_map(fn($id) => $this->buildEntityCacheKey($id), $ids);
        if (!empty($keys)) {
            $this->cache->deleteMultiple($keys);
        }
    }

    public function refresh(int $id): void
    {
        $cacheKey = $this->buildEntityCacheKey($id);
        $entity = $this->findEntity($id);

        if ($entity === null) {
            $this->cache->delete($cacheKey);
            return;
        }

        $data = $this->serialize($entity);
        $this->set($id, $data);
    }

    public function setWithStale(int $id, array $data): void
    {
        $cacheKey = $this->buildEntityCacheKey($id);
        $staleKey = $cacheKey . ':stale';

        $this->cache->set($staleKey, $data, static::DEFAULT_TTL + static::STALE_TTL);
        $this->cache->set($cacheKey, $data, static::DEFAULT_TTL);
    }

    public function getOrSet(int $id, callable $fetcher, ?int $ttl = null): array
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

    abstract protected function buildEntityCacheKey(int $id): string;
    abstract protected function getEntityType(): string;
    abstract protected function findEntity(int $id): ?object;
    abstract protected function serialize(object $entity): array;
}
