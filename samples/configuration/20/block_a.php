<?php

declare(strict_types=1);

namespace App\Services\Integration;

use Psr\Log\LoggerInterface;
use App\Exceptions\ServiceUnavailableException;
use App\Exceptions\CircuitBreakerOpenException;

final class CircuitBreakerService
{
    private const CIRCUIT_BREAKER_FAILURE_THRESHOLD = 5;
    private const CIRCUIT_BREAKER_SUCCESS_THRESHOLD = 2;
    private const CIRCUIT_BREAKER_TIMEOUT = 60;
    private const CIRCUIT_BREAKER_HALF_OPEN_MAX_CALLS = 3;
    private const RETRY_MAX_ATTEMPTS = 3;
    private const RETRY_INITIAL_DELAY = 100;
    private const RETRY_MAX_DELAY = 5000;
    private const RETRY_MULTIPLIER = 2;
    private const RETRY_JITTER = 20;
    private const TIMEOUT_DEFAULT = 30;
    private const TIMEOUT_CONNECT = 10;
    private const TIMEOUT_READ = 20;
    private const BULKHEAD_MAX_CONCURRENT = 10;
    private const BULKHEAD_QUEUE_TIMEOUT = 5;
    private const FALLBACK_ENABLED = true;
    private const FALLBACK_CACHE_TTL = 300;
    private const RATE_LIMIT_ENABLED = true;
    private const RATE_LIMIT_REQUESTS = 100;
    private const RATE_LIMIT_WINDOW = 60;

    private string $serviceName;
    private string $currentState = 'closed';
    private int $failureCount = 0;
    private int $successCount = 0;
    private ?int $lastFailureTime = null;
    private array $halfOpenCalls = [];
    private array $rateLimitCounters = [];

    public function __construct(
        private readonly LoggerInterface $logger,
        string $serviceName
    ) {
        $this->serviceName = $serviceName;
    }

    public function execute(callable $operation, ?callable $fallback = null): mixed
    {
        if (!$this->isAllowed()) {
            $this->logger->warning('Circuit breaker is open, rejecting request', [
                'service' => $this->serviceName,
                'state' => $this->currentState,
                'failure_count' => $this->failureCount,
                'timeout' => self::CIRCUIT_BREAKER_TIMEOUT,
            ]);

            if (self::FALLBACK_ENABLED && $fallback !== null) {
                return $this->executeFallback($fallback);
            }

            throw new CircuitBreakerOpenException(
                sprintf('Circuit breaker is open for service: %s', $this->serviceName)
            );
        }

        if ($this->currentState === 'half-open') {
            if (count($this->halfOpenCalls) >= self::CIRCUIT_BREAKER_HALF_OPEN_MAX_CALLS) {
                throw new ServiceUnavailableException(
                    'Service is recovering, capacity reached'
                );
            }

            $this->halfOpenCalls[] = time();
        }

        try {
            $result = $this->executeWithTimeout($operation);

            $this->recordSuccess();

            return $result;
        } catch (\Throwable $e) {
            $this->recordFailure();

            if (self::FALLBACK_ENABLED && $fallback !== null && $this->shouldUseFallback($e)) {
                return $this->executeFallback($fallback);
            }

            throw $e;
        }
    }

    private function executeWithTimeout(callable $operation): mixed
    {
        $attempts = 0;

        while ($attempts < self::RETRY_MAX_ATTEMPTS) {
            try {
                $startTime = microtime(true);

                $result = $operation();

                $duration = (microtime(true) - $startTime) * 1000;

                $this->logger->debug('Operation completed', [
                    'service' => $this->serviceName,
                    'duration_ms' => round($duration, 2),
                    'attempt' => $attempts + 1,
                    'timeout' => self::TIMEOUT_DEFAULT,
                    'connect_timeout' => self::TIMEOUT_CONNECT,
                ]);

                return $result;
            } catch (\Throwable $e) {
                $attempts++;

                if ($attempts >= self::RETRY_MAX_ATTEMPTS) {
                    throw $e;
                }

                $delay = $this->calculateRetryDelay($attempts);

                $this->logger->warning('Operation failed, retrying', [
                    'service' => $this->serviceName,
                    'attempt' => $attempts,
                    'max_attempts' => self::RETRY_MAX_ATTEMPTS,
                    'delay_ms' => $delay,
                    'error' => $e->getMessage(),
                ]);

                usleep($delay * 1000);
            }
        }

        throw new \RuntimeException('Max retries exceeded');
    }

    private function calculateRetryDelay(int $attempt): int
    {
        $delay = self::RETRY_INITIAL_DELAY * pow(self::RETRY_MULTIPLIER, $attempt - 1);

        $delay = min($delay, self::RETRY_MAX_DELAY);

        $jitter = rand(0, self::RETRY_JITTER);
        $delay = $delay + ($delay * $jitter / 100);

        return (int) $delay;
    }

