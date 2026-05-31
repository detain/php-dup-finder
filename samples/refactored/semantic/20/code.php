<?php
declare(strict_types=1);

namespace Loyalty\Shared;

interface PointsCalculationStrategy
{
    public function calculate(PointsContext $context): PointsResult;
    public function getBasePointsPerUnit(): int;
    public function getTierMultiplier(string $tier): float;
    public function getCategoryMultiplier(string $category): float;
}

abstract class BasePointsCalculator implements PointsCalculationStrategy
{
    protected LoggerInterface $logger;

    protected const POINTS_PER_DOLLAR = 1;
    protected const MAX_POINTS_PER_TRANSACTION = 10000;
    protected const BONUS_THRESHOLD = 500;
    protected const BONUS_AMOUNT = 100;

    protected const TIER_MULTIPLIERS = [
        'bronze' => 1.0,
        'silver' => 1.25,
        'gold' => 1.5,
        'platinum' => 2.0,
    ];

    public function calculate(PointsContext $context): PointsResult
    {
        $amount = $context->getTransactionAmount();
        $tier = $context->getCustomerTier();
        $category = $context->getMerchantCategory();

        $basePoints = $this->calculateBasePoints($amount);
        $tierMultiplier = $this->getTierMultiplier($tier);
        $categoryMultiplier = $this->getCategoryMultiplier($category);

        $calculatedPoints = (int)($basePoints * $tierMultiplier * $categoryMultiplier);
        $cappedPoints = min($calculatedPoints, self::MAX_POINTS_PER_TRANSACTION);
        $bonusPoints = $this->evaluateBonus($calculatedPoints);

        $totalPoints = $cappedPoints + $bonusPoints;

        return new PointsResult(
            basePoints: $basePoints,
            tierBonus: (int)($basePoints * ($tierMultiplier - 1)),
            categoryBonus: (int)($basePoints * ($categoryMultiplier - 1)),
            promotionalBonus: $bonusPoints,
            totalPoints: $totalPoints,
            dollarValue: $this->convertToDollarValue($totalPoints),
        );
    }

    public function getBasePointsPerUnit(): int
    {
        return self::POINTS_PER_DOLLAR;
    }

    public function getTierMultiplier(string $tier): float
    {
        return self::TIER_MULTIPLIERS[$tier] ?? 1.0;
    }

    abstract public function getCategoryMultiplier(string $category): float;

    protected function calculateBasePoints(float $amount): int
    {
        return max(0, (int)($amount * self::POINTS_PER_DOLLAR));
    }

    protected function evaluateBonus(int $calculatedPoints): int
    {
        if ($calculatedPoints >= self::BONUS_THRESHOLD) {
            return self::BONUS_AMOUNT;
        }
        return 0;
    }

    protected function convertToDollarValue(int $points): float
    {
        return $points * 0.01;
    }
}

final class StandardPointsCalculator extends BasePointsCalculator
{
    private const CATEGORY_MULTIPLIERS = [
        'dining' => 2.0,
        'travel' => 1.75,
        'groceries' => 1.5,
        'gas' => 1.25,
        'entertainment' => 1.25,
        'default' => 1.0,
    ];

    public function getCategoryMultiplier(string $category): float
    {
        return self::CATEGORY_MULTIPLIERS[$category] ?? 1.0;
    }
}
