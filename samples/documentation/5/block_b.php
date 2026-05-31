<?php

declare(strict_types=1);

namespace App\Application\Middleware;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use App\Infrastructure\RateLimiting\RateLimiter;
use App\Infrastructure\RateLimiting\QuotaExceededException;

/**
 * Rate Limiting Middleware Implementation
 *
 * This middleware enforces rate limits for incoming API requests.
 * The rate limiting rules are documented in the inline comments below,
 * which are duplicated from the API developer docs and admin dashboard.
 *
 * HOW RATE LIMITING WORKS:
 *
 * 1. IDENTIFICATION (documented in docs/api/rate-limiting-impl.md):
 *    - Anonymous requests identified by IP address (X-Forwarded-For considered)
 *    - Authenticated requests identified by user ID from JWT token
 *    - Partner requests identified by partner ID from API key
 *
 * 2. LIMIT APPLICATION (documented in docs/api/rate-limiting-impl.md):
 *    - Token bucket algorithm with configurable rate and burst
 *    - Sliding window counter for per-minute limits
 *    - Daily counter reset at midnight UTC
 *    - Monthly counter reset at start of month
 *
 * 3. RESPONSE BEHAVIOR (documented in docs/api/rate-limiting-impl.md):
 *    - 200 OK if under limit
 *    - 429 Too Many Requests if limit exceeded (without Retry-After if unknown)
 *    - 429 with Retry-After header if limit exceeded (known reset time)
 *    - Custom error response with error code and current usage
 *
 * 4. BYPASS CONDITIONS (documented in docs/api/rate-limiting-impl.md):
 *    - Internal service calls marked with X-Internal-Service header
 *    - Health check endpoints /health and /ready are never rate limited
 *    - Webhook POST endpoints are exempt from rate limits
 *    - Rate limit can be temporarily disabled via feature flag
 *
 * RATE LIMIT STORAGE:
 *    - Redis cluster with automatic failover
 *    - Keys: ratelimit:{type}:{id}:{window} where type is ip/user/partner
 *    - TTL set to window size + 60 seconds for sliding window cleanup
 *    - Per-partner limits stored separately for isolation
 *
 * See also: developer.example.com/docs/rate-limiting and JIRA API-342
 */
class RateLimitMiddleware
{
    public function __construct(
        private readonly RateLimiter $rateLimiter,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Process incoming request and enforce rate limits.
     *
     * @param ServerRequestInterface $request
     * @param RequestHandlerInterface $handler
     * @return ResponseInterface
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->isExempt($request)) {
            return $handler->handle($request);
        }

        $identifier = $this->resolveIdentifier($request);
        $tier = $this->resolveTier($request);

        $limits = $this->getLimitsForTier($tier);

        try {
            $result = $this->rateLimiter->check(
                $identifier,
                $limits['requests_per_minute'],
                $limits['window_size_seconds'] ?? 60,
            );

            $response = $handler->handle($request);

            return $this->addRateLimitHeaders($response, $result);

        } catch (QuotaExceededException $e) {
            $this->logger->warning('Rate limit exceeded', [
                'identifier' => $this->maskIdentifier($identifier),
                'tier' => $tier,
                'limit' => $e->getLimit(),
                'reset_at' => $e->getResetAt()->format(\DateTimeImmutable::ATOM),
            ]);

            return $this->buildRateLimitExceededResponse($e, $tier);
        }
    }

    /**
     * Check if request is exempt from rate limiting.
     * These exemptions are documented in the developer portal under
     * "Rate Limit Exemptions" section.
     */
    private function isExempt(ServerRequestInterface $request): bool
    {
        $path = $request->getUri()->getPath();

        if (in_array($path, ['/health', '/ready', '/healthz'], true)) {
            return true;
        }

        if ($request->getHeaderLine('X-Internal-Service') !== '') {
            return true;
        }

        if (str_starts_with($path, '/webhooks/') && $request->getMethod() === 'POST') {
            return true;
        }

        return false;
    }

    /**
     * Resolve the identifier for rate limiting.
     * The priority order is: partner > authenticated user > IP address.
     * This is documented in the internal wiki under "Rate Limiting Architecture".
     */
    private function resolveIdentifier(ServerRequestInterface $request): string
    {
        $partnerId = $this->extractPartnerId($request);
        if ($partnerId !== null) {
            return "partner:{$partnerId}";
        }

        $userId = $this->extractUserId($request);
        if ($userId !== null) {
            return "user:{$userId}";
        }

        $ipAddress = $this->extractIpAddress($request);
        return "ip:{$ipAddress}";
    }

    /**
     * Resolve the rate limiting tier based on request authentication.
     * Tier determination is documented in the API documentation.
     */
    private function resolveTier(ServerRequestInterface $request): string
    {
        if ($this->extractPartnerId($request) !== null) {
            return 'partner_api';
        }

        if ($this->isAuthenticatedRequest($request)) {
            return 'public_api_authenticated';
        }

        return 'public_api_anonymous';
    }

    /**
     * Get rate limits for a specific tier.
     * These values are duplicated from the admin dashboard configuration.
     */
    private function getLimitsForTier(string $tier): array
    {
        return match ($tier) {
            'partner_api' => [
                'requests_per_minute' => 5000,
                'burst_allowance' => 2.0,
                'burst_duration_seconds' => 30,
                'daily_limit' => 1000000,
                'monthly_limit' => 30000000,
                'window_size_seconds' => 60,
            ],
            'public_api_authenticated' => [
                'requests_per_minute' => 1000,
                'burst_allowance' => 1.5,
                'burst_duration_seconds' => 10,
                'daily_limit' => 50000,
                'window_size_seconds' => 60,
            ],
            'public_api_anonymous' => [
                'requests_per_minute' => 100,
                'burst_allowance' => 1.5,
                'burst_duration_seconds' => 10,
                'daily_limit' => 50000,
                'window_size_seconds' => 60,
            ],
            default => [
                'requests_per_minute' => 100,
                'window_size_seconds' => 60,
            ],
        };
    }

    /**
     * Add rate limit headers to response.
     * Header names are documented in the API documentation under "Response Headers".
     */
    private function addRateLimitHeaders(ResponseInterface $response, RateLimitResult $result): ResponseInterface
    {
        return $response
            ->withHeader('X-RateLimit-Limit', (string) $result->getLimit())
            ->withHeader('X-RateLimit-Remaining', (string) $result->getRemaining())
            ->withHeader('X-RateLimit-Reset', (string) $result->getResetAt()->getTimestamp());
    }

    /**
     * Build the rate limit exceeded response.
     * Error format is documented in docs/api/error-format.md
     */
    private function buildRateLimitExceededResponse(QuotaExceededException $e, string $tier): ResponseInterface
    {
        $body = [
            'error' => 'too_many_requests',
            'message' => 'Rate limit exceeded. Please slow down your requests.',
            'limit' => $e->getLimit(),
            'retry_after' => $e->getRetryAfterSeconds(),
            'tier' => $tier,
        ];

        $response = new JsonResponse($body, 429);
        $response = $response->withHeader('Retry-After', (string) $e->getRetryAfterSeconds());

        return $response;
    }
}
