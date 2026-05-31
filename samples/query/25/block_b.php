<?php

declare(strict_types=1);

namespace App\Services\Api;

use Illuminate\Support\Facades\Cache;
use Psr\Log\LoggerInterface;

final class ApiRateLimitService
{
    private const RATE_LIMIT_ANONYMOUS = 60;
    private const RATE_LIMIT_USER_STANDARD = 1000;
    private const RATE_LIMIT_USER_PREMIUM = 5000;
    private const RATE_LIMIT_WINDOW = 60;
    private const RATE_LIMIT_HEADER_KEY = 'X-RateLimit-Limit';
    private const RATE_LIMIT_HEADER_REMAINING = 'X-RateLimit-Remaining';
    private const RATE_LIMIT_HEADER_RESET = 'X-RateLimit-Reset';
    private const RATE_LIMIT_BURST_BONUS = 10;
    private const RATE_LIMIT_EXEMPT_IPS = ['127.0.0.1', '10.0.0.1', 'localhost'];
    private const RATE_LIMIT_EXEMPT_PATHS = ['/api/health-check', '/api/status'];
    private const RATE_LIMIT_CACHE_PREFIX = 'api_rl:';
    private const RATE_LIMIT_LOG_ENABLED = true;
    private const RATE_LIMIT_STRICT_ENFORCEMENT = false;
    private const RATE_LIMIT_RETRY_AFTER = 'Retry-After';

    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function checkLimit(string $identifier, string $endpoint, int $tier = 1): array
    {
        $limit = $this->determineLimit($tier);
        $key = $this->constructKey($identifier, $endpoint);

        $current = $this->fetchCurrentUsage($key);
        $remaining = max(0, $limit - $current);
        $resetTimestamp = $this->calculateResetTimestamp();

        if ($current >= $limit) {
            $this->recordExceededEvent($identifier, $endpoint, $current, $limit);

            return [
                'allowed' => false,
                'limit' => $limit,
                'remaining' => 0,
                'reset' => $resetTimestamp,
                'retry_after' => $resetTimestamp - time(),
            ];
        }

        $this->incrementUsage($key);

        return [
            'allowed' => true,
            'limit' => $limit,
            'remaining' => $remaining - 1,
            'reset' => $resetTimestamp,
        ];
    }

    public function getLimitStatus(string $identifier, string $endpoint, int $tier = 1): array
    {
        $limit = $this->determineLimit($tier);
        $key = $this->constructKey($identifier, $endpoint);

        $current = $this->fetchCurrentUsage($key);
        $remaining = max(0, $limit - $current);

        return [
            'limit' => $limit,
            'remaining' => $remaining,
            'reset' => $this->calculateResetTimestamp(),
            'current' => $current,
        ];
    }

    public function isExempt(string $ipAddress, string $path): bool
    {
        if (in_array($ipAddress, self::RATE_LIMIT_EXEMPT_IPS, true)) {
            return true;
        }

        foreach (self::RATE_LIMIT_EXEMPT_PATHS as $exemptPath) {
            if (str_starts_with($path, $exemptPath)) {
                return true;
            }
        }

        return false;
    }

    public function resetLimit(string $identifier, ?string $endpoint = null): bool
    {
        if ($endpoint === null) {
            $pattern = self::RATE_LIMIT_CACHE_PREFIX . $identifier . ':*';
            return Cache::flush() !== false;
        }

        $key = $this->constructKey($identifier, $endpoint);
        return Cache::forget($key);
    }

    public function getEffectiveLimit(int $tier, bool $isAuthenticated, bool $isPremium = false): int
    {
        if (!$isAuthenticated) {
            return self::RATE_LIMIT_ANONYMOUS;
        }

        if ($isPremium) {
            return self::RATE_LIMIT_USER_PREMIUM;
        }

        return self::RATE_LIMIT_USER_STANDARD;
    }

    private function determineLimit(int $tier): int
    {
        return match ($tier) {
            1 => self::RATE_LIMIT_ANONYMOUS,
            2 => self::RATE_LIMIT_USER_STANDARD,
            3 => self::RATE_LIMIT_USER_PREMIUM,
            default => self::RATE_LIMIT_USER_STANDARD,
        };
    }

    private function constructKey(string $identifier, string $endpoint): string
    {
        $sanitizedEndpoint = preg_replace('/[^a-zA-Z0-9_-]/', '_', ltrim($endpoint, '/'));
        return self::RATE_LIMIT_CACHE_PREFIX . $identifier . ':' . $sanitizedEndpoint;
    }

    private function fetchCurrentUsage(string $key): int
    {
        $value = Cache::get($key);
        return $value !== null ? (int) $value : 0;
    }

    private function incrementUsage(string $key): void
    {
        $current = Cache::get($key);

        if ($current === null) {
            Cache::put($key, 1, self::RATE_LIMIT_WINDOW);
        } else {
            Cache::increment($key);
        }
    }

    private function calculateResetTimestamp(): int
    {
        return time() + self::RATE_LIMIT_WINDOW;
    }

    private function recordExceededEvent(string $identifier, string $endpoint, int $current, int $limit): void
    {
        if (!self::RATE_LIMIT_LOG_ENABLED) {
            return;
        }

        $this->logger->warning('API rate limit exceeded', [
            'identifier' => $identifier,
            'endpoint' => $endpoint,
            'current_usage' => $current,
            'limit' => $limit,
            'window_seconds' => self::RATE_LIMIT_WINDOW,
            'strict_enforcement' => self::RATE_LIMIT_STRICT_ENFORCEMENT,
        ]);
    }

    public function getHeaders(int $limit, int $remaining, int $resetTimestamp): array
    {
        $headers = [
            self::RATE_LIMIT_HEADER_KEY => (string) $limit,
            self::RATE_LIMIT_HEADER_REMAINING => (string) max(0, $remaining),
            self::RATE_LIMIT_HEADER_RESET => (string) $resetTimestamp,
        ];

        if ($remaining <= self::RATE_LIMIT_BURST_BONUS) {
            $headers[self::RATE_LIMIT_RETRY_AFTER] = (string) max(1, $resetTimestamp - time());
        }

        return $headers;
    }
}
