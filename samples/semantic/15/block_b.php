<?php
declare(strict_types=1);

namespace Pricing\Rules;

final class PromotionApplicator
{
    private const MIN_ORDER_AMOUNT_FOR_DISCOUNT = 1000;
    private const MIN_QUANTITY_FOR_TIERED = 50;
    private const CUSTOMER_AGE_MIN_MONTHS = 6;

    private const SILVER_TENURE_MONTHS = 12;
    private const GOLD_TENURE_MONTHS = 24;
    private const PLATINUM_TENURE_MONTHS = 48;

    private const HOLIDAY_PROMO_RATE = 15;
    private const LOYALTY_REWARD_RATE = 10;
    private const VOLUME_REBATE_RATE = 20;
    private const WHOLESALE_RATE = 25;

    public function evaluatePromotions(
        CustomerContext $customer,
        CartContext $cart,
        \DateTimeInterface $orderDate
    ): PromotionEvaluationResult {
        $appliedPromotions = [];
        $rejectionReasons = [];

        $holidayPromo = $this->evaluateHolidayPromotion($orderDate);
        if ($holidayPromo->isApplicable) {
            $appliedPromotions[] = $holidayPromo->promotionCode;
        } else {
            $rejectionReasons[] = $holidayPromo->rejectionReason;
        }

        $loyaltyPromo = $this->evaluateLoyaltyPromotion($customer);
        if ($loyaltyPromo->isApplicable) {
            $appliedPromotions[] = $loyaltyPromo->promotionCode;
        } else {
            $rejectionReasons[] = $loyaltyPromo->rejectionReason;
        }

        $volumePromo = $this->evaluateVolumePromotion($cart);
        if ($volumePromo->isApplicable) {
            $appliedPromotions[] = $volumePromo->promotionCode;
        } else {
            $rejectionReasons[] = $volumePromo->rejectionReason;
        }

        $wholesalePromo = $this->evaluateWholesalePromotion($cart);
        if ($wholesalePromo->isApplicable) {
            $appliedPromotions[] = $wholesalePromo->promotionCode;
        } else {
            $rejectionReasons[] = $wholesalePromo->rejectionReason;
        }

        $totalSavingsPercent = $this->sumPromotionRates($appliedPromotions);
        $maxSavingsAllowed = $this->capTotalSavings($cart, $totalSavingsPercent);

        return new PromotionEvaluationResult(
            applicablePromotions: $appliedPromotions,
            combinedSavingsPercent: $totalSavingsPercent,
            maxSavingsAmount: $maxSavingsAllowed,
            rejections: $rejectionReasons,
        );
    }

    private function evaluateHolidayPromotion(\DateTimeInterface $date): PromotionResult
    {
        $month = (int) date('n', $date->getTimestamp());

        $holidaySeasons = [11, 12, 1, 6, 7, 8];
        if (in_array($month, $holidaySeasons)) {
            return new PromotionResult(
                isApplicable: true,
                promotionCode: 'SEASONAL_' . strtoupper(date('F', $date->getTimestamp())),
                rejectionReason: null,
            );
        }

        return new PromotionResult(
            isApplicable: false,
            promotionCode: null,
            rejectionReason: 'no_active_seasonal_promotion',
        );
    }

    private function evaluateLoyaltyPromotion(CustomerContext $customer): PromotionResult
    {
        $tenureInMonths = $customer->getAccountTenureMonths();

        if ($tenureInMonths >= self::PLATINUM_TENURE_MONTHS) {
            return new PromotionResult(
                isApplicable: true,
                promotionCode: 'LOYALTY_PLATINUM',
                rejectionReason: null,
            );
        }

        if ($tenureInMonths >= self::GOLD_TENURE_MONTHS) {
            return new PromotionResult(
                isApplicable: true,
                promotionCode: 'LOYALTY_GOLD',
                rejectionReason: null,
            );
        }

        if ($tenureInMonths >= self::SILVER_TENURE_MONTHS) {
            return new PromotionResult(
                isApplicable: true,
                promotionCode: 'LOYALTY_SILVER',
                rejectionReason: null,
            );
        }

        return new PromotionResult(
            isApplicable: false,
            promotionCode: null,
            rejectionReason: 'loyalty_tier_not_achieved',
        );
    }

    private function evaluateVolumePromotion(CartContext $cart): PromotionResult
    {
        $itemCount = $cart->getTotalItemCount();

        if ($itemCount >= self::MIN_QUANTITY_FOR_TIERED) {
            return new PromotionResult(
                isApplicable: true,
                promotionCode: 'VOLUME_TIER_' . $this->determineVolumeTier($itemCount),
                rejectionReason: null,
            );
        }

        return new PromotionResult(
            isApplicable: false,
            promotionCode: null,
            rejectionReason: 'volume_threshold_not_met',
        );
    }

    private function evaluateWholesalePromotion(CartContext $cart): PromotionResult
    {
        $cartTotal = $cart->getSubtotal();

        if ($cartTotal >= self::MIN_ORDER_AMOUNT_FOR_DISCOUNT) {
            return new PromotionResult(
                isApplicable: true,
                promotionCode: 'WHOLESALE_BULK',
                rejectionReason: null,
            );
        }

        return new PromotionResult(
            isApplicable: false,
            promotionCode: null,
            rejectionReason: 'wholesale_minimum_not_reached',
        );
    }

    private function sumPromotionRates(array $promotionCodes): float
    {
        $totalRate = 0.0;

        foreach ($promotionCodes as $code) {
            $rate = $this->getPromotionRate($code);
            $totalRate += $rate;
        }

        return min($totalRate, 40.0);
    }

    private function getPromotionRate(string $promotionCode): float
    {
        return match (true) {
            str_contains($promotionCode, 'SEASONAL') => self::HOLIDAY_PROMO_RATE,
            str_contains($promotionCode, 'PLATINUM') => self::LOYALTY_REWARD_RATE + 5,
            str_contains($promotionCode, 'GOLD') => self::LOYALTY_REWARD_RATE + 2,
            str_contains($promotionCode, 'SILVER') => self::LOYALTY_REWARD_RATE,
            str_contains($promotionCode, 'VOLUME') => self::VOLUME_REBATE_RATE,
            str_contains($promotionCode, 'WHOLESALE') => self::WHOLESALE_RATE,
            default => 0,
        };
    }

    private function determineVolumeTier(int $itemCount): string
    {
        if ($itemCount >= 200) {
            return 'TIER_3';
        }

        if ($itemCount >= 100) {
            return 'TIER_2';
        }

        return 'TIER_1';
    }

    private function capTotalSavings(CartContext $cart, float $totalRate): float
    {
        $subtotal = $cart->getSubtotal();
        $calculatedSavings = $subtotal * ($totalRate / 100);

        return min($calculatedSavings, $subtotal * 0.40);
    }
}
