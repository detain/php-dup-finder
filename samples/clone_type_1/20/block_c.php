<?php

declare(strict_types=1);

namespace App\RateLimit\TokenBucket;

use App\Entity\RateLimitEntry;
use App\Repository\RateLimitRepository;
use Psr\Log\LoggerInterface;

final class TokenBucketRateLimiter
{
    public function __construct(
        private readonly RateLimitRepository $rateLimitRepository,
        private readonly LoggerInterface $logger,
    ) {}

    public function isAllowed(string $key, int $maxRequests, int $windowSeconds): bool
    {
        $now = time();
        $bucketKey = "{$key}:bucket";

        $entry = $this->rateLimitRepository->findByKey($bucketKey);

        if ($entry === null) {
            $entry = new RateLimitEntry();
            $entry->setKey($bucketKey);
            $entry->setCount($maxRequests - 1);
            $entry->setWindowStart($now);
            $entry->setWindowEnd($now + $windowSeconds);
            $this->rateLimitRepository->save($entry);

            $this->logger->debug('Token bucket created', [
                'key' => $key,
                'tokens' => $maxRequests - 1,
            ]);

            return true;
        }

        $tokens = $this->calculateAvailableTokens($entry, $now, $maxRequests, $windowSeconds);

        if ($tokens <= 0) {
            $this->logger->warning('Rate limit exceeded - no tokens', [
                'key' => $key,
            ]);

            return false;
        }

        $entry->setCount($tokens - 1);
        $this->rateLimitRepository->save($entry);

        $this->logger->debug('Rate limit check passed', [
            'key' => $key,
            'tokens' => $tokens - 1,
            'max' => $maxRequests,
        ]);

        return true;
    }

    public function getRemainingRequests(string $key, int $maxRequests, int $windowSeconds): int
    {
        $now = time();
        $bucketKey = "{$key}:bucket";

        $entry = $this->rateLimitRepository->findByKey($bucketKey);

        if ($entry === null) {
            return $maxRequests;
        }

        return $this->calculateAvailableTokens($entry, $now, $maxRequests, $windowSeconds);
    }

    public function getRetryAfter(string $key, int $windowSeconds): int
    {
        return $windowSeconds;
    }

    private function calculateAvailableTokens(RateLimitEntry $entry, int $now, int $maxRequests, int $windowSeconds): int
    {
        $timePassed = $now - $entry->getWindowStart();
        $tokensAdded = (int) ($timePassed / $windowSeconds * $maxRequests);

        return min($maxRequests, $entry->getCount() + $tokensAdded);
    }
}
