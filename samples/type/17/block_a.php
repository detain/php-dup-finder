<?php
declare(strict_types=1);

namespace RateLimiter\Services;

use Psr\Log\LoggerInterface;

final class ApiRateLimiter
{
    private const WINDOW_SIZE_SECONDS = 60;
    private const DEFAULT_MAX_REQUESTS = 60;
    private const AUTH_MAX_REQUESTS = 10;
    private const SEARCH_MAX_REQUESTS = 30;
    private const UPLOAD_MAX_REQUESTS = 10;
    private const EXPORT_MAX_REQUESTS = 5;

    private const CACHE_PREFIX = 'rate_limit:';
    private const BYPASS_KEY = 'rate_limit_bypass';
    private const CUSTOM_LIMIT_HEADER = 'X-RateLimit-Limit';
    private const REMAINING_HEADER = 'X-RateLimit-Remaining';
    private const RESET_HEADER = 'X-RateLimit-Reset';

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function checkRateLimit(string $identifier, string $endpoint = 'default'): RateLimitResult
    {
        $cacheKey = $this->buildCacheKey($identifier, $endpoint);

        if ($this->isBypassed($identifier)) {
            return new RateLimitResult(
                allowed: true,
                limit: 0,
                remaining: 0,
                resetAt: 0,
                isBypassed: true
            );
        }

        $maxRequests = $this->getMaxRequestsForEndpoint($endpoint);
        $currentCount = $this->getCurrentCount($cacheKey);

        if ($currentCount >= $maxRequests) {
            $this->logger->warning('Rate limit exceeded', [
                'identifier' => $identifier,
                'endpoint' => $endpoint,
                'current' => $currentCount,
                'max' => $maxRequests,
            ]);

            $resetAt = $this->getWindowResetTime();

            return new RateLimitResult(
                allowed: false,
                limit: $maxRequests,
                remaining: 0,
                resetAt: $resetAt,
                retryAfter: $resetAt - time()
            );
        }

        $this->incrementCount($cacheKey);
        $remaining = $maxRequests - $currentCount - 1;
        $resetAt = $this->getWindowResetTime();

        return new RateLimitResult(
            allowed: true,
            limit: $maxRequests,
            remaining: $remaining,
            resetAt: $resetAt,
            isBypassed: false
        );
    }

    public function getHeaders(string $identifier, string $endpoint): array
    {
        $result = $this->checkRateLimit($identifier, $endpoint);

        return [
            self::CUSTOM_LIMIT_HEADER => (string)$result->limit,
            self::REMAINING_HEADER => (string)$result->remaining,
            self::RESET_HEADER => (string)$result->resetAt,
        ];
    }

    public function getCurrentCount(string $cacheKey): int
    {
        $cached = apcu_fetch($cacheKey, $success);
        return $success ? (int)$cached : 0;
    }

    public function incrementCount(string $cacheKey): void
    {
        $currentCount = $this->getCurrentCount($cacheKey);
        apcu_store($cacheKey, (string)($currentCount + 1), self::WINDOW_SIZE_SECONDS);
    }

    public function resetLimit(string $identifier, string $endpoint = 'default'): void
    {
        $cacheKey = $this->buildCacheKey($identifier, $endpoint);
        apcu_delete($cacheKey);

        $this->logger->info('Rate limit reset', [
            'identifier' => $identifier,
            'endpoint' => $endpoint,
        ]);
    }

    private function buildCacheKey(string $identifier, string $endpoint): string
    {
        return self::CACHE_PREFIX . $endpoint . ':' . $identifier;
    }

    private function getMaxRequestsForEndpoint(string $endpoint): int
    {
        return match ($endpoint) {
            'auth' => self::AUTH_MAX_REQUESTS,
            'search' => self::SEARCH_MAX_REQUESTS,
            'upload' => self::UPLOAD_MAX_REQUESTS,
            'export' => self::EXPORT_MAX_REQUESTS,
            default => self::DEFAULT_MAX_REQUESTS,
        };
    }

    private function getWindowResetTime(): int
    {
        return time() + self::WINDOW_SIZE_SECONDS;
    }

    private function isBypassed(string $identifier): bool
    {
        $bypassKey = self::CACHE_PREFIX . self::BYPASS_KEY . ':' . $identifier;
        return apcu_fetch($bypassKey, $success) !== false;
    }

    public function bypass(string $identifier, int $durationSeconds = 3600): void
    {
        $bypassKey = self::CACHE_PREFIX . self::BYPASS_KEY . ':' . $identifier;
        apcu_store($bypassKey, '1', $durationSeconds);

        $this->logger->info('Rate limit bypass granted', [
            'identifier' => $identifier,
            'duration' => $durationSeconds,
        ]);
    }

    public function removeBypass(string $identifier): void
    {
        $bypassKey = self::CACHE_PREFIX . self::BYPASS_KEY . ':' . $identifier;
        apcu_delete($bypassKey);
    }
}
