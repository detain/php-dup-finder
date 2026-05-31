<?php

declare(strict_types=1);

namespace App\Domain\Shared\RateLimiting;

/**
 * Centralized rate limit configuration.
 * Single source of truth for all rate limit settings,
 * eliminating duplication across documentation, code, and dashboards.
 */
final class RateLimitConfig
{
    public const PUBLIC_ANONYMOUS_RPM = 100;
    public const PUBLIC_AUTHENTICATED_RPM = 1000;
    public const PARTNER_RPM = 5000;
    public const INTERNAL_SERVICE_RPM = 10000;

    public static function get(string $tier): array
    {
        return match ($tier) {
            'public_anonymous' => [
                'rpm' => self::PUBLIC_ANONYMOUS_RPM,
                'burst' => 1.5,
                'daily_limit' => 50000,
            ],
            'public_authenticated' => [
                'rpm' => self::PUBLIC_AUTHENTICATED_RPM,
                'burst' => 1.5,
                'daily_limit' => 50000,
            ],
            'partner' => [
                'rpm' => self::PARTNER_RPM,
                'burst' => 2.0,
                'daily_limit' => 1000000,
                'monthly_limit' => 30000000,
            ],
            'internal' => [
                'rpm' => self::INTERNAL_SERVICE_RPM,
                'daily_limit' => null,
            ],
            default => ['rpm' => 100],
        };
    }
}
