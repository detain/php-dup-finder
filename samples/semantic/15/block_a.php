<?php
declare(strict_types=1);

namespace Pricing\Rules;

final class DiscountEligibilityChecker
{
    private const MINIMUM_PURCHASE_FOR_BULK = 1000;
    private const MINIMUM_QUANTITY_FOR_VOLUME = 50;
    private const MINIMUM_ORDER_HISTORY_MONTHS = 6;

    private const LOYALTY_TIER_SILVER_MONTHS = 12;
    private const LOYALTY_TIER_GOLD_MONTHS = 24;
    private const LOYALTY_TIER_PLATINUM_MONTHS = 48;

    private const SEASONAL_DISCOUNT_PERCENT = 15;
    private const LOYALTY_DISCOUNT_PERCENT = 10;
    private const VOLUME_DISCOUNT_PERCENT = 20;
    private const BULK_DISCOUNT_PERCENT = 25;

    public function checkDiscountEligibility(
        Customer $customer,
        Order $order,
        \DateTimeImmutable $purchaseDate
    ): DiscountEligibilityResult {
        $applicableDiscounts = [];
        $reasonCodes = [];

        $seasonalCheck = $this->checkSeasonalEligibility($purchaseDate);
        if ($seasonalCheck->eligible) {
            $applicableDiscounts[] = $seasonalCheck->discountType;
        } else {
            $reasonCodes[] = $seasonalCheck->reasonCode;
        }

        $loyaltyCheck = $this->checkLoyaltyEligibility($customer);
        if ($loyaltyCheck->eligible) {
            $applicableDiscounts[] = $loyaltyCheck->discountType;
        } else {
            $reasonCodes[] = $loyaltyCheck->reasonCode;
        }

        $volumeCheck = $this->checkVolumeEligibility($order);
        if ($volumeCheck->eligible) {
            $applicableDiscounts[] = $volumeCheck->discountType;
        } else {
            $reasonCodes[] = $volumeCheck->reasonCode;
        }

        $bulkCheck = $this->checkBulkEligibility($order);
        if ($bulkCheck->eligible) {
            $applicableDiscounts[] = $bulkCheck->discountType;
        } else {
            $reasonCodes[] = $bulkCheck->reasonCode;
        }

        $totalDiscountPercent = $this->calculateTotalDiscount($applicableDiscounts);
        $maximumDiscount = $this->calculateMaximumDiscount($order, $totalDiscountPercent);

        return new DiscountEligibilityResult(
            eligibleDiscounts: $applicableDiscounts,
            totalDiscountPercent: $totalDiscountPercent,
            maximumDiscountAmount: $maximumDiscount,
            reasonCodes: $reasonCodes,
            finalPrice: $order->getSubtotal() - $maximumDiscount,
        );
    }

    private function checkSeasonalEligibility(\DateTimeImmutable $date): DiscountCheckResult
    {
        $month = (int) $date->format('n');

        if ($month >= 11 || $month <= 1) {
            return new DiscountCheckResult(
                eligible: true,
                discountType: 'seasonal_holiday',
                reasonCode: null,
            );
        }

        if ($month >= 6 && $month <= 8) {
            return new DiscountCheckResult(
                eligible: true,
                discountType: 'seasonal_summer',
                reasonCode: null,
            );
        }

        return new DiscountCheckResult(
            eligible: false,
            discountType: null,
            reasonCode: 'outside_promotional_period',
        );
    }

    private function checkLoyaltyEligibility(Customer $customer): DiscountCheckResult
    {
        $membershipMonths = $customer->getMembershipDurationMonths();

        if ($membershipMonths >= self::LOYALTY_TIER_PLATINUM_MONTHS) {
            return new DiscountCheckResult(
                eligible: true,
                discountType: 'loyalty_platinum',
                reasonCode: null,
            );
        }

        if ($membershipMonths >= self::LOYALTY_TIER_GOLD_MONTHS) {
            return new DiscountCheckResult(
                eligible: true,
                discountType: 'loyalty_gold',
                reasonCode: null,
            );
        }

        if ($membershipMonths >= self::LOYALTY_TIER_SILVER_MONTHS) {
            return new DiscountCheckResult(
                eligible: true,
                discountType: 'loyalty_silver',
                reasonCode: null,
            );
        }

        return new DiscountCheckResult(
            eligible: false,
            discountType: null,
            reasonCode: 'insufficient_membership_duration',
        );
    }

    private function checkVolumeEligibility(Order $order): DiscountCheckResult
    {
        $totalQuantity = $order->getTotalQuantity();

        if ($totalQuantity >= self::MINIMUM_QUANTITY_FOR_VOLUME) {
            return new DiscountCheckResult(
                eligible: true,
                discountType: 'volume_discount',
                reasonCode: null,
            );
        }

        return new DiscountCheckResult(
            eligible: false,
            discountType: null,
            reasonCode: 'insufficient_quantity',
        );
    }

    private function checkBulkEligibility(Order $order): DiscountCheckResult
    {
        $subtotal = $order->getSubtotal();

        if ($subtotal >= self::MINIMUM_PURCHASE_FOR_BULK) {
            return new DiscountCheckResult(
                eligible: true,
                discountType: 'bulk_purchase',
                reasonCode: null,
            );
        }

        return new DiscountCheckResult(
            eligible: false,
            discountType: null,
            reasonCode: 'subtotal_below_bulk_threshold',
        );
    }

    private function calculateTotalDiscount(array $discountTypes): float
    {
        $totalPercent = 0.0;

        foreach ($discountTypes as $type) {
            $totalPercent += match ($type) {
                'seasonal_holiday' => self::SEASONAL_DISCOUNT_PERCENT,
                'seasonal_summer' => self::SEASONAL_DISCOUNT_PERCENT,
                'loyalty_platinum' => self::LOYALTY_DISCOUNT_PERCENT + 5,
                'loyalty_gold' => self::LOYALTY_DISCOUNT_PERCENT + 2,
                'loyalty_silver' => self::LOYALTY_DISCOUNT_PERCENT,
                'volume_discount' => self::VOLUME_DISCOUNT_PERCENT,
                'bulk_purchase' => self::BULK_DISCOUNT_PERCENT,
                default => 0,
            };
        }

        return min($totalPercent, 40.0);
    }

    private function calculateMaximumDiscount(Order $order, float $discountPercent): float
    {
        $subtotal = $order->getSubtotal();
        $discountAmount = $subtotal * ($discountPercent / 100);

        return min($discountAmount, $subtotal * 0.40);
    }
}
