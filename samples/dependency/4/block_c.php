<?php

declare(strict_types=1);

namespace App\Application\RateLimiting;

use App\Infrastructure\Cache\CacheService;

/**
 * Rate limiting service.
 * The CacheService is manually injected here, duplicated from
 * SessionService, PreferenceService, and other services.
 */
class RateLimitService
{
    private const RATE_LIMIT_PREFIX = 'ratelimit:';
    private const DEFAULT_WINDOW_SECONDS = 60;

    private CacheService $cache;

    public function __construct(CacheService $cache)
    {
        $this->cache = $cache;
    }

    public function checkRateLimit(
        string $identifier,
        int $maxRequests,
        int $windowSeconds = self::DEFAULT_WINDOW_SECONDS
    ): RateLimitResult {

        $key = $this->getCacheKey($identifier, $windowSeconds);

        $current = (int) $this->cache->get($key);

        if ($current >= $maxRequests) {
            $ttl = $this->cache->ttl($key);
            $retryAfter = $ttl > 0 ? $ttl : $windowSeconds;

            return new RateLimitResult(
                allowed: false,
                limit: $maxRequests,
                remaining: 0,
                retryAfterSeconds: $retryAfter,
            );
        }

        $this->cache->increment($key);

        if ($current === 0) {
            $this->cache->expire($key, $windowSeconds);
        }

        return new RateLimitResult(
            allowed: true,
            limit: $maxRequests,
            remaining: max(0, $maxRequests - $current - 1),
            retryAfterSeconds: null,
        );
    }

    public function resetRateLimit(string $identifier, int $windowSeconds = self::DEFAULT_WINDOW_SECONDS): void
    {
        $key = $this->getCacheKey($identifier, $windowSeconds);
        $this->cache->delete($key);
    }

    public function getCurrentCount(string $identifier, int $windowSeconds = self::DEFAULT_WINDOW_SECONDS): int
    {
        $key = $this->getCacheKey($identifier, $windowSeconds);
        return (int) $this->cache->get($key) ?: 0;
    }

    public function checkApiRateLimit(string $apiKey, string $endpoint): RateLimitResult
    {
        $tier = $this->getApiTier($apiKey);

        $limits = match ($tier) {
            'free' => ['requests' => 60, 'window' => 60],
            'basic' => ['requests' => 300, 'window' => 60],
            'professional' => ['requests' => 1000, 'window' => 60],
            'enterprise' => ['requests' => 5000, 'window' => 60],
            default => ['requests' => 60, 'window' => 60],
        };

        $identifier = "api:{$apiKey}:{$endpoint}";

        return $this->checkRateLimit($identifier, $limits['requests'], $limits['window']);
    }

    public function checkIpRateLimit(string $ipAddress, string $endpoint): RateLimitResult
    {
        $identifier = "ip:{$ipAddress}:{$endpoint}";

        return $this->checkRateLimit($identifier, 100, 60);
    }

    public function checkUserRateLimit(string $userId, string $action): RateLimitResult
    {
        $identifier = "user:{$userId}:{$action}";

        $limits = match ($action) {
            'login' => ['requests' => 5, 'window' => 300],
            'password_reset' => ['requests' => 3, 'window' => 3600],
            'register' => ['requests' => 5, 'window' => 3600],
            'api_request' => ['requests' => 1000, 'window' => 60],
            default => ['requests' => 60, 'window' => 60],
        };

        return $this->checkRateLimit($identifier, $limits['requests'], $limits['window']);
    }

    public function recordQuotaUsage(string $identifier, int $amount = 1): void
    {
        $key = "quota:{$identifier}";
        $this->cache->increment($key, $amount);
    }

    public function getQuotaRemaining(string $identifier, int $dailyLimit): int
    {
        $key = "quota:{$identifier}";
        $used = (int) $this->cache->get($key) ?: 0;

        return max(0, $dailyLimit - $used);
    }

    public function resetDailyQuotas(string $identifier): void
    {
        $key = "quota:{$identifier}";
        $this->cache->delete($key);
    }

    private function getCacheKey(string $identifier, int $windowSeconds): string
    {
        $windowKey = (int) (time() / $windowSeconds);
        return self::RATE_LIMIT_PREFIX . $identifier . ':' . $windowKey;
    }

    private function getApiTier(string $apiKey): string
    {
        $cached = $this->cache->get("api_tier:{$apiKey}");

        if ($cached !== null) {
            return $cached;
        }

        return 'free';
    }
}
