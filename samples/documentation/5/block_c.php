<?php

declare(strict_types=1);

namespace App\Admin\Dashboard\ReadModels;

/**
 * Rate Limit Admin Dashboard View Model
 *
 * Displays current rate limit configuration and usage statistics.
 * This information is duplicated from the developer portal documentation,
 * API implementation code comments, and internal configuration files.
 *
 * CONFIGURATION SECTIONS:
 *
 * PUBLIC API SECTION:
 * - Anonymous tier: 100 req/min, 150% burst for 10s, 50k daily
 * - Authenticated tier: 1000 req/min, 150% burst for 10s, 50k daily
 * - Rate limit window: Sliding 1-minute window
 * - Quota resets: Daily at midnight UTC
 *
 * PARTNER API SECTION:
 * - Base rate: 5000 req/min, 200% burst for 30s
 * - Daily quota: 1,000,000 requests
 * - Monthly quota: 30,000,000 requests
 * - Concurrent connection limit: 100 per partner
 * - Rate limit window: Sliding 1-minute window
 *
 * INTERNAL API SECTION:
 * - Service-to-service: 10,000 req/min (no daily limits)
 * - Admin endpoints: 500 req/min
 * - Webhook delivery: 1,000 deliveries/min
 * - Rate limit window: Fixed window for internal services
 *
 * USAGE METRICS DISPLAYED:
 * - Current request rate (requests per minute)
 * - Remaining quota in current window
 * - Reset time for current window
 * - Daily/monthly usage vs limits
 * - Top consumers by request volume
 * - Rate limit events (throttle activations)
 *
 * ALERT THRESHOLDS (per ops runbook OPS-2024-034):
 * - Warning: 80% of limit reached
 * - Critical: 95% of limit reached
 * - Exhausted: 100% of limit reached
 *
 * See also: docs/admin/rate-limit-dashboard.md and Confluence DOC-ADMIN-012
 */
class RateLimitDashboardViewModel
{
    public const DEFAULT_LIMITS = [
        'public_api_anonymous' => [
            'requests_per_minute' => 100,
            'burst_allowance' => 1.5,
            'burst_duration_seconds' => 10,
            'daily_limit' => 50000,
        ],
        'public_api_authenticated' => [
            'requests_per_minute' => 1000,
            'burst_allowance' => 1.5,
            'burst_duration_seconds' => 10,
            'daily_limit' => 50000,
        ],
        'partner_api' => [
            'requests_per_minute' => 5000,
            'burst_allowance' => 2.0,
            'burst_duration_seconds' => 30,
            'daily_limit' => 1000000,
            'monthly_limit' => 30000000,
            'concurrent_connections' => 100,
        ],
        'internal_service' => [
            'requests_per_minute' => 10000,
            'daily_limit' => null,
            'monthly_limit' => null,
        ],
        'internal_admin' => [
            'requests_per_minute' => 500,
            'daily_limit' => null,
            'monthly_limit' => null,
        ],
    ];

    private const ALERT_THRESHOLDS = [
        'warning' => 0.8,
        'critical' => 0.95,
        'exhausted' => 1.0,
    ];

    /**
     * Generate dashboard data for public API rate limits section.
     * This mirrors the documentation at developer.example.com/api/rate-limits
     */
    public function getPublicApiData(): array
    {
        return [
            'title' => 'Public API Rate Limits',
            'description' => 'Rate limits for public API access',
            'limits' => [
                'anonymous' => [
                    'requests_per_minute' => self::DEFAULT_LIMITS['public_api_anonymous']['requests_per_minute'],
                    'burst_rate' => self::DEFAULT_LIMITS['public_api_anonymous']['requests_per_minute']
                        * self::DEFAULT_LIMITS['public_api_anonymous']['burst_allowance'],
                    'burst_duration_seconds' => self::DEFAULT_LIMITS['public_api_anonymous']['burst_duration_seconds'],
                    'daily_limit' => self::DEFAULT_LIMITS['public_api_anonymous']['daily_limit'],
                    'window_type' => 'sliding',
                ],
                'authenticated' => [
                    'requests_per_minute' => self::DEFAULT_LIMITS['public_api_authenticated']['requests_per_minute'],
                    'burst_rate' => self::DEFAULT_LIMITS['public_api_authenticated']['requests_per_minute']
                        * self::DEFAULT_LIMITS['public_api_authenticated']['burst_allowance'],
                    'burst_duration_seconds' => self::DEFAULT_LIMITS['public_api_authenticated']['burst_duration_seconds'],
                    'daily_limit' => self::DEFAULT_LIMITS['public_api_authenticated']['daily_limit'],
                    'window_type' => 'sliding',
                ],
            ],
            'response_headers' => [
                'X-RateLimit-Limit' => 'Maximum requests allowed in window',
                'X-RateLimit-Remaining' => 'Requests remaining in current window',
                'X-RateLimit-Reset' => 'Unix timestamp when window resets',
                'Retry-After' => 'Seconds to wait before retrying (on 429)',
            ],
        ];
    }

