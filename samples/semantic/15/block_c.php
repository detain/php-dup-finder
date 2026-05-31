<?php
declare(strict_types=1);

namespace Pricing\Rules;

final class PricingModifierEngine
{
    private const THRESHOLD_WHOLESALE_ORDER = 1000;
    private const THRESHOLD_VOLUME_ITEMS = 50;
    private const THRESHOLD_TENURE_MONTHS = 6;

    private const TENURE_BRONZE_MONTHS = 12;
    private const TENURE_SILVER_MONTHS = 24;
    private const TENURE_GOLD_MONTHS = 48;

    private const RATE_SEASONAL_HOLIDAY = 15;
    private const RATE_SEASONAL_SUMMER = 15;
    private const RATE_LOYALTY_BASE = 10;
    private const RATE_VOLUME = 20;
    private const RATE_WHOLESALE = 25;

    public function calculateAdjustedPrice(
        PricingContext $context,
        PriceCalculationRequest $request
    ): AdjustedPriceResult {
        $modifiers = [];
        $rejectedModifiers = [];

        $seasonalAdjustment = $this->applySeasonalModifier($request->getOrderDate());
        if ($seasonalAdjustment->applied) {
            $modifiers[] = $seasonalAdjustment->modifierCode;
        } else {
            $rejectedModifiers[] = $seasonalAdjustment->rejectionReason;
        }

        $tenureAdjustment = $this->applyTenureModifier($context->getCustomerTenureMonths());
        if ($tenureAdjustment->applied) {
            $modifiers[] = $tenureAdjustment->modifierCode;
        } else {
            $rejectedModifiers[] = $tenureAdjustment->rejectionReason;
        }

        $quantityAdjustment = $this->applyQuantityModifier($request->getTotalUnits());
        if ($quantityAdjustment->applied) {
            $modifiers[] = $quantityAdjustment->modifierCode;
        } else {
            $rejectedModifiers[] = $quantityAdjustment->rejectionReason;
        }

        $orderSizeAdjustment = $this->applyOrderSizeModifier($request->getOrderTotal());
        if ($orderSizeAdjustment->applied) {
            $modifiers[] = $orderSizeAdjustment->modifierCode;
        } else {
            $rejectedModifiers[] = $orderSizeAdjustment->rejectionReason;
        }

        $combinedModifierPercent = $this->sumModifierPercentages($modifiers);
        $cappedModifierPercent = $this->enforceMaximumModifier($combinedModifierPercent);
        $finalDiscountAmount = $this->computeDiscount($request->getOrderTotal(), $cappedModifierPercent);

        return new AdjustedPriceResult(
            appliedModifiers: $modifiers,
            combinedDiscountPercent: $cappedModifierPercent,
            discountAmount: $finalDiscountAmount,
            finalPrice: $request->getOrderTotal() - $finalDiscountAmount,
            rejectedModifiers: $rejectedModifiers,
        );
    }

    private function applySeasonalModifier(\DateTimeInterface $orderDate): ModifierResult
    {
        $month = (int) date('n', $orderDate->getTimestamp());

        $seasonalMonths = [11, 12, 1, 6, 7, 8];
        if (in_array($month, $seasonalMonths)) {
            return new ModifierResult(
                applied: true,
                modifierCode: 'SEASONAL_APPLICABLE',
                rejectionReason: null,
            );
        }

        return new ModifierResult(
            applied: false,
            modifierCode: null,
            rejectionReason: 'outside_seasonal_promotion_window',
        );
    }

    private function applyTenureModifier(int $customerTenureMonths): ModifierResult
    {
        if ($customerTenureMonths >= self::TENURE_GOLD_MONTHS) {
            return new ModifierResult(
                applied: true,
                modifierCode: 'TENURE_GOLD_LEVEL',
                rejectionReason: null,
            );
        }

        if ($customerTenureMonths >= self::TENURE_SILVER_MONTHS) {
            return new ModifierResult(
                applied: true,
                modifierCode: 'TENURE_SILVER_LEVEL',
                rejectionReason: null,
            );
        }

        if ($customerTenureMonths >= self::TENURE_BRONZE_MONTHS) {
            return new ModifierResult(
                applied: true,
                modifierCode: 'TENURE_BRONZE_LEVEL',
                rejectionReason: null,
            );
        }

        return new ModifierResult(
            applied: false,
            modifierCode: null,
            rejectionReason: 'minimum_tenure_not_met',
        );
    }

    private function applyQuantityModifier(int $totalUnits): ModifierResult
    {
        if ($totalUnits >= self::THRESHOLD_VOLUME_ITEMS) {
            return new ModifierResult(
                applied: true,
                modifierCode: 'VOLUME_QUANTITY_APPLIED',
                rejectionReason: null,
            );
        }

        return new ModifierResult(
            applied: false,
            modifierCode: null,
            rejectionReason: 'volume_minimum_not_achieved',
        );
    }

    private function applyOrderSizeModifier(float $orderTotal): ModifierResult
    {
        if ($orderTotal >= self::THRESHOLD_WHOLESALE_ORDER) {
            return new ModifierResult(
                applied: true,
                modifierCode: 'WHOLESALE_ORDER_TIER',
                rejectionReason: null,
            );
        }

        return new ModifierResult(
            applied: false,
            modifierCode: null,
            rejectionReason: 'wholesale_threshold_not_reached',
        );
    }

    private function sumModifierPercentages(array $modifierCodes): float
    {
        $totalPercentage = 0.0;

        foreach ($modifierCodes as $code) {
            $percentage = $this->lookupPercentage($code);
            $totalPercentage += $percentage;
        }

        return $totalPercentage;
    }

    private function lookupPercentage(string $modifierCode): float
    {
        return match ($modifierCode) {
            'SEASONAL_APPLICABLE' => self::RATE_SEASONAL_HOLIDAY,
            'TENURE_GOLD_LEVEL' => self::RATE_LOYALTY_BASE + 5,
            'TENURE_SILVER_LEVEL' => self::RATE_LOYALTY_BASE + 2,
            'TENURE_BRONZE_LEVEL' => self::RATE_LOYALTY_BASE,
            'VOLUME_QUANTITY_APPLIED' => self::RATE_VOLUME,
            'WHOLESALE_ORDER_TIER' => self::RATE_WHOLESALE,
            default => 0,
        };
    }

    private function enforceMaximumModifier(float $totalPercentage): float
    {
        return min($totalPercentage, 40.0);
    }

    private function computeDiscount(float $orderTotal, float $discountPercent): float
    {
        return $orderTotal * ($discountPercent / 100);
    }
}
