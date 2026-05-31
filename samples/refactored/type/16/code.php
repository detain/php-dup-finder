<?php
declare(strict_types=1);

namespace Service\Caching;

interface EntityCacheManagerInterface
{
    public function get(string $entityId): ?array;
    public function set(string $entityId, array $data, ?int $ttl = null): void;
    public function invalidate(string $entityId): void;
    public function invalidateAll(string $entityId): void;
}

final class CacheKeyGenerator
{
    private string $prefix;
    private array $subKeyConfigs;

    public function __construct(string $prefix, array $subKeyConfigs = [])
    {
        $this->prefix = $prefix;
        $this->subKeyConfigs = $subKeyConfigs;
    }

    public function forEntity(string $entityId): string
    {
        return $this->prefix . $entityId;
    }

    public function forSubKey(string $entityId, string $subKey): string
    {
        return $this->prefix . $entityId . ':' . $subKey;
    }

    public function forList(string $listKey): string
    {
        return $this->prefix . 'list:' . $listKey;
    }

    public function forLock(string $entityId): string
    {
        return $this->prefix . 'lock:' . $entityId;
    }

    public function getTtl(string $subKey): int
    {
        return $this->subKeyConfigs[$subKey]['ttl'] ?? 3600;
    }
}

abstract class BaseCacheManager implements EntityCacheManagerInterface
{
    protected LoggerInterface $logger;
    protected CacheKeyGenerator $keyGenerator;

    protected function __construct(string $cachePrefix, array $subKeyConfigs = [])
    {
        $this->keyGenerator = new CacheKeyGenerator($cachePrefix, $subKeyConfigs);
    }

    public function get(string $entityId): ?array
    {
        $cacheKey = $this->keyGenerator->forEntity($entityId);
        $cached = apcu_fetch($cacheKey, $success);

        if ($success && $cached !== false) {
            $this->logger->debug('Cache hit', ['key' => $cacheKey]);
            return unserialize($cached);
        }

        $this->logger->debug('Cache miss', ['key' => $cacheKey]);
        return null;
    }

    public function set(string $entityId, array $data, ?int $ttl = null): void
    {
        $cacheKey = $this->keyGenerator->forEntity($entityId);
        apcu_store($cacheKey, serialize($data), $ttl ?? 3600);
        $this->logger->debug('Cached', ['key' => $cacheKey, 'ttl' => $ttl]);
    }

    public function invalidate(string $entityId): void
    {
        apcu_delete($this->keyGenerator->forEntity($entityId));
    }

    public function getSubKey(string $entityId, string $subKey): ?array
    {
        $cacheKey = $this->keyGenerator->forSubKey($entityId, $subKey);
        $cached = apcu_fetch($cacheKey, $success);
        return $success ? unserialize($cached) : null;
    }

    public function setSubKey(string $entityId, string $subKey, array $data): void
    {
        $cacheKey = $this->keyGenerator->forSubKey($entityId, $subKey);
        $ttl = $this->keyGenerator->getTtl($subKey);
        apcu_store($cacheKey, serialize($data), $ttl);
    }

    public function invalidateSubKey(string $entityId, string $subKey): void
    {
        apcu_delete($this->keyGenerator->forSubKey($entityId, $subKey));
    }

    public function acquireLock(string $entityId): bool
    {
        $lockKey = $this->keyGenerator->forLock($entityId);
        return apcu_add($lockKey, time(), 30);
    }

    public function releaseLock(string $entityId): void
    {
        apcu_delete($this->keyGenerator->forLock($entityId));
    }

    abstract protected function invalidateAllSubKeys(string $entityId): void;

    public function invalidateAll(string $entityId): void
    {
        $this->invalidate($entityId);
        $this->invalidateAllSubKeys($entityId);
        $this->logger->info('All data invalidated', ['entity_id' => $entityId]);
    }
}

final class UserCacheManager extends BaseCacheManager
{
    public function __construct(LoggerInterface $logger)
    {
        parent::__construct('user:', [
            'profile' => ['ttl' => 1800],
            'sessions' => ['ttl' => 900],
            'preferences' => ['ttl' => 7200],
        ]);
        $this->logger = $logger;
    }

    protected function invalidateAllSubKeys(string $entityId): void
    {
        $this->invalidateSubKey($entityId, 'profile');
        $this->invalidateSubKey($entityId, 'sessions');
        $this->invalidateSubKey($entityId, 'preferences');
    }
}
