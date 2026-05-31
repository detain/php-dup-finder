<?php
declare(strict_types=1);

namespace Loyalty\Rules;

final class LoyaltyPointsCalculator
{
    private const BASE_POINTS_PER_DOLLAR = 1;
    private const TIER_MULTIPLIER_BRONZE = 1.0;
    private const TIER_MULTIPLIER_SILVER = 1.25;
    private const TIER_MULTIPLIER_GOLD = 1.5;
    private const TIER_MULTIPLIER_PLATINUM = 2.0;

    private const CATEGORY_BONUS_MULTIPLIERS = [
        'dining' => 2.0,
        'travel' => 1.75,
        'groceries' => 1.5,
        'gas' => 1.25,
        'entertainment' => 1.25,
        'default' => 1.0,
    ];

    private const BONUS_POINTS_THRESHOLD = 500;
    private const BONUS_POINTS_AMOUNT = 100;
    private const MAX_POINTS_PER_TRANSACTION = 10000;

    public function calculatePointsEarned(PointsEarningRequest $request): PointsEarningResult
    {
        $transactionAmount = $request->getTransactionAmount();
        $customerTier = $request->getCustomerTier();
        $merchantCategory = $request->getMerchantCategory();

        $basePoints = $this->calculateBasePoints($transactionAmount);

        $tierMultiplier = $this->getTierMultiplier($customerTier);
        $categoryMultiplier = $this->getCategoryMultiplier($merchantCategory);

        $calculatedPoints = (int)($basePoints * $tierMultiplier * $categoryMultiplier);

        $cappedPoints = min($calculatedPoints, self::MAX_POINTS_PER_TRANSACTION);

        $bonusPoints = $this->checkBonusEligibility($calculatedPoints);

        $totalPoints = $cappedPoints + $bonusPoints;

        return new PointsEarningResult(
            basePoints: $basePoints,
            tierBonus: $this->calculateTierBonus($basePoints, $tierMultiplier),
            categoryBonus: $this->calculateCategoryBonus($basePoints, $categoryMultiplier),
            promotionalBonus: $bonusPoints,
            totalPointsEarned: $totalPoints,
            pointsValueDollars: $this->convertPointsToDollarValue($totalPoints),
        );
    }

    private function calculateBasePoints(float $transactionAmount): int
    {
        $points = (int)($transactionAmount * self::BASE_POINTS_PER_DOLLAR);

        return max(0, $points);
    }

    private function getTierMultiplier(string $customerTier): float
    {
        return match ($customerTier) {
            'platinum' => self::TIER_MULTIPLIER_PLATINUM,
            'gold' => self::TIER_MULTIPLIER_GOLD,
            'silver' => self::TIER_MULTIPLIER_SILVER,
            'bronze' => self::TIER_MULTIPLIER_BRONZE,
            default => self::TIER_MULTIPLIER_BRONZE,
        };
    }

    private function getCategoryMultiplier(string $category): float
    {
        return self::CATEGORY_BONUS_MULTIPLIERS[$category]
            ?? self::CATEGORY_BONUS_MULTIPLIERS['default'];
    }

    private function checkBonusEligibility(int $pointsEarned): int
    {
        if ($pointsEarned >= self::BONUS_POINTS_THRESHOLD) {
            return self::BONUS_POINTS_AMOUNT;
        }

        return 0;
    }

    private function calculateTierBonus(int $basePoints, float $multiplier): int
    {
        $tierPoints = (int)($basePoints * $multiplier);

        return max(0, $tierPoints - $basePoints);
    }

    private function calculateCategoryBonus(int $basePoints, float $multiplier): int
    {
        $categoryPoints = (int)($basePoints * $multiplier);

        return max(0, $categoryPoints - $basePoints);
    }

    private function convertPointsToDollarValue(int $points): float
    {
        $centsPerPoint = 0.01;

        return $points * $centsPerPoint;
    }
}
