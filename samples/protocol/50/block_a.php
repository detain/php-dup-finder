<?php

declare(strict_types=1);

namespace App\Services\RateLimiter;

use App\Services\CacheService;

class TokenBucketRateLimiter
{
    private CacheService $cacheService;
    private int $bucketCapacity;
    private int $refillRate;
    private int $defaultLimit;
    private int $windowSeconds;

    public function __construct(
        CacheService $cacheService,
        int $bucketCapacity = 100,
        int $refillRate = 10,
        int $defaultLimit = 60,
        int $windowSeconds = 60
    ) {
        $this->cacheService = $cacheService;
        $this->bucketCapacity = $bucketCapacity;
        $this->refillRate = $refillRate;
        $this->defaultLimit = $defaultLimit;
        $this->windowSeconds = $windowSeconds;
    }

    public function attempt(string $key, ?int $limit = null, ?int $windowSeconds = null): bool
    {
        $limit = $limit ?? $this->defaultLimit;
        $windowSeconds = $windowSeconds ?? $this->windowSeconds;

        $bucketKey = "rate_limit:{$key}";

        $bucket = $this->cacheService->get($bucketKey);

        if (!$bucket) {
            $bucket = [
                'tokens' => $this->bucketCapacity - 1,
                'last_refill' => time(),
            ];
        } else {
            $bucket = json_decode($bucket, true);

            // Refill tokens based on time elapsed
            $now = time();
            $elapsed = $now - $bucket['last_refill'];
            $tokensToAdd = (int) floor($elapsed * $this->refillRate);

            if ($tokensToAdd > 0) {
                $bucket['tokens'] = min($this->bucketCapacity, $bucket['tokens'] + $tokensToAdd);
                $bucket['last_refill'] = $now;
            }
        }

        if ($bucket['tokens'] <= 0) {
            return false;
        }

        $bucket['tokens']--;
        $this->cacheService->set($bucketKey, json_encode($bucket), $windowSeconds);

        return true;
    }

    public function reserve(string $key, int $tokens = 1): bool
    {
        $bucketKey = "rate_limit:{$key}";

        $bucket = $this->cacheService->get($bucketKey);

        if (!$bucket) {
            $bucket = [
                'tokens' => $this->bucketCapacity - $tokens,
                'last_refill' => time(),
            ];
        } else {
            $bucket = json_decode($bucket, true);

            $now = time();
            $elapsed = $now - $bucket['last_refill'];
            $tokensToAdd = (int) floor($elapsed * $this->refillRate);

            if ($tokensToAdd > 0) {
                $bucket['tokens'] = min($this->bucketCapacity, $bucket['tokens'] + $tokensToAdd);
                $bucket['last_refill'] = $now;
            }

            if ($bucket['tokens'] < $tokens) {
                return false;
            }

            $bucket['tokens'] -= $tokens;
        }

        $this->cacheService->set($bucketKey, json_encode($bucket), $this->windowSeconds);

        return true;
    }

    public function getAvailableTokens(string $key): int
    {
        $bucketKey = "rate_limit:{$key}";
        $bucket = $this->cacheService->get($bucketKey);

        if (!$bucket) {
            return $this->bucketCapacity;
        }

        $bucket = json_decode($bucket, true);
        $now = time();
        $elapsed = $now - $bucket['last_refill'];
        $tokensToAdd = (int) floor($elapsed * $this->refillRate);

        return min($this->bucketCapacity, $bucket['tokens'] + $tokensToAdd);
    }

    public function reset(string $key): void
    {
        $bucketKey = "rate_limit:{$key}";
        $this->cacheService->delete($bucketKey);
    }

    public function getRetryAfter(string $key): int
    {
        $bucketKey = "rate_limit:{$key}";
        $bucket = $this->cacheService->get($bucketKey);

        if (!$bucket) {
            return 0;
        }

        $bucket = json_decode($bucket, true);

        if ($bucket['tokens'] > 0) {
            return 0;
        }

        // Calculate time until next token
        $timeSinceRefill = time() - $bucket['last_refill'];
        $tokensRefilled = $timeSinceRefill * $this->refillRate;

        if ($tokensRefilled >= 1) {
            return 0;
        }

        return (int) ceil((1 - $tokensRefilled) / $this->refillRate);
    }
}
