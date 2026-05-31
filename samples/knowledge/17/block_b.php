<?php
declare(strict_types=1);

namespace App\Config;

use Symfony\Component\Yaml\Yaml;

final class DiscountConfigLoader
{
    public const DEFAULT_STANDARD_DISCOUNT = 10.0;
    public const DEFAULT_PREMIUM_DISCOUNT = 15.0;
    public const DEFAULT_VIP_DISCOUNT = 20.0;

    public const DEFAULT_BULK_THRESHOLD = 10;
    public const DEFAULT_BULK_DISCOUNT = 5.0;

    public const DEFAULT_MIN_ORDER_FOR_DISCOUNT = 50.00;
    public const DEFAULT_MIN_ORDER_FREE_SHIPPING = 100.00;

    public const DEFAULT_REFERRAL_DISCOUNT = 10.00;
    public const DEFAULT_FIRST_ORDER_DISCOUNT = 15.0;

    private array $config;

    public function __construct(string $configPath)
    {
        $this->config = Yaml::parseFile($configPath);
    }

    public function getTierDiscountPercent(string $tier): float
    {
        $discounts = $this->config['discounts']['tier_discounts'] ?? [];

        return match ($tier) {
            'premium' => $discounts['premium'] ?? self::DEFAULT_PREMIUM_DISCOUNT,
            'vip' => $discounts['vip'] ?? self::DEFAULT_VIP_DISCOUNT,
            default => $discounts['standard'] ?? self::DEFAULT_STANDARD_DISCOUNT,
        };
    }

    public function getBulkDiscountConfig(): array
    {
        $bulk = $this->config['discounts']['bulk'] ?? [];

        return [
            'threshold' => $bulk['quantity_threshold'] ?? self::DEFAULT_BULK_THRESHOLD,
            'discount_percent' => $bulk['discount_percent'] ?? self::DEFAULT_BULK_DISCOUNT,
        ];
    }

    public function getMinimumOrderThresholds(): array
    {
        $thresholds = $this->config['discounts']['minimum_orders'] ?? [];

        return [
            'for_discount' => $thresholds['for_discount'] ?? self::DEFAULT_MIN_ORDER_FOR_DISCOUNT,
            'for_free_shipping' => $thresholds['for_free_shipping'] ?? self::DEFAULT_MIN_ORDER_FREE_SHIPPING,
        ];
    }

    public function getReferralDiscountAmount(): float
    {
        return $this->config['discounts']['referral']['amount']
            ?? self::DEFAULT_REFERRAL_DISCOUNT;
    }

    public function getFirstOrderDiscountPercent(): float
    {
        return $this->config['discounts']['first_order']['discount_percent']
            ?? self::DEFAULT_FIRST_ORDER_DISCOUNT;
    }

    public function getDiscountRules(): array
    {
        return [
            'tier_discounts' => [
                'standard' => $this->getTierDiscountPercent('standard'),
                'premium' => $this->getTierDiscountPercent('premium'),
                'vip' => $this->getTierDiscountPercent('vip'),
            ],
            'bulk' => $this->getBulkDiscountConfig(),
            'minimum_orders' => $this->getMinimumOrderThresholds(),
            'referral' => [
                'amount' => $this->getReferralDiscountAmount(),
            ],
            'first_order' => [
                'discount_percent' => $this->getFirstOrderDiscountPercent(),
                'min_order_amount' => $this->config['discounts']['first_order']['min_order_amount'] ?? 30.00,
            ],
        ];
    }

    public function calculateTierDiscount(float $subtotal, string $customerTier): float
    {
        $thresholds = $this->getMinimumOrderThresholds();

        if ($subtotal < $thresholds['for_discount']) {
            return 0.0;
        }

        $discountPercent = $this->getTierDiscountPercent($customerTier);
        return round($subtotal * ($discountPercent / 100), 2);
    }

    public function calculateBulkDiscount(int $totalQuantity, float $subtotal): float
    {
        $bulkConfig = $this->getBulkDiscountConfig();

        if ($totalQuantity < $bulkConfig['threshold']) {
            return 0.0;
        }

        return round($subtotal * ($bulkConfig['discount_percent'] / 100), 2);
    }

    public function isFreeShippingEligible(float $subtotal): bool
    {
        $thresholds = $this->getMinimumOrderThresholds();
        return $subtotal >= $thresholds['for_free_shipping'];
    }
}
