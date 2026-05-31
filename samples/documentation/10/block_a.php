<?php

declare(strict_types=1);

namespace App\Api\Documentation;

use OpenApi\Annotations as OA;

/**
 * API Rate Limiting Documentation
 *
 * This file documents the rate limiting structure for the API.
 * The same information is duplicated in:
 * - Developer portal: developer.example.com/rate-limits
 * - Admin dashboard: admin.example.com/settings/rate-limits
 * - SDK implementations: sdk-php, sdk-node, sdk-python
 * - Internal wiki: confluence.io/RATE-LIMITS
 *
 * RATE LIMIT TIERS:
 *
 * FREE TIER:
 * - Requests per minute: 60
 * - Requests per hour: 1000
 * - Requests per day: 10000
 * - Concurrent requests: 5
 * - Burst allowance: 1.2x for 5 seconds
 * - Quota resets at: Midnight UTC
 *
 * BASIC TIER:
 * - Requests per minute: 300
 * - Requests per hour: 10000
 * - Requests per day: 100000
 * - Concurrent requests: 20
 * - Burst allowance: 1.5x for 10 seconds
 * - Quota resets at: Midnight UTC
 *
 * PROFESSIONAL TIER:
 * - Requests per minute: 1000
 * - Requests per hour: 50000
 * - Requests per day: 500000
 * - Concurrent requests: 50
 * - Burst allowance: 2.0x for 15 seconds
 * - Quota resets at: Midnight UTC
 *
 * ENTERPRISE TIER:
 * - Requests per minute: 5000
 * - Requests per hour: Custom
 * - Requests per day: Custom (min 1M, max unlimited)
 * - Concurrent requests: 200
 * - Burst allowance: 3.0x for 30 seconds
 * - Quota resets at: Monthly (1st of month)
 *
 * RATE LIMIT BY ENDPOINT CATEGORY:
 *
 * Authentication Endpoints:
 * - /auth/login: 10 req/min (stricter for security)
 * - /auth/register: 5 req/min
 * - /auth/password/reset: 3 req/min
 * - /auth/2fa/verify: 5 req/min
 *
 * Data Retrieval Endpoints:
 * - /api/v1/* (GET): Standard tier limits
 * - /api/v1/search: 30% of standard limits
 * - /api/v1/export: 10% of standard limits
 *
 * Data Modification Endpoints:
 * - /api/v1/* (POST): 50% of standard limits
 * - /api/v1/* (PUT): 50% of standard limits
 * - /api/v1/* (DELETE): 25% of standard limits
 *
 * Webhook Endpoints:
 * - Delivery rate: 1000/min per endpoint
 * - Retry attempts: 5 with exponential backoff
 * - Timeout: 30 seconds per delivery
 *
 * RESPONSE HEADERS (documented in API spec):
 * - X-RateLimit-Limit: Current limit
 * - X-RateLimit-Remaining: Remaining requests
 * - X-RateLimit-Reset: Unix timestamp
 * - X-RateLimit-Reset-After: Seconds until reset
 *
 * See also: docs/api/rate-limiting.md and JIRA API-234
 */

/**
 * @OA\Tag(name="Rate Limits", description="API rate limiting information")
 */
class RateLimitDocumentation
{
    public const FREE_TIER = [
        'requests_per_minute' => 60,
        'requests_per_hour' => 1000,
        'requests_per_day' => 10000,
        'concurrent_requests' => 5,
        'burst_allowance' => 1.2,
        'burst_duration_seconds' => 5,
    ];

    public const BASIC_TIER = [
        'requests_per_minute' => 300,
        'requests_per_hour' => 10000,
        'requests_per_day' => 100000,
        'concurrent_requests' => 20,
        'burst_allowance' => 1.5,
        'burst_duration_seconds' => 10,
    ];

    public const PROFESSIONAL_TIER = [
        'requests_per_minute' => 1000,
        'requests_per_hour' => 50000,
        'requests_per_day' => 500000,
        'concurrent_requests' => 50,
        'burst_allowance' => 2.0,
        'burst_duration_seconds' => 15,
    ];

    public const ENTERPRISE_TIER = [
        'requests_per_minute' => 5000,
        'requests_per_hour' => null,
        'requests_per_day' => null,
        'concurrent_requests' => 200,
        'burst_allowance' => 3.0,
        'burst_duration_seconds' => 30,
    ];

    public const ENDPOINT_RATE_LIMITS = [
        'auth.login' => ['requests_per_minute' => 10],
        'auth.register' => ['requests_per_minute' => 5],
        'auth.password_reset' => ['requests_per_minute' => 3],
        'auth.2fa_verify' => ['requests_per_minute' => 5],
        'data.search' => ['multiplier' => 0.3],
        'data.export' => ['multiplier' => 0.1],
        'data.create' => ['multiplier' => 0.5],
        'data.update' => ['multiplier' => 0.5],
        'data.delete' => ['multiplier' => 0.25],
        'webhooks.delivery' => ['requests_per_minute' => 1000],
    ];

    public const RESPONSE_HEADERS = [
        'X-RateLimit-Limit' => 'Current rate limit',
        'X-RateLimit-Remaining' => 'Remaining requests in window',
        'X-RateLimit-Reset' => 'Unix timestamp when limit resets',
        'X-RateLimit-Reset-After' => 'Seconds until rate limit resets',
    ];
}
