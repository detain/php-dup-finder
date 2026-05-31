<?php

declare(strict_types=1);

namespace App\Domain\Shared\RateLimiting;

/**
 * Centralized rate limit configuration.
 * Single source of truth for all rate limit settings,
 * eliminating duplication across documentation, dashboards, and SDKs.
 */
final class CentralizedRateLimitConfig
{
    private static array $tiers = [];
    private static array $endpoints = [];

    public static function registerTier(string $tier, array $config): void
    {
        self::$tiers[$tier] = $config;
    }

    public static function registerEndpoint(string $pattern, array $config): void
    {
        self::$endpoints[$pattern] = $config;
    }

    public static function getTier(string $tier): array
    {
        return self::$tiers[$tier] ?? self::$tiers['free'];
    }

    public static function getEndpointLimit(string $endpoint): ?array
    {
        foreach (self::$endpoints as $pattern => $config) {
            if (fnmatch($pattern, $endpoint)) {
                return $config;
            }
        }
        return null;
    }

    public static function calculateEffectiveLimit(string $tier, string $endpoint): int
    {
        $tierConfig = self::getTier($tier);
        $endpointConfig = self::getEndpointLimit($endpoint);

        $baseLimit = $tierConfig['rpm'] ?? 60;
        $multiplier = $endpointConfig['multiplier'] ?? 1.0;

        return (int) floor($baseLimit * $multiplier);
    }
}
