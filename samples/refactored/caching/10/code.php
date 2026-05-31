<?php
declare(strict_types=1);

namespace App\Caching;

use App\Service\CacheService;
use App\Service\CacheKeyBuilder;
use App\Service\MetricsService;
use Psr\Log\LoggerInterface;

abstract class AbstractPaymentCacheHandler
{
    protected const CACHE_PREFIX = 'payment';
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
        $cacheKey = $this->buildCacheKey($id);
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('cache.hit', ['type' => $this->getType()]);
            return $cached;
        }

        $this->metrics->increment('cache.miss', ['type' => $this->getType()]);
        return null;
    }

    public function set(int $id, array $data, ?int $ttl = null): void
    {
        $this->cache->set($this->buildCacheKey($id), $data, $ttl ?? static::DEFAULT_TTL);
    }

    public function invalidate(int $id): void
    {
        $this->cache->delete($this->buildCacheKey($id));
    }

    public function invalidateMultiple(array $ids): void
    {
        $keys = array_map(fn($id) => $this->buildCacheKey($id), $ids);
        if (!empty($keys)) {
            $this->cache->deleteMultiple($keys);
        }
    }

    public function refresh(int $id): void
    {
        $entity = $this->find($id);
        if ($entity === null) {
            $this->cache->delete($this->buildCacheKey($id));
            return;
        }
        $this->set($id, $this->serialize($entity));
    }

    public function handleCreate(int $id): void
    {
        $entity = $this->find($id);
        if ($entity !== null) {
            $this->invalidateOwner($this->getOwnerId($entity));
        }
        $this->metrics->increment('cache.invalidation', ['type' => $this->getType() . '_create']);
    }

    public function handleUpdate(int $id): void
    {
        $this->invalidate($id);
        $entity = $this->find($id);
        if ($entity !== null) {
            $this->invalidateSubCache($id);
        }
    }

    public function handleDelete(int $id): void
    {
        $entity = $this->find($id);
        if ($entity !== null) {
            $this->invalidate($id);
            $this->invalidateOwner($this->getOwnerId($entity));
        }
    }

    public function handleStatusChange(int $id): void
    {
        $this->invalidate($id);
        $entity = $this->find($id);
        if ($entity !== null) {
            $this->invalidateOwner($this->getOwnerId($entity));
        }
    }

    public function handleSpecialAction(int $id, string $action): void
    {
        $this->invalidate($id);
        $actionKey = $this->buildCacheKey($id) . ':' . $action;
        $this->cache->delete($actionKey);

        $entity = $this->find($id);
        if ($entity !== null) {
            $this->invalidateOwner($this->getOwnerId($entity));
        }
        $this->metrics->increment('cache.invalidation', ['type' => $this->getType() . '_' . $action]);
    }

    public function setWithStale(int $id, array $data): void
    {
        $cacheKey = $this->buildCacheKey($id);
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

    abstract protected function buildCacheKey(int $id): string;
    abstract protected function getType(): string;
    abstract protected function find(int $id): ?object;
    abstract protected function serialize(object $entity): array;
    abstract protected function getOwnerId(object $entity): int;
    abstract protected function invalidateOwner(int $ownerId): void;
    abstract protected function invalidateSubCache(int $id): void;
}
