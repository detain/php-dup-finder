<?php
declare(strict_types=1);

namespace App\Caching;

use App\Service\CacheService;
use App\Service\CacheKeyBuilder;
use App\Service\MetricsService;
use Psr\Log\LoggerInterface;

abstract class AbstractContentCacheHandler
{
    protected const CACHE_PREFIX = 'content';
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
        $cacheKey = $this->buildPrimaryCacheKey($id);
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('cache.hit', ['type' => $this->getContentType()]);
            return $cached;
        }

        $this->metrics->increment('cache.miss', ['type' => $this->getContentType()]);
        return null;
    }

    public function set(int $id, array $data, ?int $ttl = null): void
    {
        $cacheKey = $this->buildPrimaryCacheKey($id);
        $ttl = $ttl ?? static::DEFAULT_TTL;
        $this->cache->set($cacheKey, $data, $ttl);
    }

    public function invalidate(int $id): void
    {
        $this->cache->delete($this->buildPrimaryCacheKey($id));
    }

    public function invalidateByIds(array $ids): void
    {
        $keys = array_map(fn($id) => $this->buildPrimaryCacheKey($id), $ids);
        if (!empty($keys)) {
            $this->cache->deleteMultiple($keys);
        }
    }

    public function refresh(int $id): void
    {
        $cacheKey = $this->buildPrimaryCacheKey($id);
        $entity = $this->findEntity($id);

        if ($entity === null) {
            $this->cache->delete($cacheKey);
            return;
        }

        $this->set($id, $this->serialize($entity));
    }

    public function handleParentUpdate(int $parentId, callable $getChildIds): void
    {
        $childIds = $getChildIds($parentId);
        $this->invalidateByIds($childIds);
        $this->invalidateParentSummary($parentId);

        $this->logger->info('Handled parent update', [
            'parent_id' => $parentId,
            'child_count' => count($childIds),
        ]);
    }

    public function handleEntityUpdate(int $id): void
    {
        $this->invalidate($id);
        $this->invalidateRelatedCache($id);

        $this->logger->info('Handled entity update', [
            'id' => $id,
        ]);
    }

    public function setWithStale(int $id, array $data): void
    {
        $cacheKey = $this->buildPrimaryCacheKey($id);
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

    abstract protected function buildPrimaryCacheKey(int $id): string;
    abstract protected function getContentType(): string;
    abstract protected function findEntity(int $id): ?object;
    abstract protected function serialize(object $entity): array;
    abstract protected function invalidateRelatedCache(int $id): void;
    abstract protected function invalidateParentSummary(int $parentId): void;
}