    /**
     * Generate dashboard data for partner API rate limits section.
     * This mirrors the documentation in the partner portal.
     */
    public function getPartnerApiData(): array
    {
        return [
            'title' => 'Partner API Rate Limits',
            'description' => 'Rate limits for partner API access (defined in partnership agreements)',
            'limits' => [
                'base' => [
                    'requests_per_minute' => self::DEFAULT_LIMITS['partner_api']['requests_per_minute'],
                    'burst_rate' => self::DEFAULT_LIMITS['partner_api']['requests_per_minute']
                        * self::DEFAULT_LIMITS['partner_api']['burst_allowance'],
                    'burst_duration_seconds' => self::DEFAULT_LIMITS['partner_api']['burst_duration_seconds'],
                    'daily_limit' => self::DEFAULT_LIMITS['partner_api']['daily_limit'],
                    'monthly_limit' => self::DEFAULT_LIMITS['partner_api']['monthly_limit'],
                    'concurrent_connections' => self::DEFAULT_LIMITS['partner_api']['concurrent_connections'],
                    'window_type' => 'sliding',
                ],
            ],
            'sla' => [
                'uptime_target' => '99.9%',
                'rate_limit_violation_compensation' => 'API call credits per incident',
            ],
        ];
    }

    /**
     * Generate dashboard data for internal API rate limits section.
     * This mirrors the documentation in the internal security policy.
     */
    public function getInternalApiData(): array
    {
        return [
            'title' => 'Internal API Rate Limits',
            'description' => 'Rate limits for internal service-to-service communication',
            'limits' => [
                'service_to_service' => [
                    'requests_per_minute' => self::DEFAULT_LIMITS['internal_service']['requests_per_minute'],
                    'daily_limit' => 'No limit',
                    'monthly_limit' => 'No limit',
                    'window_type' => 'fixed',
                ],
                'admin_endpoints' => [
                    'requests_per_minute' => self::DEFAULT_LIMITS['internal_admin']['requests_per_minute'],
                    'daily_limit' => 'No limit',
                    'monthly_limit' => 'No limit',
                    'window_type' => 'sliding',
                ],
            ],
            'bypass_conditions' => [
                'Requests with X-Internal-Service header',
                'Health check endpoints (GET /health, /ready)',
                'Webhook POST endpoints',
            ],
        ];
    }

    /**
     * Get current usage statistics for a given tier.
     * Used for dashboard gauges and alert indicators.
     */
    public function getUsageStatistics(string $tier): array
    {
        $limits = self::DEFAULT_LIMITS[$tier] ?? self::DEFAULT_LIMITS['public_api_anonymous'];
        $primaryLimit = $limits['requests_per_minute'] ?? 100;

        $currentUsage = $this->fetchCurrentUsage($tier);

        $utilization = $currentUsage / $primaryLimit;

        return [
            'tier' => $tier,
            'current_usage' => $currentUsage,
            'limit' => $primaryLimit,
            'remaining' => max(0, $primaryLimit - $currentUsage),
            'utilization_percentage' => round($utilization * 100, 2),
            'alert_level' => $this->determineAlertLevel($utilization),
            'reset_at' => $this->calculateResetTime(),
        ];
    }

    /**
     * Determine alert level based on utilization.
     * Thresholds are documented in the ops runbook.
     */
    private function determineAlertLevel(float $utilization): string
    {
        if ($utilization >= self::ALERT_THRESHOLDS['exhausted']) {
            return 'exhausted';
        }
        if ($utilization >= self::ALERT_THRESHOLDS['critical']) {
            return 'critical';
        }
        if ($utilization >= self::ALERT_THRESHOLDS['warning']) {
            return 'warning';
        }
        return 'normal';
    }

    private function fetchCurrentUsage(string $tier): int
    {
        return rand(50, 500);
    }

    private function calculateResetTime(): \DateTimeImmutable
    {
        $now = new \DateTimeImmutable();
        return $now->modify('+1 minute')->setTime((int)$now->format('H'), (int)$now->format('i'), 0);
    }
}
