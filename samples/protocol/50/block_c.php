<?php

declare(strict_types=1);

namespace App\Services\RateLimiter;

use App\Services\CacheService;

interface RateLimiterInterface
{
    public function attempt(string $key, ?int $limit = null, ?int $windowSeconds = null): bool;
    public function getRemainingRequests(string $key, ?int $limit = null): int;
    public function getRetryAfter(string $key): int;
    public function reset(string $key): void;
}

abstract class AbstractRateLimiter implements RateLimiterInterface
{
    protected CacheService $cacheService;
    protected int $defaultLimit;
    protected int $windowSeconds;

    public function __construct(
        CacheService $cacheService,
        int $defaultLimit = 60,
        int $windowSeconds = 60
    ) {
        $this->cacheService = $cacheService;
        $this->defaultLimit = $defaultLimit;
        $this->windowSeconds = $windowSeconds;
    }

    public function getRemainingRequests(string $key, ?int $limit = null): int
    {
        $limit = $limit ?? $this->defaultLimit;
        $used = $this->getUsedCount($key);

        return max(0, $limit - $used);
    }

    abstract protected function getUsedCount(string $key): int;
}

class TokenBucketRateLimiter extends AbstractRateLimiter
{
    private int $bucketCapacity;
    private int $refillRate;

    public function __construct(
        CacheService $cacheService,
        int $bucketCapacity = 100,
        int $refillRate = 10,
        int $defaultLimit = 60,
        int $windowSeconds = 60
    ) {
        parent::__construct($cacheService, $defaultLimit, $windowSeconds);
        $this->bucketCapacity = $bucketCapacity;
        $this->refillRate = $refillRate;
    }

    public function attempt(string $key, ?int $limit = null, ?int $windowSeconds = null): bool
    {
        $limit = $limit ?? $this->defaultLimit;
        $bucketKey = "rate_limit:{$key}";

        $bucket = $this->getBucket($key);

        if ($bucket['tokens'] <= 0) {
            return false;
        }

        $bucket['tokens']--;
        $this->saveBucket($bucketKey, $bucket);

        return true;
    }

    protected function getUsedCount(string $key): int
    {
        $bucket = $this->getBucket($key);
        return $this->bucketCapacity - $bucket['tokens'];
    }

    private function getBucket(string $key): array
    {
        $bucketKey = "rate_limit:{$key}";
        $bucket = $this->cacheService->get($bucketKey);

        if (!$bucket) {
            return [
                'tokens' => $this->bucketCapacity,
                'last_refill' => time(),
            ];
        }

        $bucket = json_decode($bucket, true);

        // Refill tokens
        $elapsed = time() - $bucket['last_refill'];
        $tokensToAdd = (int) floor($elapsed * $this->refillRate);

        if ($tokensToAdd > 0) {
            $bucket['tokens'] = min($this->bucketCapacity, $bucket['tokens'] + $tokensToAdd);
            $bucket['last_refill'] = time();
        }

        return $bucket;
    }

    private function saveBucket(string $key, array $bucket): void
    {
        $this->cacheService->set($key, json_encode($bucket), $this->windowSeconds);
    }

    public function getRetryAfter(string $key): int
    {
        $bucket = $this->getBucket($key);

        if ($bucket['tokens'] > 0) {
            return 0;
        }

        return (int) ceil(1 / $this->refillRate);
    }
}
