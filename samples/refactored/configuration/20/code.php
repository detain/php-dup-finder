<?php

declare(strict_types=1);

namespace App\Infrastructure\Configuration;

use App\Attributes\Configuration;

#[Configuration('resilience')]
final class ResilienceConfig
{
    public function __construct(
        public readonly int $maxConsecutiveFailures = 5,
        public readonly int $recoveryTimeout = 60,
        public readonly int $recoveryAttempts = 3,
        public readonly int $backoffBaseDelay = 100,
        public readonly int $backoffMaxDelay = 5000,
        public readonly float $backoffMultiplier = 2.0,
        public readonly int $backoffJitter = 20,
        public readonly int $timeoutOperation = 30,
        public readonly int $timeoutConnect = 10,
        public readonly int $timeoutRead = 20,
        public readonly int $bulkheadCapacity = 10,
        public readonly int $bulkheadWaitTimeout = 5,
    ) {}

    public function calculateBackoff(int $attempt): int
    {
        $delay = $this->backoffBaseDelay * pow($this->backoffMultiplier, $attempt - 1);
        $delay = min($delay, $this->backoffMaxDelay);

        $jitter = rand(-$this->backoffJitter, $this->backoffJitter);
        $delay = $delay * (1 + $jitter / 100);

        return (int) $delay;
    }
}

#[Configuration('retry')]
final class RetryConfig
{
    public const DEFAULT_MAX_ATTEMPTS = 3;
    public const DEFAULT_INITIAL_DELAY = 100;
    public const DEFAULT_MAX_DELAY = 5000;
    public const DEFAULT_MULTIPLIER = 2;

    public function __construct(
        public readonly int $maxAttempts = 3,
        public readonly int $initialDelay = 100,
        public readonly int $maxDelay = 3000,
        public readonly float $multiplier = 2.0,
        public readonly int $jitterPercent = 15,
    ) {}
}

trait HasResilience
{
    protected abstract function getResilienceConfig(): ResilienceConfig;
    protected abstract function getRetryConfig(): RetryConfig;

    protected function executeWithResilience(callable $operation, string $resource, ?callable $fallback = null): mixed
    {
        $config = $this->getResilienceConfig();
        $retryConfig = $this->getRetryConfig();

        $attempt = 0;

        while ($attempt < $retryConfig->maxAttempts) {
            try {
                $result = $operation();

                return $result;
            } catch (\Throwable $e) {
                $attempt++;

                if ($attempt >= $retryConfig->maxAttempts) {
                    if ($fallback !== null) {
                        return $fallback();
                    }
                    throw $e;
                }

                $delay = $retryConfig->calculateBackoff($attempt);
                usleep($delay * 1000);
            }
        }
    }
}
