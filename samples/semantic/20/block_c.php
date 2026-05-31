<?php
declare(strict_types=1);

namespace Loyalty\Rules;

final class PointsAccrualCalculator
{
    private const POINTS_BASE_RATE = 1;
    private const STATUS_MULTIPLIER_BRONZE = 1.0;
    private const STATUS_MULTIPLIER_SILVER = 1.25;
    private const STATUS_MULTIPLIER_GOLD = 1.5;
    private const STATUS_MULTIPLIER_PLATINUM = 2.0;

    private const MERCHANT_CATEGORY_MULTIPLIERS = [
        'food_service' => 2.0,
        'transportation' => 1.75,
        'retail_grocery' => 1.5,
        'automotive_fuel' => 1.25,
        'recreation' => 1.25,
        'miscellaneous' => 1.0,
    ];

    private const PROMOTIONAL_THRESHOLD = 500;
    private const PROMOTIONAL_AWARD = 100;
    private const TRANSACTION_CAP = 10000;

    public function accruePoints(AccrualRequest $request): AccrualOutcome
    {
        $transactionValue = $request->getTransactionValue();
        $programLevel = $request->getProgramLevel();
        $merchantClassification = $request->getMerchantClassification();

        $foundationalPoints = $this->computeFoundationalPoints($transactionValue);

        $levelMultiplication = $this->resolveLevelMultiplication($programLevel);
        $classificationMultiplication = $this->resolveClassificationMultiplication($merchantClassification);

        $interimPoints = (int)($foundationalPoints * $levelMultiplication * $classificationMultiplication);

        $cappedPoints = min($interimPoints, self::TRANSACTION_CAP);

        $thresholdAward = $this->evaluateThresholdAward($interimPoints);

        $totalAccrued = $cappedPoints + $thresholdAward;

        return new AccrualOutcome(
            corePoints: $foundationalPoints,
            statusIncrement: $this->computeStatusIncrement($foundationalPoints, $levelMultiplication),
            categoryIncrement: $this->computeCategoryIncrement($foundationalPoints, $classificationMultiplication),
            thresholdBonus: $thresholdAward,
            finalAccrual: $totalAccrued,
            cashEquivalent: $this->computeCashEquivalent($totalAccrued),
        );
    }

    private function computeFoundationalPoints(float $value): int
    {
        $points = (int)($value * self::POINTS_BASE_RATE);

        return max(0, $points);
    }

    private function resolveLevelMultiplication(string $level): float
    {
        return match ($level) {
            'platinum' => self::STATUS_MULTIPLIER_PLATINUM,
            'gold' => self::STATUS_MULTIPLIER_GOLD,
            'silver' => self::STATUS_MULTIPLIER_SILVER,
            'bronze' => self::STATUS_MULTIPLIER_BRONZE,
            default => self::STATUS_MULTIPLIER_BRONZE,
        };
    }

    private function resolveClassificationMultiplication(string $classification): float
    {
        return self::MERCHANT_CATEGORY_MULTIPLIERS[$classification]
            ?? self::MERCHANT_CATEGORY_MULTIPLIERS['miscellaneous'];
    }

    private function evaluateThresholdAward(int $interimPoints): int
    {
        if ($interimPoints >= self::PROMOTIONAL_THRESHOLD) {
            return self::PROMOTIONAL_AWARD;
        }

        return 0;
    }

    private function computeStatusIncrement(int $foundationalPoints, float $multiplication): int
    {
        $multipliedPoints = (int)($foundationalPoints * $multiplication);

        return max(0, $multipliedPoints - $foundationalPoints);
    }

    private function computeCategoryIncrement(int $foundationalPoints, float $multiplication): int
    {
        $multipliedPoints = (int)($foundationalPoints * $multiplication);

        return max(0, $multipliedPoints - $foundationalPoints);
    }

    private function computeCashEquivalent(int $points): float
    {
        return $points * 0.01;
    }
}
