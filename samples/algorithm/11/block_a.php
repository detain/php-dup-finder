<?php
declare(strict_types=1);

namespace PricingEngine\Discount;

use Psr\Log\LoggerInterface;

final class SeasonalDiscountCalculator
{
    private const SPRING_SEASON_START = '03-01';
    private const SPRING_SEASON_END = '05-31';
    private const SUMMER_SEASON_START = '06-01';
    private const SUMMER_SEASON_END = '08-31';
    private const FALL_SEASON_START = '09-01';
    private const FALL_SEASON_END = '11-30';
    private const WINTER_SEASON_START = '12-01';
    private const WINTER_SEASON_END = '02-28';

    private const SPRING_DISCOUNT_PERCENTAGE = 0.15;
    private const SUMMER_DISCOUNT_PERCENTAGE = 0.25;
    private const FALL_DISCOUNT_PERCENTAGE = 0.20;
    private const WINTER_DISCOUNT_PERCENTAGE = 0.30;
    private const BLACK_FRIDAY_DISCOUNT_PERCENTAGE = 0.40;
    private const CYBER_MONDAY_DISCOUNT_PERCENTAGE = 0.35;

    private const MINIMUM_ORDER_FOR_DISCOUNT = 25.00;
    private const MAXIMUM_DISCOUNT_AMOUNT = 500.00;
    private const BULK_DISCOUNT_THRESHOLD = 10;
    private const BULK_DISCOUNT_ADDITIONAL_PERCENTAGE = 0.05;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function calculateDiscount(array $orderItems, \DateTimeInterface $orderDate, bool $isBlackFriday = false, bool $isCyberMonday = false): DiscountResult
    {
        $this->logger->debug('Calculating seasonal discount', [
            'item_count' => count($orderItems),
            'is_black_friday' => $isBlackFriday,
            'is_cyber_monday' => $isCyberMonday,
        ]);

        $subtotal = $this->calculateSubtotal($orderItems);
        if ($subtotal < self::MINIMUM_ORDER_FOR_DISCOUNT) {
            $this->logger->info('Order below minimum for discount', ['subtotal' => $subtotal]);
            return new DiscountResult(0.0, 0.0, $subtotal, 'below_minimum');
        }

        $seasonalDiscount = $this->getSeasonalDiscountPercentage($orderDate);
        if ($isBlackFriday) {
            $seasonalDiscount = self::BLACK_FRIDAY_DISCOUNT_PERCENTAGE;
        } elseif ($isCyberMonday) {
            $seasonalDiscount = self::CYBER_MONDAY_DISCOUNT_PERCENTAGE;
        }

        $seasonalDiscountAmount = $subtotal * $seasonalDiscount;

        $bulkDiscount = $this->calculateBulkDiscount($orderItems);
        $totalDiscountAmount = $seasonalDiscountAmount + $bulkDiscount;

        if ($totalDiscountAmount > self::MAXIMUM_DISCOUNT_AMOUNT) {
            $totalDiscountAmount = self::MAXIMUM_DISCOUNT_AMOUNT;
        }

        $finalTotal = $subtotal - $totalDiscountAmount;

        $this->logger->info('Discount calculated', [
            'subtotal' => $subtotal,
            'seasonal_discount' => $seasonalDiscountAmount,
            'bulk_discount' => $bulkDiscount,
            'total_discount' => $totalDiscountAmount,
        ]);

        return new DiscountResult(
            discountAmount: $totalDiscountAmount,
            discountPercentage: $seasonalDiscount + ($bulkDiscount / $subtotal * 100),
            finalTotal: $finalTotal,
            appliedRules: $this->getAppliedRules($isBlackFriday, $isCyberMonday, $bulkDiscount > 0),
        );
    }

    private function getSeasonalDiscountPercentage(\DateTimeInterface $date): float
    {
        $monthDay = $date->format('m-d');

        if ($monthDay >= self::SPRING_SEASON_START && $monthDay <= self::SPRING_SEASON_END) {
            return self::SPRING_DISCOUNT_PERCENTAGE;
        }

        if ($monthDay >= self::SUMMER_SEASON_START && $monthDay <= self::SUMMER_SEASON_END) {
            return self::SUMMER_DISCOUNT_PERCENTAGE;
        }

        if ($monthDay >= self::FALL_SEASON_START && $monthDay <= self::FALL_SEASON_END) {
            return self::FALL_DISCOUNT_PERCENTAGE;
        }

        if ($monthDay >= self::WINTER_SEASON_START || $monthDay <= self::WINTER_SEASON_END) {
            return self::WINTER_DISCOUNT_PERCENTAGE;
        }

        return 0.0;
    }

    private function calculateBulkDiscount(array $orderItems): float
    {
        $totalQuantity = array_sum(array_column($orderItems, 'quantity'));

        if ($totalQuantity >= self::BULK_DISCOUNT_THRESHOLD) {
            $subtotal = $this->calculateSubtotal($orderItems);
            return $subtotal * self::BULK_DISCOUNT_ADDITIONAL_PERCENTAGE;
        }

        return 0.0;
    }

    private function calculateSubtotal(array $orderItems): float
    {
        $subtotal = 0.0;
        foreach ($orderItems as $item) {
            $price = $item['unit_price'] ?? 0.0;
            $quantity = $item['quantity'] ?? 1;
            $subtotal += $price * $quantity;
        }
        return $subtotal;
    }

    private function getAppliedRules(bool $isBlackFriday, bool $isCyberMonday, bool $hasBulkDiscount): array
    {
        $rules = [];
        if ($isBlackFriday) {
            $rules[] = 'black_friday';
        } elseif ($isCyberMonday) {
            $rules[] = 'cyber_monday';
        } else {
            $rules[] = 'seasonal';
        }

        if ($hasBulkDiscount) {
            $rules[] = 'bulk_purchase';
        }

        return $rules;
    }
}
