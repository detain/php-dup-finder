<?php

declare(strict_types=1);

namespace App\Admin\Dashboard\Components;

/**
 * Rate Limit Admin Dashboard Component
 *
 * Displays rate limit configuration and usage in admin dashboard.
 * This information is duplicated from:
 * - Developer portal documentation
 * - API SDK rate limiting modules
 * - Configuration files
 *
 * DASHBOARD SECTIONS:
 *
 * OVERVIEW SECTION:
 * - Total API requests (today/week/month)
 * - Requests blocked by rate limiting
 * - Top consumers by request volume
 * - Average rate limit utilization percentage
 *
 * TIER CONFIGURATION SECTION:
 * Display current rate limit tiers with their parameters.
 *
 * FREE TIER:
 * - name: Free
 * - requests_per_minute: 60
 * - requests_per_hour: 1,000
 * - requests_per_day: 10,000
 * - burst_allowance: 1.2x
 * - burst_duration_seconds: 5
 *
 * BASIC TIER:
 * - name: Basic
 * - requests_per_minute: 300
 * - requests_per_hour: 10,000
 * - requests_per_day: 100,000
 * - burst_allowance: 1.5x
 * - burst_duration_seconds: 10
 * - monthly_price: $29
 *
 * PROFESSIONAL TIER:
 * - name: Professional
 * - requests_per_minute: 1,000
 * - requests_per_hour: 50,000
 * - requests_per_day: 500,000
 * - burst_allowance: 2.0x
 * - burst_duration_seconds: 15
 * - monthly_price: $99
 *
 * ENTERPRISE TIER:
 * - name: Enterprise
 * - requests_per_minute: 5,000
 * - requests_per_hour: Custom
 * - requests_per_day: Custom
 * - burst_allowance: 3.0x
 * - burst_duration_seconds: 30
 * - monthly_price: Custom
 *
 * PER-ENDPOINT LIMITS SECTION:
 * - Authentication endpoints: 5-10 req/min (stricter)
 * - Data retrieval: Standard tier limits
 * - Search: 30% of standard limits
 * - Export: 10% of standard limits
 * - Data modification: 50% of standard limits
 * - Deletion: 25% of standard limits
 *
 * USAGE STATISTICS SECTION:
 * - Current active users per tier
 * - Requests per tier (today)
 * - Blocked requests per tier
 * - Average remaining quota per tier
 *
 * RECENT VIOLATIONS SECTION:
 * - List of recent rate limit violations
 * - Timestamp, user, tier, endpoint, limit hit
 * - Option to whitelist or adjust limits
 *
 * DOCUMENTED IN:
 * - docs/admin/rate-limit-dashboard.md
 * - developer.example.com/api/rate-limits
 * - Confluence: ADMIN-DOCS-012
 */
class RateLimitDashboardComponent
{
    public const TIER_DISPLAY_CONFIG = [
        'free' => [
            'name' => 'Free',
            'rpm' => 60,
            'rph' => 1000,
            'rpd' => 10000,
            'burst_multiplier' => 1.2,
            'burst_duration' => 5,
        ],
        'basic' => [
            'name' => 'Basic',
            'rpm' => 300,
            'rph' => 10000,
            'rpd' => 100000,
            'burst_multiplier' => 1.5,
            'burst_duration' => 10,
            'price' => '$29/month',
        ],
        'professional' => [
            'name' => 'Professional',
            'rpm' => 1000,
            'rph' => 50000,
            'rpd' => 500000,
            'burst_multiplier' => 2.0,
            'burst_duration' => 15,
            'price' => '$99/month',
        ],
        'enterprise' => [
            'name' => 'Enterprise',
            'rpm' => 5000,
            'rph' => 'Custom',
            'rpd' => 'Custom',
            'burst_multiplier' => 3.0,
            'burst_duration' => 30,
            'price' => 'Contact sales',
        ],
    ];

    public const ENDPOINT_LIMIT_CONFIG = [
        'Authentication' => [
            '/auth/login' => ['rpm' => 10, 'reason' => 'Security - prevent brute force'],
            '/auth/register' => ['rpm' => 5, 'reason' => 'Prevent spam registration'],
            '/auth/password/reset' => ['rpm' => 3, 'reason' => 'Security - prevent abuse'],
            '/auth/2fa/verify' => ['rpm' => 5, 'reason' => 'Security - rate limit 2FA attempts'],
        ],
        'Data Retrieval' => [
            '/api/* (GET)' => ['multiplier' => 1.0, 'reason' => 'Standard tier limits'],
            '/api/search' => ['multiplier' => 0.3, 'reason' => 'Expensive operation'],
            '/api/export' => ['multiplier' => 0.1, 'reason' => 'Resource intensive'],
        ],
        'Data Modification' => [
            '/api/* (POST)' => ['multiplier' => 0.5, 'reason' => 'Prevent spam/submission abuse'],
            '/api/* (PUT)' => ['multiplier' => 0.5, 'reason' => 'Prevent excessive updates'],
            '/api/* (DELETE)' => ['multiplier' => 0.25, 'reason' => 'Critical operations'],
        ],
    ];

    public function getTierConfig(string $tier): array
    {
        return self::TIER_DISPLAY_CONFIG[$tier] ?? self::TIER_DISPLAY_CONFIG['free'];
    }

    public function getEndpointLimit(string $endpoint): ?array
    {
        foreach (self::ENDPOINT_LIMIT_CONFIG as $category => $endpoints) {
            foreach ($endpoints as $path => $config) {
                if ($this->matchesEndpoint($path, $endpoint)) {
                    return $config;
                }
            }
        }
        return null;
    }

    private function matchesEndpoint(string $pattern, string $endpoint): bool
    {
        $pattern = str_replace('*', '.*', $pattern);
        return (bool) preg_match("#^{$pattern}$#", $endpoint);
    }

    public function calculateEffectiveLimit(string $tier, string $endpoint): int
    {
        $tierConfig = $this->getTierConfig($tier);
        $endpointConfig = $this->getEndpointLimit($endpoint);

        $baseLimit = $tierConfig['rpm'];
        $multiplier = $endpointConfig['multiplier'] ?? 1.0;

        return (int) floor($baseLimit * $multiplier);
    }
}
