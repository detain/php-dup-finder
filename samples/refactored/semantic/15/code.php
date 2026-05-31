<?php
declare(strict_types=1);

namespace Pricing\Shared;

interface PricingModifier
{
    public function isApplicable(PricingContext $context): bool;
    public function getModifierCode(): string;
    public function getDiscountPercentage(): float;
    public function getRejectionReason(): string;
}

abstract class BasePricingModifier implements PricingModifier
{
    protected const DISCOUNT_RATE = 0;
    protected const MINIMUM_THRESHOLD = 0;

    public function isApplicable(PricingContext $context): bool
    {
        return $this->checkThreshold($context);
    }

    abstract protected function checkThreshold(PricingContext $context): bool;

    public function getDiscountPercentage(): float
    {
        return static::DISCOUNT_RATE;
    }

    public function getRejectionReason(): string
    {
        return 'threshold_not_met';
    }
}

final class SeasonalModifier extends BasePricingModifier
{
    protected const DISCOUNT_RATE = 15;

    private const ACTIVE_MONTHS = [11, 12, 1, 6, 7, 8];

    protected function checkThreshold(PricingContext $context): bool
    {
        $month = (int) date('n', $context->getOrderDate()->getTimestamp());
        return in_array($month, self::ACTIVE_MONTHS);
    }

    public function getModifierCode(): string
    {
        return 'SEASONAL';
    }
}

final class LoyaltyModifier extends BasePricingModifier
{
    protected const DISCOUNT_RATE = 10;
    private const MINIMUM_MONTHS = 12;
    private const GOLD_BONUS = 5;
    private const SILVER_BONUS = 2;

    protected function checkThreshold(PricingContext $context): bool
    {
        return $context->getCustomerTenureMonths() >= self::MINIMUM_MONTHS;
    }

    public function getDiscountPercentage(): float
    {
        $tenure = $this->context->getCustomerTenureMonths();

        if ($tenure >= 48) {
            return self::DISCOUNT_RATE + self::GOLD_BONUS;
        }
        if ($tenure >= 24) {
            return self::DISCOUNT_RATE + self::SILVER_BONUS;
        }
        return self::DISCOUNT_RATE;
    }

    public function getModifierCode(): string
    {
        return 'LOYALTY';
    }
}

class UnifiedPricingEngine
{
    private const MAX_COMBINED_DISCOUNT = 40.0;

    public function calculateAdjustedPrice(PricingContext $context): AdjustedPriceResult
    {
        $modifiers = [];
        $rejected = [];

        foreach ($this->getAvailableModifiers() as $modifier) {
            if ($modifier->isApplicable($context)) {
                $modifiers[] = $modifier;
            } else {
                $rejected[] = $modifier->getRejectionReason();
            }
        }

        $totalDiscount = $this->sumDiscounts($modifiers);
        $cappedDiscount = min($totalDiscount, self::MAX_COMBINED_DISCOUNT);
        $discountAmount = $context->getBasePrice() * ($cappedDiscount / 100);

        return new AdjustedPriceResult(
            finalPrice: $context->getBasePrice() - $discountAmount,
            discountPercent: $cappedDiscount,
            appliedModifiers: array_map(fn($m) => $m->getModifierCode(), $modifiers),
            rejectedReasons: $rejected,
        );
    }

    private function sumDiscounts(array $modifiers): float
    {
        return array_reduce(
            $modifiers,
            fn($sum, $m) => $sum + $m->getDiscountPercentage(),
            0.0
        );
    }

    protected function getAvailableModifiers(): array
    {
        return [];
    }
}
