<?php

declare(strict_types=1);

namespace App\RateLimit\SlidingWindow;

use App\Entity\RateLimitEntry;
use App\Repository\RateLimitRepository;
use Psr\Log\LoggerInterface;

final class SlidingWindowRateLimiter
{
    public function __construct(
        private readonly RateLimitRepository $rateLimitRepository,
        private readonly LoggerInterface $logger,
    ) {}

    public function isAllowed(string $key, int $maxRequests, int $windowSeconds): bool
    {
        $now = time();
        $windowStart = $now - $windowSeconds;
        $windowKey = "{$key}:sliding";

        $entry = $this->rateLimitRepository->findByKey($windowKey);

        if ($entry === null) {
            $entry = new RateLimitEntry();
            $entry->setKey($windowKey);
            $entry->setCount(1);
            $entry->setWindowStart($now);
            $entry->setWindowEnd($now + $windowSeconds);
            $this->rateLimitRepository->save($entry);

            $this->logger->debug('Rate limit window created', [
                'key' => $key,
                'count' => 1,
            ]);

            return true;
        }

        $count = $this->getCountInWindow($entry, $windowStart, $now);

        if ($count >= $maxRequests) {
            $this->logger->warning('Rate limit exceeded', [
                'key' => $key,
                'count' => $count,
                'max' => $maxRequests,
            ]);

            return false;
        }

        $entry->setCount($count + 1);
        $this->rateLimitRepository->save($entry);

        $this->logger->debug('Rate limit check passed', [
            'key' => $key,
            'count' => $count + 1,
            'max' => $maxRequests,
        ]);

        return true;
    }

    public function getRemainingRequests(string $key, int $maxRequests, int $windowSeconds): int
    {
        $now = time();
        $windowStart = $now - $windowSeconds;
        $windowKey = "{$key}:sliding";

        $entry = $this->rateLimitRepository->findByKey($windowKey);

        if ($entry === null) {
            return $maxRequests;
        }

        $count = $this->getCountInWindow($entry, $windowStart, $now);

        return max(0, $maxRequests - $count);
    }

    public function getRetryAfter(string $key, int $windowSeconds): int
    {
        return $windowSeconds;
    }

    private function getCountInWindow(RateLimitEntry $entry, int $windowStart, int $now): int
    {
        return $entry->getCount();
    }
}
