<?php

declare(strict_types=1);

namespace App\Services\Resilience;

use Psr\Log\LoggerInterface;
use App\Exceptions\ResilienceExceededException;

final class ResilienceProxy
{
    private const MAX_CONSECUTIVE_FAILURES = 5;
    private const RECOVERY_ATTEMPTS = 3;
    private const RECOVERY_TIMEOUT = 60;
    private const BACKOFF_BASE_DELAY = 100;
    private const BACKOFF_MAX_DELAY = 5000;
    private const BACKOFF_MULTIPLIER = 2.0;
    private const BACKOFF_JITTER = 20;
    private const TIMEOUT_OPERATION = 30;
    private const TIMEOUT_CONNECT = 10;
    private const TIMEOUT_READ = 20;
    private const BULKHEAD_CAPACITY = 10;
    private const BULKHEAD_WAIT_TIMEOUT = 5;
    private const RATE_LIMIT_MAX_CALLS = 100;
    private const RATE_LIMIT_WINDOW_SECONDS = 60;
    private const FALLBACK_CACHE_ENABLED = true;
    private const FALLBACK_TTL = 300;
    private const STALE_CACHE_ENABLED = true;
    private const STALE_CACHE_DURATION = 60;

    private LoggerInterface $logger;
    private array $failureCounters = [];
    private array $lastFailureTimes = [];
    private array $rateLimitCounters = [];
    private array $bulkheadSemaphores = [];

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function execute(callable $operation, string $resource, ?callable $fallback = null): mixed
    {
        $this->checkRateLimit($resource);

        $this->acquireBulkhead($resource);

        try {
            return $this->doExecute($operation, $resource, $fallback);
        } finally {
            $this->releaseBulkhead($resource);
        }
    }

    private function doExecute(callable $operation, string $resource, ?callable $fallback): mixed
    {
        if (!$this->canExecute($resource)) {
            if ($fallback !== null) {
                return $this->executeFallback($fallback, $resource);
            }

            throw new ResilienceExceededException(
                sprintf('Resource %s is not available', $resource)
            );
        }

        $attempt = 0;

        while (true) {
            try {
                $result = $this->executeOperation($operation, $resource, $attempt);

                $this->recordSuccess($resource);

                return $result;
            } catch (\Throwable $e) {
                $this->recordFailure($resource);

                if (!$this->shouldRetry($e, $resource)) {
                    if ($fallback !== null && $this->isFallbackApplicable($e)) {
                        return $this->executeFallback($fallback, $resource);
                    }
                    throw $e;
                }

                if ($this->getFailureCount($resource) >= self::MAX_CONSECUTIVE_FAILURES) {
                    if ($fallback !== null) {
                        return $this->executeFallback($fallback, $resource);
                    }
                    throw $e;
                }

                $attempt++;
                $delay = $this->calculateBackoff($attempt);

                $this->logger->warning('Retrying after failure', [
                    'resource' => $resource,
                    'attempt' => $attempt,
                    'delay_ms' => $delay,
                    'failure_count' => $this->getFailureCount($resource),
                    'max_failures' => self::MAX_CONSECUTIVE_FAILURES,
                ]);

                usleep($delay * 1000);
            }
        }
    }

    private function executeOperation(callable $operation, string $resource, int $attempt): mixed
    {
        $timeout = $this->getTimeout($attempt);

        $startTime = microtime(true);

        $result = $operation();

        $duration = (microtime(true) - $startTime) * 1000;

        $this->logger->debug('Operation executed', [
            'resource' => $resource,
            'duration_ms' => round($duration, 2),
            'timeout' => $timeout,
            'attempt' => $attempt + 1,
            'backoff_base' => self::BACKOFF_BASE_DELAY,
            'backoff_max' => self::BACKOFF_MAX_DELAY,
        ]);

        return $result;
    }

    private function getTimeout(int $attempt): int
    {
        $baseTimeout = self::TIMEOUT_OPERATION;
        return min($baseTimeout + ($attempt * 5), 60);
    }

    private function calculateBackoff(int $attempt): int
    {
        $delay = self::BACKOFF_BASE_DELAY * pow(self::BACKOFF_MULTIPLIER, $attempt - 1);

        $delay = min($delay, self::BACKOFF_MAX_DELAY);

        $jitter = rand(-self::BACKOFF_JITTER, self::BACKOFF_JITTER);
        $delay = $delay * (1 + $jitter / 100);

        return (int) $delay;
    }

