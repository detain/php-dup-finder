<?php
declare(strict_types=1);

namespace LoyaltyEngine\Points;

use Psr\Log\LoggerInterface;

final class LoyaltyPointsCalculator
{
    private const SPRING_SEASON_START = '03-01';
    private const SPRING_SEASON_END = '05-31';
    private const SUMMER_SEASON_START = '06-01';
    private const SUMMER_SEASON_END = '08-31';
    private const FALL_SEASON_START = '09-01';
    private const FALL_SEASON_END = '11-30';
    private const WINTER_SEASON_START = '12-01';
    private const WINTER_SEASON_END = '02-28';

    private const SPRING_BASE_POINTS_PER_DOLLAR = 3;
    private const SUMMER_BASE_POINTS_PER_DOLLAR = 2;
    private const FALL_BASE_POINTS_PER_DOLLAR = 3;
    private const WINTER_BASE_POINTS_PER_DOLLAR = 4;
    private const BLACK_FRIDAY_MULTIPLIER = 3.0;
    private const CYBER_MONDAY_MULTIPLIER = 2.5;

    private const MINIMUM_PURCHASE_FOR_POINTS = 10.00;
    private const MAXIMUM_POINTS_PER_TRANSACTION = 5000;
    private const REFERRAL_BONUS_POINTS = 500;
    private const BIRTHDAY_BONUS_POINTS = 200;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function calculatePoints(array $orderItems, \DateTimeInterface $orderDate, array $customerContext = []): PointsResult
    {
        $this->logger->debug('Calculating loyalty points', [
            'item_count' => count($orderItems),
            'customer_id' => $customerContext['customer_id'] ?? 'unknown',
        ]);

        $subtotal = $this->calculateSubtotal($orderItems);
        if ($subtotal < self::MINIMUM_PURCHASE_FOR_POINTS) {
            $this->logger->info('Purchase below minimum for points', ['subtotal' => $subtotal]);
            return new PointsResult(0, 0.0, $subtotal, 'below_minimum');
        }

        $basePoints = $this->calculateBasePoints($subtotal, $orderDate);
        $multiplier = $this->getSeasonalMultiplier($orderDate);
        $isBlackFriday = $this->isBlackFriday($orderDate);
        $isCyberMonday = $this->isCyberMonday($orderDate);

        if ($isBlackFriday) {
            $multiplier = self::BLACK_FRIDAY_MULTIPLIER;
        } elseif ($isCyberMonday) {
            $multiplier = self::CYBER_MONDAY_MULTIPLIER;
        }

        $seasonalPoints = (int)floor($basePoints * $multiplier);

        $bonusPoints = $this->calculateBonusPoints($customerContext);
        $totalPoints = $seasonalPoints + $bonusPoints;

        if ($totalPoints > self::MAXIMUM_POINTS_PER_TRANSACTION) {
            $totalPoints = self::MAXIMUM_POINTS_PER_TRANSACTION;
        }

        $dollarValue = $totalPoints / 100;

        $this->logger->info('Points calculated', [
            'base_points' => $basePoints,
            'multiplier' => $multiplier,
            'bonus_points' => $bonusPoints,
            'total_points' => $totalPoints,
        ]);

        return new PointsResult(
            pointsEarned: $totalPoints,
            pointsValue: $dollarValue,
            purchaseAmount: $subtotal,
            appliedMultipliers: $this->getAppliedMultipliers($isBlackFriday, $isCyberMonday, $bonusPoints > 0),
        );
    }

    private function getSeasonalMultiplier(\DateTimeInterface $date): float
    {
        $monthDay = $date->format('m-d');

        if ($monthDay >= self::SPRING_SEASON_START && $monthDay <= self::SPRING_SEASON_END) {
            return self::SPRING_BASE_POINTS_PER_DOLLAR;
        }

        if ($monthDay >= self::SUMMER_SEASON_START && $monthDay <= self::SUMMER_SEASON_END) {
            return self::SUMMER_BASE_POINTS_PER_DOLLAR;
        }

        if ($monthDay >= self::FALL_SEASON_START && $monthDay <= self::FALL_SEASON_END) {
            return self::FALL_BASE_POINTS_PER_DOLLAR;
        }

        if ($monthDay >= self::WINTER_SEASON_START || $monthDay <= self::WINTER_SEASON_END) {
            return self::WINTER_BASE_POINTS_PER_DOLLAR;
        }

        return 1.0;
    }

    private function calculateBasePoints(float $subtotal, \DateTimeInterface $date): int
    {
        $pointsPerDollar = $this->getPointsPerDollar($date);
        return (int)floor($subtotal * $pointsPerDollar);
    }

    private function getPointsPerDollar(\DateTimeInterface $date): int
    {
        $monthDay = $date->format('m-d');

        if ($monthDay >= self::SPRING_SEASON_START && $monthDay <= self::SPRING_SEASON_END) {
            return self::SPRING_BASE_POINTS_PER_DOLLAR;
        }

        if ($monthDay >= self::SUMMER_SEASON_START && $monthDay <= self::SUMMER_SEASON_END) {
            return self::SUMMER_BASE_POINTS_PER_DOLLAR;
        }

        if ($monthDay >= self::FALL_SEASON_START && $monthDay <= self::FALL_SEASON_END) {
            return self::FALL_BASE_POINTS_PER_DOLLAR;
        }

        return self::WINTER_BASE_POINTS_PER_DOLLAR;
    }

    private function calculateBonusPoints(array $customerContext): int
    {
        $bonusPoints = 0;

        if ($customerContext['is_referral'] ?? false) {
            $bonusPoints += self::REFERRAL_BONUS_POINTS;
        }

        if ($customerContext['is_birthday_month'] ?? false) {
            $bonusPoints += self::BIRTHDAY_BONUS_POINTS;
        }

        return $bonusPoints;
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

    private function isBlackFriday(\DateTimeInterface $date): bool
    {
        $monthDay = $date->format('m-d');
        return $monthDay === '11-25' || $monthDay === '11-26';
    }

    private function isCyberMonday(\DateTimeInterface $date): bool
    {
        return $date->format('m-d') === '11-28';
    }

    private function getAppliedMultipliers(bool $isBlackFriday, bool $isCyberMonday, bool $hasBonus): array
    {
        $multipliers = [];
        if ($isBlackFriday) {
            $multipliers[] = 'black_friday_3x';
        } elseif ($isCyberMonday) {
            $multipliers[] = 'cyber_monday_2.5x';
        } else {
            $multipliers[] = 'seasonal';
        }

        if ($hasBonus) {
            $multipliers[] = 'bonus';
        }

        return $multipliers;
    }
}
