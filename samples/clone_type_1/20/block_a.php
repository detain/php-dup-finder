<?php

declare(strict_types=1);

namespace App\RateLimit\FixedWindow;

use App\Entity\RateLimitEntry;
use App\Repository\RateLimitRepository;
use Psr\Log\LoggerInterface;

final class FixedWindowRateLimiter
{
    public function __construct(
        private readonly RateLimitRepository $rateLimitRepository,
        private readonly LoggerInterface $logger,
    ) {}

    public function isAllowed(string $key, int $maxRequests, int $windowSeconds): bool
    {
        $now = time();
        $windowStart = $now - ($now % $windowSeconds);
        $windowKey = "{$key}:{$windowStart}";

        $entry = $this->rateLimitRepository->findByKey($windowKey);

        if ($entry === null) {
            $entry = new RateLimitEntry();
            $entry->setKey($windowKey);
            $entry->setCount(1);
            $entry->setWindowStart($windowStart);
            $this->rateLimitRepository->save($entry);

            $this->logger->debug('Rate limit window created', [
                'key' => $key,
                'count' => 1,
            ]);

            return true;
        }

        if ($entry->getCount() >= $maxRequests) {
            $this->logger->warning('Rate limit exceeded', [
                'key' => $key,
                'count' => $entry->getCount(),
                'max' => $maxRequests,
            ]);

            return false;
        }

        $entry->setCount($entry->getCount() + 1);
        $this->rateLimitRepository->save($entry);

        $this->logger->debug('Rate limit check passed', [
            'key' => $key,
            'count' => $entry->getCount(),
            'max' => $maxRequests,
        ]);

        return true;
    }

    public function getRemainingRequests(string $key, int $maxRequests, int $windowSeconds): int
    {
        $now = time();
        $windowStart = $now - ($now % $windowSeconds);
        $windowKey = "{$key}:{$windowStart}";

        $entry = $this->rateLimitRepository->findByKey($windowKey);

        if ($entry === null) {
            return $maxRequests;
        }

        return max(0, $maxRequests - $entry->getCount());
    }

    public function getRetryAfter(string $key, int $windowSeconds): int
    {
        $now = time();
        $windowStart = $now - ($now % $windowSeconds);
        $windowEnd = $windowStart + $windowSeconds;

        return max(0, $windowEnd - $now);
    }
}