    private function canExecute(string $resource): bool
    {
        if ($this->getFailureCount($resource) >= self::MAX_CONSECUTIVE_FAILURES) {
            $timeSinceLastFailure = time() - ($this->lastFailureTimes[$resource] ?? 0);

            if ($timeSinceLastFailure < self::RECOVERY_TIMEOUT) {
                return false;
            }

            $this->resetFailureCount($resource);
        }

        return true;
    }

    private function shouldRetry(\Throwable $e, string $resource): bool
    {
        if ($this->getFailureCount($resource) >= self::MAX_CONSECUTIVE_FAILURES) {
            return false;
        }

        if (str_contains(strtolower($e->getMessage()), 'timeout')) {
            return true;
        }

        if (str_contains(strtolower($e->getMessage()), 'connection')) {
            return true;
        }

        return false;
    }

    private function isFallbackApplicable(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return !str_contains($message, 'not found') &&
               !str_contains($message, 'unauthorized') &&
               !str_contains($message, 'forbidden');
    }

    private function executeFallback(callable $fallback, string $resource): mixed
    {
        $this->logger->info('Executing fallback', [
            'resource' => $resource,
            'fallback_cache_enabled' => self::FALLBACK_CACHE_ENABLED,
            'fallback_ttl' => self::FALLBACK_TTL,
            'stale_cache_enabled' => self::STALE_CACHE_ENABLED,
        ]);

        return $fallback();
    }

    private function recordSuccess(string $resource): void
    {
        $this->failureCounters[$resource] = 0;
        $this->lastFailureTimes[$resource] = null;
    }

    private function recordFailure(string $resource): void
    {
        if (!isset($this->failureCounters[$resource])) {
            $this->failureCounters[$resource] = 0;
        }

        $this->failureCounters[$resource]++;
        $this->lastFailureTimes[$resource] = time();
    }

    private function getFailureCount(string $resource): int
    {
        return $this->failureCounters[$resource] ?? 0;
    }

    private function resetFailureCount(string $resource): void
    {
        $this->failureCounters[$resource] = 0;
    }

    private function checkRateLimit(string $resource): void
    {
        $now = time();

        if (!isset($this->rateLimitCounters[$resource])) {
            $this->rateLimitCounters[$resource] = ['count' => 0, 'window_start' => $now];
        }

        $counter = &$this->rateLimitCounters[$resource];

        if (($now - $counter['window_start']) >= self::RATE_LIMIT_WINDOW_SECONDS) {
            $counter['count'] = 0;
            $counter['window_start'] = $now;
        }

        if ($counter['count'] >= self::RATE_LIMIT_MAX_CALLS) {
            throw new ResilienceExceededException(
                sprintf('Rate limit exceeded for resource %s', $resource)
            );
        }

        $counter['count']++;
    }

    private function acquireBulkhead(string $resource): void
    {
        if (!isset($this->bulkheadSemaphores[$resource])) {
            $this->bulkheadSemaphores[$resource] = [
                'current' => 0,
                'max' => self::BULKHEAD_CAPACITY,
                'queue' => [],
            ];
        }

        $semaphore = &$this->bulkheadSemaphores[$resource];

        if ($semaphore['current'] >= $semaphore['max']) {
            $waitStart = time();

            while ($semaphore['current'] >= $semaphore['max']) {
                if ((time() - $waitStart) >= self::BULKHEAD_WAIT_TIMEOUT) {
                    throw new ResilienceExceededException(
                        sprintf('Bulkhead capacity exceeded for resource %s', $resource)
                    );
                }
                usleep(10000);
            }
        }

        $semaphore['current']++;
    }

    private function releaseBulkhead(string $resource): void
    {
        if (isset($this->bulkheadSemaphores[$resource])) {
            $this->bulkheadSemaphores[$resource]['current']--;
        }
    }

    public function getFailureThreshold(): int
    {
        return self::MAX_CONSECUTIVE_FAILURES;
    }

    public function getRecoveryTimeout(): int
    {
        return self::RECOVERY_TIMEOUT;
    }
}
