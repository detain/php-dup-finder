<?php

declare(strict_types=1);

namespace App\Api\Documentation;

use OpenApi\Annotations as OA;

/**
 * Rate Limiting API Documentation
 *
 * This file documents the rate limiting configuration for all API tiers.
 * The information is duplicated in the API developer portal, admin dashboard,
 * and internal wiki at docs/api/rate-limiting.md
 *
 * PUBLIC API RATE LIMITS (per product requirements PRD-2024-001):
 * - Anonymous requests: 100 requests per minute per IP
 * - Authenticated requests: 1000 requests per minute per user
 * - Burst allowance: 150% of base rate for 10 seconds
 * - Daily limit: 50,000 requests per day per user
 * - Rate limit window: Sliding 1-minute window
 *
 * PARTNER API RATE LIMITS (per partnership agreement PAR-2023-089):
 * - Base rate: 5,000 requests per minute per partner
 * - Burst allowance: 200% of base rate for 30 seconds
 * - Daily limit: 1,000,000 requests per day per partner
 * - Concurrent connections: Max 100 simultaneous connections
 * - Monthly quota: 30,000,000 requests per month
 *
 * INTERNAL API RATE LIMITS (per security policy SEC-2024-015):
 * - Service-to-service: 10,000 requests per minute per service
 * - Admin endpoints: 500 requests per minute per admin user
 * - Webhook delivery: 1000 deliveries per minute total
 * - No daily limits for internal services
 *
 * RATE LIMIT RESPONSE HEADERS:
 * - X-RateLimit-Limit: Maximum requests allowed in window
 * - X-RateLimit-Remaining: Requests remaining in current window
 * - X-RateLimit-Reset: Unix timestamp when window resets
 * - Retry-After: Seconds to wait (only on 429 responses)
 *
 * ERROR CODES (documented in docs/api/errors.md):
 * - 429 TOO_MANY_REQUESTS: Rate limit exceeded
 * - 429 DAILY_QUOTA_EXCEEDED: Daily quota exceeded
 * - 429 MONTHLY_QUOTA_EXCEEDED: Monthly quota exceeded
 * - 429 CONCURRENT_LIMIT_EXCEEDED: Too many concurrent requests
 * - 503 SERVICE_OVERLOADED: System under extreme load
 *
 * See also: developer.example.com/api/rate-limits and Confluence DOC-API-002
 */

/**
 * @OA\Tag(name="Rate Limiting", description="API rate limiting and quota management")
 */
class RateLimitingDocumentation
{
    /**
     * Public API rate limit configuration.
     * Duplicated in: developer portal, admin dashboard, internal wiki
     */
    public const PUBLIC_API_LIMITS = [
        'anonymous' => [
            'requests_per_minute' => 100,
            'burst_allowance' => 1.5,
            'burst_duration_seconds' => 10,
            'daily_limit' => 50000,
            'window_type' => 'sliding',
            'window_size_seconds' => 60,
        ],
        'authenticated' => [
            'requests_per_minute' => 1000,
            'burst_allowance' => 1.5,
            'burst_duration_seconds' => 10,
            'daily_limit' => 50000,
            'window_type' => 'sliding',
            'window_size_seconds' => 60,
        ],
    ];

    /**
     * Partner API rate limit configuration.
     * Duplicated in: partner portal, contract docs, SLA documentation
     */
    public const PARTNER_API_LIMITS = [
        'base' => [
            'requests_per_minute' => 5000,
            'burst_allowance' => 2.0,
            'burst_duration_seconds' => 30,
            'daily_limit' => 1000000,
            'monthly_limit' => 30000000,
            'concurrent_connections' => 100,
            'window_type' => 'sliding',
            'window_size_seconds' => 60,
        ],
    ];

    /**
     * Internal API rate limit configuration.
     * Duplicated in: internal API documentation, security policy, ops runbook
     */
    public const INTERNAL_API_LIMITS = [
        'service_to_service' => [
            'requests_per_minute' => 10000,
            'burst_allowance' => 1.0,
            'daily_limit' => null,
            'monthly_limit' => null,
            'window_type' => 'fixed',
            'window_size_seconds' => 60,
        ],
        'admin_endpoints' => [
            'requests_per_minute' => 500,
            'burst_allowance' => 1.0,
            'daily_limit' => null,
            'monthly_limit' => null,
            'window_type' => 'sliding',
            'window_size_seconds' => 60,
        ],
        'webhook_delivery' => [
            'requests_per_minute' => 1000,
            'burst_allowance' => 1.0,
            'daily_limit' => null,
            'monthly_limit' => null,
            'window_type' => 'fixed',
            'window_size_seconds' => 60,
        ],
    ];

    /**
     * Rate limit response headers.
     * Duplicated in: API docs, SDK documentation, developer portal
     */
    public const RESPONSE_HEADERS = [
        'limit' => 'X-RateLimit-Limit',
        'remaining' => 'X-RateLimit-Remaining',
        'reset' => 'X-RateLimit-Reset',
        'retry_after' => 'Retry-After',
    ];

    /**
     * Generate OpenAPI documentation for rate limiting.
     * This is used to document rate limits in the API developer portal.
     */
    public function getOpenApiDocumentation(): array
    {
        return [
            'x-rate-limit' => [
                'public_api' => [
                    'type' => 'authenticated',
                    'requests' => self::PUBLIC_API_LIMITS['authenticated']['requests_per_minute'],
                    'period' => 'minute',
                    'burst' => [
                        'requests' => self::PUBLIC_API_LIMITS['authenticated']['requests_per_minute']
                            * self::PUBLIC_API_LIMITS['authenticated']['burst_allowance'],
                        'duration' => self::PUBLIC_API_LIMITS['authenticated']['burst_duration_seconds'],
                    ],
                    'daily_limit' => self::PUBLIC_API_LIMITS['authenticated']['daily_limit'],
                ],
                'partner_api' => [
                    'type' => 'partner',
                    'requests' => self::PARTNER_API_LIMITS['base']['requests_per_minute'],
                    'period' => 'minute',
                    'burst' => [
                        'requests' => self::PARTNER_API_LIMITS['base']['requests_per_minute']
                            * self::PARTNER_API_LIMITS['base']['burst_allowance'],
                        'duration' => self::PARTNER_API_LIMITS['base']['burst_duration_seconds'],
                    ],
                    'monthly_limit' => self::PARTNER_API_LIMITS['base']['monthly_limit'],
                    'concurrent_limit' => self::PARTNER_API_LIMITS['base']['concurrent_connections'],
                ],
            ],
        ];
    }
}
