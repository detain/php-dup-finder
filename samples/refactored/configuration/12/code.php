<?php

declare(strict_types=1);

namespace App\Infrastructure\RateLimiting;

use App\Attributes\RateLimit;
use App\Traits\HasRateLimiting;

#[RateLimit(requests: 100, window: 60, burst: 20, backoff: 30)]
final class RateLimitedService
{
    use HasRateLimiting;

    public function __construct(
        private readonly RateLimitConfiguration $config
    ) {}

    public function executeLimitedOperation(callable $operation): mixed
    {
        $this->acquireRateLimitSlot();

        try {
            return $operation();
        } finally {
            $this->releaseRateLimitSlot();
        }
    }

    private function acquireRateLimitSlot(): void
    {
        $config = $this->getRateLimitConfig();

        if ($this->shouldBackoff()) {
            $backoffTime = $config->calculateBackoff($this->getBurstUsage());
            $this->logger->info('Applying rate limit backoff', ['backoff' => $backoffTime]);
            sleep($backoffTime);
        }

        if ($this->hasExceededLimit()) {
            $waitTime = $this->getTimeUntilNextWindow();
            $this->logger->warning('Rate limit exceeded, waiting', ['wait' => $waitTime]);
            sleep($waitTime);
        }

        $this->recordRequest();
    }
}

interface RateLimitConfiguration
{
    public function getMaxRequests(): int;
    public function getWindowSeconds(): int;
    public function getBurstAllowance(): int;
    public function getBackoffDelay(): int;
    public function calculateBackoff(int $burstUsage): int;
}
