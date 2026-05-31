<?php
declare(strict_types=1);

namespace RateLimiter\Services;

use Psr\Log\LoggerInterface;

final class WebhookRateLimiter
{
    private const WINDOW_SIZE_SECONDS = 60;
    private const DEFAULT_MAX_REQUESTS = 60;
    private const DELIVERY_MAX_REQUESTS = 100;
    private const RETRY_MAX_REQUESTS = 20;
    private const STATUS_CHECK_MAX_REQUESTS = 50;
    private const CONFIRMATION_MAX_REQUESTS = 30;

    private const CACHE_PREFIX = 'webhook_rate:';
    private const BYPASS_KEY = 'webhook_rate_bypass';
    private const CUSTOM_LIMIT_HEADER = 'X-RateLimit-Limit';
    private const REMAINING_HEADER = 'X-RateLimit-Remaining';
    private const RESET_HEADER = 'X-RateLimit-Reset';

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function checkRateLimit(string $webhookId, string $operation = 'default'): RateLimitResult
    {
        $cacheKey = $this->buildCacheKey($webhookId, $operation);

        if ($this->isBypassed($webhookId)) {
            return new RateLimitResult(
                allowed: true,
                limit: 0,
                remaining: 0,
                resetAt: 0,
                isBypassed: true
            );
        }

        $maxRequests = $this->getMaxRequestsForOperation($operation);
        $currentCount = $this->getCurrentCount($cacheKey);

        if ($currentCount >= $maxRequests) {
            $this->logger->warning('Webhook rate limit exceeded', [
                'webhook_id' => $webhookId,
                'operation' => $operation,
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

    public function getHeaders(string $webhookId, string $operation): array
    {
        $result = $this->checkRateLimit($webhookId, $operation);

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

    public function resetLimit(string $webhookId, string $operation = 'default'): void
    {
        $cacheKey = $this->buildCacheKey($webhookId, $operation);
        apcu_delete($cacheKey);

        $this->logger->info('Webhook rate limit reset', [
            'webhook_id' => $webhookId,
            'operation' => $operation,
        ]);
    }

    private function buildCacheKey(string $webhookId, string $operation): string
    {
        return self::CACHE_PREFIX . $operation . ':' . $webhookId;
    }

    private function getMaxRequestsForOperation(string $operation): int
    {
        return match ($operation) {
            'delivery' => self::DELIVERY_MAX_REQUESTS,
            'retry' => self::RETRY_MAX_REQUESTS,
            'status_check' => self::STATUS_CHECK_MAX_REQUESTS,
            'confirmation' => self::CONFIRMATION_MAX_REQUESTS,
            default => self::DEFAULT_MAX_REQUESTS,
        };
    }

    private function getWindowResetTime(): int
    {
        return time() + self::WINDOW_SIZE_SECONDS;
    }

    private function isBypassed(string $webhookId): bool
    {
        $bypassKey = self::CACHE_PREFIX . self::BYPASS_KEY . ':' . $webhookId;
        return apcu_fetch($bypassKey, $success) !== false;
    }

    public function bypass(string $webhookId, int $durationSeconds = 3600): void
    {
        $bypassKey = self::CACHE_PREFIX . self::BYPASS_KEY . ':' . $webhookId;
        apcu_store($bypassKey, '1', $durationSeconds);

        $this->logger->info('Webhook rate limit bypass granted', [
            'webhook_id' => $webhookId,
            'duration' => $durationSeconds,
        ]);
    }

    public function removeBypass(string $webhookId): void
    {
        $bypassKey = self::CACHE_PREFIX . self::BYPASS_KEY . ':' . $webhookId;
        apcu_delete($bypassKey);
    }
}
