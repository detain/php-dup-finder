<?php

declare(strict_types=1);

namespace App\RateLimit;

use App\Entity\RateLimitEntry;
use App\Repository\RateLimitRepository;
use Psr\Log\LoggerInterface;

interface RateLimitStrategyInterface
{
    public function isAllowed(string $key, int $maxRequests, int $windowSeconds): bool;
    public function getRemainingRequests(string $key, int $maxRequests, int $windowSeconds): int;
    public function getRetryAfter(string $key, int $windowSeconds): int;
}

abstract class AbstractRateLimiter implements RateLimitStrategyInterface
{
    public function __construct(
        protected readonly RateLimitRepository $rateLimitRepository,
        protected readonly LoggerInterface $logger,
    ) {}

    protected function getOrCreateEntry(string $key): RateLimitEntry
    {
        $entry = $this->rateLimitRepository->findByKey($key);

        if ($entry === null) {
            $entry = new RateLimitEntry();
            $entry->setKey($key);
            $this->rateLimitRepository->save($entry);
        }

        return $entry;
    }

    protected function logRateLimitDecision(string $key, bool $allowed, int $count, int $max): void
    {
        if ($allowed) {
            $this->logger->debug('Rate limit check passed', [
                'key' => $key,
                'count' => $count,
                'max' => $max,
            ]);
        } else {
            $this->logger->warning('Rate limit exceeded', [
                'key' => $key,
                'count' => $count,
                'max' => $max,
            ]);
        }
    }
}

final class RateLimitOrchestrator
{
    /** @var RateLimitStrategyInterface[] */
    private array $strategies = [];

    public function registerStrategy(RateLimitStrategyInterface $strategy): void
    {
        $this->strategies[] = $strategy;
    }

    public function isAllowed(string $key, int $maxRequests, int $windowSeconds): bool
    {
        foreach ($this->strategies as $strategy) {
            if (!$strategy->isAllowed($key, $maxRequests, $windowSeconds)) {
                return false;
            }
        }

        return true;
    }
}