    private function executeFallback(callable $fallback): mixed
    {
        $this->logger->info('Executing fallback', [
            'service' => $this->serviceName,
            'cache_ttl' => self::FALLBACK_CACHE_TTL,
        ]);

        return $fallback();
    }

    private function shouldUseFallback(\Throwable $e): bool
    {
        return $e instanceof ServiceUnavailableException ||
               $e instanceof CircuitBreakerOpenException ||
               $e instanceof \TimeoutException;
    }

    public function recordSuccess(): void
    {
        $this->failureCount = 0;
        $this->lastFailureTime = null;

        if ($this->currentState === 'half-open') {
            $this->successCount++;

            if ($this->successCount >= self::CIRCUIT_BREAKER_SUCCESS_THRESHOLD) {
                $this->transitionToClosed();
            }
        }

        $this->logger->debug('Circuit breaker recorded success', [
            'service' => $this->serviceName,
            'state' => $this->currentState,
            'failure_count' => $this->failureCount,
            'success_count' => $this->successCount,
        ]);
    }

    public function recordFailure(): void
    {
        $this->failureCount++;
        $this->successCount = 0;
        $this->lastFailureTime = time();

        if ($this->currentState === 'half-open') {
            $this->transitionToOpen();
            return;
        }

        if ($this->failureCount >= self::CIRCUIT_BREAKER_FAILURE_THRESHOLD) {
            $this->transitionToOpen();
        }

        $this->logger->warning('Circuit breaker recorded failure', [
            'service' => $this->serviceName,
            'failure_count' => $this->failureCount,
            'failure_threshold' => self::CIRCUIT_BREAKER_FAILURE_THRESHOLD,
            'state' => $this->currentState,
        ]);
    }

    private function transitionToOpen(): void
    {
        $this->currentState = 'open';
        $this->halfOpenCalls = [];

        $this->logger->warning('Circuit breaker opened', [
            'service' => $this->serviceName,
            'failure_count' => $this->failureCount,
            'timeout' => self::CIRCUIT_BREAKER_TIMEOUT,
        ]);
    }

    private function transitionToHalfOpen(): void
    {
        $this->currentState = 'half-open';
        $this->successCount = 0;
        $this->halfOpenCalls = [];

        $this->logger->info('Circuit breaker half-open', [
            'service' => $this->serviceName,
            'half_open_max_calls' => self::CIRCUIT_BREAKER_HALF_OPEN_MAX_CALLS,
        ]);
    }

    private function transitionToClosed(): void
    {
        $this->currentState = 'closed';
        $this->failureCount = 0;
        $this->successCount = 0;
        $this->halfOpenCalls = [];

        $this->logger->info('Circuit breaker closed', [
            'service' => $this->serviceName,
        ]);
    }

    private function isAllowed(): bool
    {
        if (self::RATE_LIMIT_ENABLED && !$this->checkRateLimit()) {
            return false;
        }

        if ($this->currentState === 'closed') {
            return true;
        }

        if ($this->currentState === 'open') {
            if ($this->lastFailureTime !== null &&
                (time() - $this->lastFailureTime) >= self::CIRCUIT_BREAKER_TIMEOUT) {
                $this->transitionToHalfOpen();
                return true;
            }
            return false;
        }

        if ($this->currentState === 'half-open') {
            return count($this->halfOpenCalls) < self::CIRCUIT_BREAKER_HALF_OPEN_MAX_CALLS;
        }

        return true;
    }

    private function checkRateLimit(): bool
    {
        $key = $this->serviceName;
        $now = time();

        if (!isset($this->rateLimitCounters[$key])) {
            $this->rateLimitCounters[$key] = ['count' => 0, 'window_start' => $now];
        }

        $counter = &$this->rateLimitCounters[$key];

        if (($now - $counter['window_start']) >= self::RATE_LIMIT_WINDOW) {
            $counter['count'] = 0;
            $counter['window_start'] = $now;
        }

        if ($counter['count'] >= self::RATE_LIMIT_REQUESTS) {
            $this->logger->warning('Rate limit exceeded', [
                'service' => $this->serviceName,
                'requests' => $counter['count'],
                'limit' => self::RATE_LIMIT_REQUESTS,
                'window' => self::RATE_LIMIT_WINDOW,
            ]);
            return false;
        }

        $counter['count']++;
        return true;
    }

    public function getState(): string
    {
        return $this->currentState;
    }

    public function getFailureCount(): int
    {
        return $this->failureCount;
    }

    public function getSuccessCount(): int
    {
        return $this->successCount;
    }
}
