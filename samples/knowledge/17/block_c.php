<?php
declare(strict_types=1);

namespace App\Rules\Discount;

final class DiscountRules
{
    public const TIER_STANDARD = 'standard';
    public const TIER_PREMIUM = 'premium';
    public const TIER_VIP = 'vip';

    public const DISCOUNT_PERCENT_STANDARD = 10.0;
    public const DISCOUNT_PERCENT_PREMIUM = 15.0;
    public const DISCOUNT_PERCENT_VIP = 20.0;

    public const BULK_QUANTITY_THRESHOLD = 10;
    public const BULK_DISCOUNT_PERCENT = 5.0;

    public const MIN_ORDER_AMOUNT_FOR_DISCOUNT = 50.00;
    public const MIN_ORDER_AMOUNT_FOR_FREE_SHIPPING = 100.00;

    public const REFERRAL_DISCOUNT_AMOUNT = 10.00;
    public const FIRST_ORDER_DISCOUNT_PERCENT = 15.0;
    public const FIRST_ORDER_MIN_AMOUNT = 30.00;

    private const TIER_DISCOUNTS = [
        self::TIER_STANDARD => self::DISCOUNT_PERCENT_STANDARD,
        self::TIER_PREMIUM => self::DISCOUNT_PERCENT_PREMIUM,
        self::TIER_VIP => self::DISCOUNT_PERCENT_VIP,
    ];

    public function __construct(
        private readonly array $customTierDiscounts = [],
        private readonly array $customBulkConfig = [],
        private readonly array $customThresholds = []
    ) {}

    public function getTierDiscountPercent(string $tier): float
    {
        return $this->customTierDiscounts[$tier]
            ?? self::TIER_DISCOUNTS[$tier]
            ?? self::DISCOUNT_PERCENT_STANDARD;
    }

    public function getBulkQuantityThreshold(): int
    {
        return $this->customBulkConfig['quantity_threshold']
            ?? self::BULK_QUANTITY_THRESHOLD;
    }

    public function getBulkDiscountPercent(): float
    {
        return $this->customBulkConfig['discount_percent']
            ?? self::BULK_DISCOUNT_PERCENT;
    }

    public function getMinimumOrderForDiscount(): float
    {
        return $this->customThresholds['min_order_for_discount']
            ?? self::MIN_ORDER_AMOUNT_FOR_DISCOUNT;
    }

    public function getMinimumOrderForFreeShipping(): float
    {
        return $this->customThresholds['min_order_for_free_shipping']
            ?? self::MIN_ORDER_AMOUNT_FOR_FREE_SHIPPING;
    }

    public function getReferralDiscountAmount(): float
    {
        return $this->customTierDiscounts['referral_amount']
            ?? self::REFERRAL_DISCOUNT_AMOUNT;
    }

    public function getFirstOrderDiscountPercent(): float
    {
        return $this->customTierDiscounts['first_order_discount']
            ?? self::FIRST_ORDER_DISCOUNT_PERCENT;
    }

    public function getFirstOrderMinimumAmount(): float
    {
        return $this->customThresholds['first_order_min_amount']
            ?? self::FIRST_ORDER_MIN_AMOUNT;
    }

    public function calculateTierDiscount(float $subtotal, string $tier): float
    {
        if ($subtotal < $this->getMinimumOrderForDiscount()) {
            return 0.0;
        }

        $percent = $this->getTierDiscountPercent($tier);
        return round($subtotal * ($percent / 100), 2);
    }

    public function calculateBulkDiscount(int $totalQuantity, float $subtotal): float
    {
        if ($totalQuantity < $this->getBulkQuantityThreshold()) {
            return 0.0;
        }

        $percent = $this->getBulkDiscountPercent();
        return round($subtotal * ($percent / 100), 2);
    }

    public function isFreeShippingEligible(float $subtotal): bool
    {
        return $subtotal >= $this->getMinimumOrderForFreeShipping();
    }

    public function calculateFirstOrderDiscount(float $subtotal, int $previousOrderCount): float
    {
        if ($previousOrderCount > 0) {
            return 0.0;
        }

        if ($subtotal < $this->getFirstOrderMinimumAmount()) {
            return 0.0;
        }

        $percent = $this->getFirstOrderDiscountPercent();
        return round($subtotal * ($percent / 100), 2);
    }

    public function getAllRules(): array
    {
        return [
            'tier_discounts' => [
                'standard' => $this->getTierDiscountPercent(self::TIER_STANDARD),
                'premium' => $this->getTierDiscountPercent(self::TIER_PREMIUM),
                'vip' => $this->getTierDiscountPercent(self::TIER_VIP),
            ],
            'bulk' => [
                'quantity_threshold' => $this->getBulkQuantityThreshold(),
                'discount_percent' => $this->getBulkDiscountPercent(),
            ],
            'minimums' => [
                'for_discount' => $this->getMinimumOrderForDiscount(),
                'for_free_shipping' => $this->getMinimumOrderForFreeShipping(),
            ],
            'referral' => [
                'amount' => $this->getReferralDiscountAmount(),
            ],
            'first_order' => [
                'discount_percent' => $this->getFirstOrderDiscountPercent(),
                'min_amount' => $this->getFirstOrderMinimumAmount(),
            ],
        ];
    }
}
