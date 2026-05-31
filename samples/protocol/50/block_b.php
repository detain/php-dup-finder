<?php

declare(strict_types=1);

namespace App\Services\RateLimiter;

use App\Services\CacheService;

class SlidingWindowRateLimiter
{
    private CacheService $cacheService;
    private int $defaultLimit;
    private int $windowSeconds;

    public function __construct(
        CacheService $cacheService,
        int $defaultLimit = 60,
        int $windowSeconds = 60
    ) {
        $this->cacheService = $cacheService;
        $this->defaultLimit = $defaultLimit;
        $this->windowSeconds = $windowSeconds;
    }

    public function attempt(string $key, ?int $limit = null, ?int $windowSeconds = null): bool
    {
        $limit = $limit ?? $this->defaultLimit;
        $windowSeconds = $windowSeconds ?? $this->windowSeconds;

        $windowKey = "rate_limit:{$key}";
        $now = microtime(true);
        $windowStart = $now - $windowSeconds;

        // Get current window
        $window = $this->cacheService->get($windowKey);

        if (!$window) {
            $window = [];
        } else {
            $window = json_decode($window, true);
        }

        // Remove expired entries
        $window = array_values(array_filter($window, fn($ts) => $ts > $windowStart));

        // Check if under limit
        if (count($window) >= $limit) {
            return false;
        }

        // Add current request
        $window[] = $now;

        // Store updated window
        $this->cacheService->set($windowKey, json_encode($window), $windowSeconds + 1);

        return true;
    }

    public function getRequestCount(string $key, ?int $windowSeconds = null): int
    {
        $windowSeconds = $windowSeconds ?? $this->windowSeconds;
        $windowKey = "rate_limit:{$key}";
        $now = microtime(true);
        $windowStart = $now - $windowSeconds;

        $window = $this->cacheService->get($windowKey);

        if (!$window) {
            return 0;
        }

        $window = json_decode($window, true);

        return count(array_filter($window, fn($ts) => $ts > $windowStart));
    }

    public function getRemainingRequests(string $key, ?int $limit = null): int
    {
        $limit = $limit ?? $this->defaultLimit;
        $current = $this->getRequestCount($key);

        return max(0, $limit - $current);
    }

    public function getRetryAfter(string $key): int
    {
        $windowKey = "rate_limit:{$key}";
        $window = $this->cacheService->get($windowKey);

        if (!$window) {
            return 0;
        }

        $window = json_decode($window, true);
        $limit = $this->defaultLimit;

        if (count($window) < $limit) {
            return 0;
        }

        // Sort by timestamp
        sort($window);

        // Get the oldest request in the window
        $oldestInWindow = $window[$limit - 1];
        $now = microtime(true);

        // Calculate when the oldest request will expire
        $expiresAt = $oldestInWindow + $this->windowSeconds;

        if ($expiresAt <= $now) {
            return 0;
        }

        return (int) ceil($expiresAt - $now);
    }

    public function reset(string $key): void
    {
        $windowKey = "rate_limit:{$key}";
        $this->cacheService->delete($windowKey);
    }

    public function getWindowData(string $key): array
    {
        $windowKey = "rate_limit:{$key}";
        $window = $this->cacheService->get($windowKey);

        if (!$window) {
            return [];
        }

        return json_decode($window, true);
    }
}
