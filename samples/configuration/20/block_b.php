<?php

declare(strict_types=1);

namespace App\Services\Integration;

use Psr\Log\LoggerInterface;

final class ExternalApiRetryHandler
{
    private const RETRY_MAX_ATTEMPTS = 3;
    private const RETRY_INITIAL_DELAY = 100;
    private const RETRY_MAX_DELAY = 3000;
    private const RETRY_MULTIPLIER = 2;
    private const RETRY_JITTER_PERCENT = 15;
    private const RETRY_ON_STATUS_CODES = [408, 429, 500, 502, 503, 504];
    private const RETRY_EXCEPTIONS = [
        'ConnectionException',
        'ConnectException',
        'TimeoutException',
        'RequestException',
    ];
    private const TIMEOUT_DEFAULT = 30;
    private const TIMEOUT_CONNECT = 5;
    private const TIMEOUT_READ = 25;
    private const CIRCUIT_BREAKER_THRESHOLD = 5;
    private const CIRCUIT_BREAKER_TIMEOUT = 30;
    private const CIRCUIT_BREAKER_HALF_OPEN_SUCCESSES = 2;
    private const RATE_LIMIT_RETRIES = 3;
    private const RATE_LIMIT_RETRY_DELAY = 500;
    private const FALLBACK_CACHE_ENABLED = true;
    private const FALLBACK_CACHE_TTL = 300;
    private const BULKHEAD_MAX_CONCURRENT = 10;
    private const CONTEXT_PROPAGATION_ENABLED = true;

    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function executeWithRetry(callable $operation, ?callable $fallback = null): mixed
    {
        $attempts = 0;
        $lastException = null;

        while ($attempts < self::RETRY_MAX_ATTEMPTS) {
            try {
                $result = $this->executeWithTimeout($operation, $attempts);

                if ($attempts > 0) {
                    $this->logger->info('Operation succeeded after retry', [
                        'attempts' => $attempts + 1,
                        'initial_delay' => self::RETRY_INITIAL_DELAY,
                        'max_delay' => self::RETRY_MAX_DELAY,
                    ]);
                }

                return $result;
            } catch (\Throwable $e) {
                $attempts++;
                $lastException = $e;

                if (!$this->shouldRetry($e)) {
                    $this->logger->error('Operation failed with non-retryable error', [
                        'error' => $e->getMessage(),
                        'exception_class' => get_class($e),
                        'attempt' => $attempts,
                    ]);

                    if ($fallback !== null && $this->isFallbackAllowed($e)) {
                        return $this->executeFallback($fallback);
                    }

                    throw $e;
                }

                if ($attempts >= self::RETRY_MAX_ATTEMPTS) {
                    $this->logger->error('Max retry attempts exceeded', [
                        'max_attempts' => self::RETRY_MAX_ATTEMPTS,
                        'multiplier' => self::RETRY_MULTIPLIER,
                        'error' => $e->getMessage(),
                    ]);

                    if ($fallback !== null && $this->isFallbackAllowed($e)) {
                        return $this->executeFallback($fallback);
                    }

                    throw $e;
                }

                $delay = $this->calculateBackoffDelay($attempts);

                $this->logger->warning('Retrying after failure', [
                    'attempt' => $attempts,
                    'max_attempts' => self::RETRY_MAX_ATTEMPTS,
                    'delay_ms' => $delay,
                    'error' => $e->getMessage(),
                    'exception_class' => get_class($e),
                ]);

                usleep($delay * 1000);
            }
        }

        throw $lastException ?? new \RuntimeException('Max retries exceeded');
    }

    private function executeWithTimeout(callable $operation, int $attempt): mixed
    {
        $timeout = $this->getTimeoutForAttempt($attempt);

        $startTime = microtime(true);

        $result = $operation();

        $duration = (microtime(true) - $startTime) * 1000;

        $this->logger->debug('API call completed', [
            'duration_ms' => round($duration, 2),
            'timeout' => $timeout,
            'connect_timeout' => self::TIMEOUT_CONNECT,
            'attempt' => $attempt + 1,
        ]);

        return $result;
    }

    private function getTimeoutForAttempt(int $attempt): int
    {
        $baseTimeout = self::TIMEOUT_DEFAULT;
        $maxTimeout = 60;

        $timeout = $baseTimeout + ($attempt * 5);
        return min($timeout, $maxTimeout);
    }

    private function calculateBackoffDelay(int $attempt): int
    {
        $baseDelay = self::RETRY_INITIAL_DELAY;
        $maxDelay = self::RETRY_MAX_DELAY;
        $multiplier = self::RETRY_MULTIPLIER;

        $delay = $baseDelay * pow($multiplier, $attempt - 1);

        $delay = min($delay, $maxDelay);

        $jitterPercent = self::RETRY_JITTER_PERCENT;
        $jitter = rand(-$jitterPercent, $jitterPercent);
        $delay = $delay * (1 + $jitter / 100);

        return (int) $delay;
    }

    private function shouldRetry(\Throwable $e): bool
    {
        if (in_array(get_class($e), self::RETRY_EXCEPTIONS, true)) {
            return true;
        }

        if (method_exists($e, 'getCode') && in_array($e->getCode(), self::RETRY_ON_STATUS_CODES, true)) {
            return true;
        }

        $message = strtolower($e->getMessage());

        if (str_contains($message, 'timeout') ||
            str_contains($message, 'connection') ||
            str_contains($message, 'temporary')) {
            return true;
        }

        return false;
    }

    private function isFallbackAllowed(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        if (str_contains($message, 'not found') ||
            str_contains($message, 'unauthorized') ||
            str_contains($message, 'forbidden')) {
            return false;
        }

        return true;
    }

    private function executeFallback(callable $fallback): mixed
    {
        if (!self::FALLBACK_CACHE_ENABLED) {
            return $fallback();
        }

        $this->logger->info('Executing fallback', [
            'cache_ttl' => self::FALLBACK_CACHE_TTL,
        ]);

        return $fallback();
    }

    public function getMaxAttempts(): int
    {
        return self::RETRY_MAX_ATTEMPTS;
    }

    public function getInitialDelay(): int
    {
        return self::RETRY_INITIAL_DELAY;
    }

    public function getMaxDelay(): int
    {
        return self::RETRY_MAX_DELAY;
    }

    public function getRetryStatusCodes(): array
    {
        return self::RETRY_ON_STATUS_CODES;
    }
}
