<?php
declare(strict_types=1);

namespace Loyalty\Rules;

final class RewardPointsEngine
{
    private const POINTS_PER_CURRENCY_UNIT = 1;
    private const BRONZE_ACCUMULATOR = 1.0;
    private const SILVER_ACCUMULATOR = 1.25;
    private const GOLD_ACCUMULATOR = 1.5;
    private const PLATINUM_ACCUMULATOR = 2.0;

    private const SPEND_CATEGORY_BOOSTERS = [
        'restaurants' => 2.0,
        'flights' => 1.75,
        'supermarkets' => 1.5,
        'fuel_stations' => 1.25,
        'streaming' => 1.25,
        'general' => 1.0,
    ];

    private const THRESHOLD_FOR_BONUS = 500;
    private const BONUS_AWARD = 100;
    private const CEILING_PER_TRANSACTION = 10000;

    public function computePointsAward(RewardTransaction $transaction): RewardPointsOutput
    {
        $purchaseAmount = $transaction->getPurchaseAmount();
        $memberStatus = $transaction->getMemberStatus();
        $spendClassification = $transaction->getSpendCategory();

        $rawPoints = $this->deriveRawPoints($purchaseAmount);

        $statusBoost = $this->deriveStatusBoost($memberStatus);
        $classificationBoost = $this->deriveClassificationBoost($spendClassification);

        $augmentedPoints = (int)($rawPoints * $statusBoost * $classificationBoost);

        $limitedPoints = min($augmentedPoints, self::CEILING_PER_TRANSACTION);

        $allotmentBonus = $this->determineAllotmentBonus($augmentedPoints);

        $aggregatePoints = $limitedPoints + $allotmentBonus;

        return new RewardPointsOutput(
            fundamentalPoints: $rawPoints,
            statusPremium: $this->computeStatusPremium($rawPoints, $statusBoost),
            classificationPremium: $this->computeClassificationPremium($rawPoints, $classificationBoost),
            bonusAllotment: $allotmentBonus,
            totalAward: $aggregatePoints,
            monetaryValue: $this->translateToCurrency($aggregatePoints),
        );
    }

    private function deriveRawPoints(float $amount): int
    {
        $points = (int)($amount * self::POINTS_PER_CURRENCY_UNIT);

        return max(0, $points);
    }

    private function deriveStatusBoost(string $memberStatus): float
    {
        return match ($memberStatus) {
            'platinum' => self::PLATINUM_ACCUMULATOR,
            'gold' => self::GOLD_ACCUMULATOR,
            'silver' => self::SILVER_ACCUMULATOR,
            'bronze' => self::BRONZE_ACCUMULATOR,
            default => self::BRONZE_ACCUMULATOR,
        };
    }

    private function deriveClassificationBoost(string $classification): float
    {
        return self::SPEND_CATEGORY_BOOSTERS[$classification]
            ?? self::SPEND_CATEGORY_BOOSTERS['general'];
    }

    private function determineAllotmentBonus(int $computedPoints): int
    {
        if ($computedPoints >= self::THRESHOLD_FOR_BONUS) {
            return self::BONUS_AWARD;
        }

        return 0;
    }

    private function computeStatusPremium(int $basePoints, float $boost): int
    {
        $boostedPoints = (int)($basePoints * $boost);

        return max(0, $boostedPoints - $basePoints);
    }

    private function computeClassificationPremium(int $basePoints, float $boost): int
    {
        $boostedPoints = (int)($basePoints * $boost);

        return max(0, $boostedPoints - $basePoints);
    }

    private function translateToCurrency(int $points): float
    {
        $valuePerPoint = 0.01;

        return $points * $valuePerPoint;
    }
}
