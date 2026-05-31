<?php

declare(strict_types=1);

namespace App\Domain\Shared;

/**
 * Centralized policy rules with single source of truth.
 * All business rules should be defined here and referenced from
 * domain entities and services, avoiding duplication.
 */
final class PolicyRegistry
{
    /**
     * Refund policy time windows in days.
     */
    public const REFUND_FULL_WINDOW_DAYS = 30;
    public const REFUND_PARTIAL_WINDOW_DAYS = 60;
    public const REFUND_RETURN_WINDOW_DAYS = 14;

    /**
     * Shipping weight limits in pounds.
     */
    public const SHIPPING_MAX_STANDARD_LBS = 50;
    public const SHIPPING_MAX_EXPRESS_LBS = 25;
    public const SHIPPING_MAX_OVERNIGHT_LBS = 15;
    public const SHIPPING_MAX_PACKAGE_LBS = 70;

    /**
     * Discount tiers and thresholds.
     */
    public const TIER_THRESHOLDS = [
        'bronze' => 0,
        'silver' => 500,
        'gold' => 2000,
        'platinum' => 5000,
    ];

    /**
     * Get a specific policy value by key.
     */
    public static function get(string $policy, mixed $default = null): mixed
    {
        return match ($policy) {
            'refund.full_window' => self::REFUND_FULL_WINDOW_DAYS,
            'refund.partial_window' => self::REFUND_PARTIAL_WINDOW_DAYS,
            'shipping.max_standard' => self::SHIPPING_MAX_STANDARD_LBS,
            default => $default,
        };
    }
}
