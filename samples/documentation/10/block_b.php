<?php

declare(strict_types=1);

namespace App\Infrastructure\RateLimiting;

use App\Infrastructure\Cache\CacheService;
use App\Infrastructure\Logging\LoggerInterface;

/**
 * Token bucket rate limiter implementation.
 *
 * This implementation enforces rate limits documented in:
 * - Developer portal: developer.example.com/docs/rate-limits
 * - API SDK: sdk-php rate limiting
 * - Admin dashboard: admin.example.com/rate-limits
 * - Configuration: config/rate-limits.php
 *
 * ALGORITHM OVERVIEW:
 * The token bucket algorithm allows burst traffic up to the bucket capacity,
 * then refills at a steady rate. This provides smoother traffic patterns
 * than a simple sliding window.
 *
 * TOKEN BUCKET PARAMETERS (per tier documentation):
 *
 * FREE TIER:
 * - bucket_capacity: 72 tokens (1.2x of 60 rpm)
 * - refill_rate: 1 token per second
 * - refill_period: 1 second
 *
 * BASIC TIER:
 * - bucket_capacity: 450 tokens (1.5x of 300 rpm)
 * - refill_rate: 5 tokens per second
 * - refill_period: 1 second
 *
 * PROFESSIONAL TIER:
 * - bucket_capacity: 2000 tokens (2.0x of 1000 rpm)
 * - refill_rate: 16.67 tokens per second
 * - refill_period: 1 second
 *
 * ENTERPRISE TIER:
 * - bucket_capacity: 15000 tokens (3.0x of 5000 rpm)
 * - refill_rate: 83.33 tokens per second
 * - refill_period: 1 second
 *
 * RATE LIMIT STORAGE:
 * - Redis key format: ratelimit:{tier}:{user_id}:{endpoint}
 * - Key contains: tokens remaining, last refill timestamp
 * - TTL: bucket_capacity / refill_rate + 60 seconds
 *
 * CONCURRENCY HANDLING:
 * - Uses Redis WATCH/MULTI/EXEC for atomic operations
 * - Lua script for checking and updating in single operation
 * - Prevents race conditions in distributed environments
 *
 * See also: docs/architecture/rate-limiting.md and JIRA API-234
 */
class TokenBucketRateLimiter
{
    private const LUA_SCRIPT = <<<'LUA'
local key = KEYS[1]
local capacity = tonumber(ARGV[1])
local refill_rate = tonumber(ARGV[2])
local now = tonumber(ARGV[3])
local requested = tonumber(ARGV[4])

local data = redis.call('HMGET', key, 'tokens', 'last_refill')
local tokens = tonumber(data[1])
local last_refill = tonumber(data[2])

if tokens == nil then
    tokens = capacity
    last_refill = now
end

local elapsed = now - last_refill
local refill = math.floor(elapsed * refill_rate)
tokens = math.min(capacity, tokens + refill)

if refill > 0 then
    last_refill = now
end

local allowed = 0
local remaining = tokens

if tokens >= requested then
    tokens = tokens - requested
    allowed = 1
    remaining = tokens
end

local ttl = math.ceil(capacity / refill_rate) + 60
redis.call('HMSET', key, 'tokens', tokens, 'last_refill', last_refill)
redis.call('EXPIRE', key, ttl)

return {allowed, remaining, math.ceil(tokens)}
LUA;

    private CacheService $cache;
    private LoggerInterface $logger;

    private array $tierConfigs = [
        'free' => [
            'requests_per_minute' => 60,
            'bucket_capacity' => 72,
            'refill_rate' => 1.0,
        ],
        'basic' => [
            'requests_per_minute' => 300,
            'bucket_capacity' => 450,
            'refill_rate' => 5.0,
        ],
        'professional' => [
            'requests_per_minute' => 1000,
            'bucket_capacity' => 2000,
            'refill_rate' => 16.67,
        ],
        'enterprise' => [
            'requests_per_minute' => 5000,
            'bucket_capacity' => 15000,
            'refill_rate' => 83.33,
        ],
    ];

    public function __construct(
        CacheService $cache,
        LoggerInterface $logger
    ) {
        $this->cache = $cache;
        $this->logger = $logger;
    }

    /**
     * Check if a request is allowed under the rate limit.
     *
     * @param string $identifier User or API key identifier
     * @param string $tier Rate limit tier (free, basic, professional, enterprise)
     * @param string $endpoint Optional endpoint-specific limit
     * @return RateLimitCheckResult Whether request is allowed
     */
    public function check(
        string $identifier,
        string $tier = 'free',
        ?string $endpoint = null
    ): RateLimitCheckResult {

        $config = $this->tierConfigs[$tier] ?? $this->tierConfigs['free'];

        $key = $this->buildKey($identifier, $endpoint);
        $now = microtime(true);

        $result = $this->executeScript(
            $key,
            $config['bucket_capacity'],
            $config['refill_rate'],
            $now,
            1
        );

        $allowed = $result[0] === 1;
        $remaining = $result[1];
        $resetAt = $now + ($remaining / $config['refill_rate']);

        if (!$allowed) {
            $this->logger->warning('Rate limit exceeded', [
                'identifier' => $this->maskIdentifier($identifier),
                'tier' => $tier,
                'endpoint' => $endpoint,
                'remaining_tokens' => $remaining,
            ]);
        }

        return new RateLimitCheckResult(
            allowed: $allowed,
            limit: $config['requests_per_minute'],
            remaining: (int) $remaining,
            resetAt: (int) $resetAt,
            retryAfter: $allowed ? null : (int) ceil(($config['bucket_capacity'] - $remaining) / $config['refill_rate']),
        );
    }

    /**
     * Execute the rate limiting Lua script atomically.
     */
    private function executeScript(
        string $key,
        int $capacity,
        float $refillRate,
        float $now,
        int $requested
    ): array {

        return $this->cache->eval(
            self::LUA_SCRIPT,
            [$key],
            [$capacity, $refillRate, $now, $requested]
        );
    }

    /**
     * Build Redis key for rate limiting.
     */
    private function buildKey(string $identifier, ?string $endpoint): string
    {
        $base = "ratelimit:{$identifier}";

        if ($endpoint !== null) {
            return "{$base}:{$endpoint}";
        }

        return $base;
    }

    /**
     * Mask identifier for logging (privacy).
     */
    private function maskIdentifier(string $identifier): string
    {
        if (strlen($identifier) <= 8) {
            return $identifier;
        }

        return substr($identifier, 0, 4) . '****' . substr($identifier, -4);
    }

    /**
     * Reset rate limit for an identifier (admin function).
     */
    public function reset(string $identifier): void
    {
        $key = $this->buildKey($identifier, null);
        $this->cache->del($key);

        $this->logger->info('Rate limit reset', [
            'identifier' => $this->maskIdentifier($identifier),
        ]);
    }
}
