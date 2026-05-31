<?php
declare(strict_types=1);

namespace App\Caching;

use App\Service\CacheService;
use App\Service\CacheKeyBuilder;
use App\Service\MetricsService;
use Psr\Log\LoggerInterface;

abstract class AbstractPricingCacheHandler
{
    protected const CACHE_PREFIX = 'pricing';
    protected const DEFAULT_TTL = 3600;
    protected const STALE_TTL = 300;

    public function __construct(
        protected readonly CacheService $cache,
        protected readonly CacheKeyBuilder $keyBuilder,
        protected readonly MetricsService $metrics,
        protected readonly LoggerInterface $logger,
    ) {
    }

    public function get(int $id): ?array
    {
        $cacheKey = $this->buildPrimaryCacheKey($id);
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
        $this->cache->set($this->buildPrimaryCacheKey($id), $data, $ttl ?? static::DEFAULT_TTL);
    }

    public function invalidate(int $id): void
    {
        $this->cache->delete($this->buildPrimaryCacheKey($id));
    }

    public function invalidateMultiple(array $ids): void
    {
        $keys = array_map(fn($id) => $this->buildPrimaryCacheKey($id), $ids);
        if (!empty($keys)) {
            $this->cache->deleteMultiple($keys);
        }
    }

    public function refresh(int $id): void
    {
        $entity = $this->findEntity($id);
        if ($entity === null) {
            $this->cache->delete($this->buildPrimaryCacheKey($id));
            return;
        }
        $this->set($id, $this->serializeEntity($entity));
    }

    public function handleCreate(int $id): void
    {
        $entity = $this->findEntity($id);
        if ($entity !== null) {
            $this->invalidateGroup($this->getGroupId($entity));
        }
        $this->metrics->increment('cache.invalidation', ['type' => $this->getEntityType() . '_create']);
    }

    public function handleUpdate(int $id): void
    {
        $this->invalidate($id);
        $this->invalidateSubCache($id);
    }

    public function handleDelete(int $id): void
    {
        $entity = $this->findEntity($id);
        if ($entity !== null) {
            $this->invalidate($id);
            $this->invalidateGroup($this->getGroupId($entity));
        }
    }

    public function handleActivate(int $id): void
    {
        $this->invalidate($id);
        $entity = $this->findEntity($id);
        if ($entity !== null) {
            $this->invalidateGroup($this->getGroupId($entity));
        }
    }

    public function handleDeactivate(int $id): void
    {
        $this->invalidate($id);
        $entity = $this->findEntity($id);
        if ($entity !== null) {
            $this->invalidateGroup($this->getGroupId($entity));
        }
    }

    public function handleGroupUpdate(int $groupId): void
    {
        $this->invalidateGroupSummary($groupId);
        $this->invalidateGroup($groupId);
    }

    public function setWithStale(int $id, array $data): void
    {
        $cacheKey = $this->buildPrimaryCacheKey($id);
        $this->cache->set($cacheKey . ':stale', $data, static::DEFAULT_TTL + static::STALE_TTL);
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
    abstract protected function getEntityType(): string;
    abstract protected function findEntity(int $id): ?object;
    abstract protected function serializeEntity(object $entity): array;
    abstract protected function getGroupId(object $entity): int;
    abstract protected function invalidateGroup(int $groupId): void;
    abstract protected function invalidateGroupSummary(int $groupId): void;
    abstract protected function invalidateSubCache(int $id): void;
}
