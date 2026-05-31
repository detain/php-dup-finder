<?php
declare(strict_types=1);

namespace RateLimiter\Shared;

final class RateLimitConfig
{
    public const WINDOW_SIZE_SECONDS = 60;
    public const DEFAULT_MAX_REQUESTS = 60;
    public const BYPASS_DURATION_SECONDS = 3600;
}

final class RateLimitHeaders
{
    public const LIMIT = 'X-RateLimit-Limit';
    public const REMAINING = 'X-RateLimit-Remaining';
    public const RESET = 'X-RateLimit-Reset';
    public const RETRY_AFTER = 'Retry-After';
}

interface RateLimitStrategyInterface
{
    public function getMaxRequests(): int;
    public function getWindowSize(): int;
}

final class DefaultRateLimitStrategy implements RateLimitStrategyInterface
{
    public function getMaxRequests(): int
    {
        return RateLimitConfig::DEFAULT_MAX_REQUESTS;
    }

    public function getWindowSize(): int
    {
        return RateLimitConfig::WINDOW_SIZE_SECONDS;
    }
}

abstract class BaseRateLimiter
{
    protected LoggerInterface $logger;
    protected string $cachePrefix;

    public function __construct(string $cachePrefix, LoggerInterface $logger)
    {
        $this->cachePrefix = $cachePrefix;
        $this->logger = $logger;
    }

    public function checkRateLimit(string $identifier, string $type = 'default'): RateLimitResult
    {
        $cacheKey = $this->buildCacheKey($identifier, $type);

        if ($this->isBypassed($identifier)) {
            return RateLimitResult::bypassed();
        }

        $strategy = $this->getStrategyForType($type);
        $maxRequests = $strategy->getMaxRequests();
        $currentCount = $this->getCurrentCount($cacheKey);

        if ($currentCount >= $maxRequests) {
            $resetAt = $this->getWindowResetTime($strategy->getWindowSize());
            return RateLimitResult::exceeded($maxRequests, $resetAt, $resetAt - time());
        }

        $this->incrementCount($cacheKey, $strategy->getWindowSize());
        $resetAt = $this->getWindowResetTime($strategy->getWindowSize());

        return RateLimitResult::allowed($maxRequests, $maxRequests - $currentCount - 1, $resetAt);
    }

    public function getHeaders(string $identifier, string $type): array
    {
        $result = $this->checkRateLimit($identifier, $type);

        return [
            RateLimitHeaders::LIMIT => (string)$result->limit,
            RateLimitHeaders::REMAINING => (string)$result->remaining,
            RateLimitHeaders::RESET => (string)$result->resetAt,
        ];
    }

    protected function buildCacheKey(string $identifier, string $type): string
    {
        return $this->cachePrefix . $type . ':' . $identifier;
    }

    protected function getCurrentCount(string $cacheKey): int
    {
        $cached = apcu_fetch($cacheKey, $success);
        return $success ? (int)$cached : 0;
    }

    protected function incrementCount(string $cacheKey, int $windowSize): void
    {
        $currentCount = $this->getCurrentCount($cacheKey);
        apcu_store($cacheKey, (string)($currentCount + 1), $windowSize);
    }

    protected function getWindowResetTime(int $windowSize): int
    {
        return time() + $windowSize;
    }

    protected function isBypassed(string $identifier): bool
    {
        $bypassKey = $this->cachePrefix . 'bypass:' . $identifier;
        return apcu_fetch($bypassKey, $success) !== false;
    }

    public function bypass(string $identifier, int $durationSeconds = 3600): void
    {
        $bypassKey = $this->cachePrefix . 'bypass:' . $identifier;
        apcu_store($bypassKey, '1', $durationSeconds);
    }

    public function removeBypass(string $identifier): void
    {
        apcu_delete($this->cachePrefix . 'bypass:' . $identifier);
    }

    abstract protected function getStrategyForType(string $type): RateLimitStrategyInterface;
}

final class ApiRateLimiter extends BaseRateLimiter
{
    private array $endpointStrategies;

    public function __construct(LoggerInterface $logger)
    {
        parent::__construct('rate_limit:', $logger);

        $this->endpointStrategies = [
            'auth' => new class implements RateLimitStrategyInterface {
                public function getMaxRequests(): int { return 10; }
                public function getWindowSize(): int { return 60; }
            },
            'search' => new class implements RateLimitStrategyInterface {
                public function getMaxRequests(): int { return 30; }
                public function getWindowSize(): int { return 60; }
            },
        ];
    }

    protected function getStrategyForType(string $type): RateLimitStrategyInterface
    {
        return $this->endpointStrategies[$type] ?? new DefaultRateLimitStrategy();
    }
}
