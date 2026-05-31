<?php
declare(strict_types=1);

namespace App\Ecommerce\Policy;

final class DiscountPolicy
{
    public function __construct(
        public readonly float $standardDiscount = 10.0,
        public readonly float $premiumDiscount = 15.0,
        public readonly float $vipDiscount = 20.0,
        public readonly int $bulkThreshold = 10,
        public readonly float $bulkDiscount = 5.0,
        public readonly float $minOrderForDiscount = 50.00,
        public readonly float $minOrderForFreeShipping = 100.00,
        public readonly float $referralDiscount = 10.00,
        public readonly float $firstOrderDiscountPercent = 15.0,
        public readonly float $firstOrderMinAmount = 30.00
    ) {}

    public static function fromConfig(array $config): self
    {
        $d = $config['discounts'] ?? [];

        return new self(
            standardDiscount: $d['standard'] ?? 10.0,
            premiumDiscount: $d['premium'] ?? 15.0,
            vipDiscount: $d['vip'] ?? 20.0,
            bulkThreshold: $d['bulk']['threshold'] ?? 10,
            bulkDiscount: $d['bulk']['discount'] ?? 5.0,
            minOrderForDiscount: $d['min_order_for_discount'] ?? 50.00,
            minOrderForFreeShipping: $d['min_order_free_shipping'] ?? 100.00,
            referralDiscount: $d['referral'] ?? 10.00,
            firstOrderDiscountPercent: $d['first_order'] ?? 15.0,
            firstOrderMinAmount: $d['first_order_min'] ?? 30.00
        );
    }

    public function getTierDiscountPercent(string $tier): float
    {
        return match ($tier) {
            'premium' => $this->premiumDiscount,
            'vip' => $this->vipDiscount,
            default => $this->standardDiscount
        };
    }

    public function calculateTierDiscount(float $subtotal, string $tier): float
    {
        if ($subtotal < $this->minOrderForDiscount) {
            return 0.0;
        }

        $percent = $this->getTierDiscountPercent($tier);
        return round($subtotal * ($percent / 100), 2);
    }

    public function calculateBulkDiscount(int $quantity, float $subtotal): float
    {
        if ($quantity < $this->bulkThreshold) {
            return 0.0;
        }

        return round($subtotal * ($this->bulkDiscount / 100), 2);
    }

    public function isFreeShippingEligible(float $subtotal): bool
    {
        return $subtotal >= $this->minOrderForFreeShipping;
    }
}
