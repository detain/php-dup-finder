<?php
declare(strict_types=1);

namespace Billing\Core\Caching;

use Psr\Log\LoggerInterface;

final class CacheFallbackHandler
{
    public function __construct(
        private readonly ?LoggerInterface $logger = null
    ) {}

    public function getWithFallback(
        string $cacheKey,
        callable $fallback,
        int $cacheTtl = 3600,
        string $staleCacheKey = null
    ): mixed {
        // Try cache first
        $cached = $this->getFromCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        // Cache miss - call fallback
        try {
            $value = $fallback();

            // Populate cache
            $this->setCache($cacheKey, $value, $cacheTtl);

            // Update stale cache for emergencies
            if ($staleCacheKey !== null) {
                $this->setCache($staleCacheKey, $value, $cacheTtl * 24);
            }

            return $value;

        } catch (\Exception $e) {
            $this->logger?->warning('Primary source failed, trying stale cache', [
                'error' => $e->getMessage()
            ]);

            // Try stale cache
            if ($staleCacheKey !== null) {
                $stale = $this->getFromCache($staleCacheKey);
                if ($stale !== null) {
                    return $stale;
                }
            }

            throw $e;
        }
    }

    private function getFromCache(string $key): mixed
    {
        return null; // Implementation depends on cache backend
    }

    private function setCache(string $key, mixed $value, int $ttl): void
    {
        // Implementation depends on cache backend
    }
}
