<?php

declare(strict_types=1);

namespace App\Application;

use App\Infrastructure\Cache\CacheService;

/**
 * Base service class providing cache service.
 * Centralizes CacheService injection to avoid duplication.
 */
abstract class BaseCachedService
{
    protected CacheService $cache;

    public function __construct(CacheService $cache)
    {
        $this->cache = $cache;
    }

    protected function getCached(string $key, callable $fetcher, int $ttl)
    {
        $cached = $this->cache->get($key);

        if ($cached !== null) {
            return $cached;
        }

        $value = $fetcher();
        $this->cache->set($key, $value, $ttl);

        return $value;
    }
}

/**
 * Session service extending base to inherit cache.
 */
class SessionService extends BaseCachedService
{
    public function __construct(
        CacheService $cache,
        SessionRepositoryInterface $sessionRepository
    ) {
        parent::__construct($cache);
    }
}
