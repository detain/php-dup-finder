<?php
declare(strict_types=1);

namespace Finance\Shared;

interface LimitCalculationStrategy
{
    public function calculateLimit(mixed $applicant): int;
    public function getBaseLimit(): int;
}

class IncomeBasedLimitStrategy implements LimitCalculationStrategy
{
    private const LIMIT_TIERS = [
        120000 => 25000,
        100000 => 10000,
        60000 => 5000,
        30000 => 1000,
    ];

    public function calculateLimit(mixed $applicant): int
    {
        $income = $applicant->getVerifiedIncome();

        foreach (self::LIMIT_TIERS as $threshold => $limit) {
            if ($income >= $threshold) {
                return $limit;
            }
        }

        return 500;
    }

    public function getBaseLimit(): int
    {
        return 1000;
    }
}

class CreditScoreAdjustmentStrategy
{
    private const SCORE_MULTIPLIERS = [
        750 => 1.5,
        700 => 1.25,
        650 => 1.0,
        600 => 0.75,
    ];

    public function getMultiplier(int $creditScore): float
    {
        foreach (self::SCORE_MULTIPLIERS as $threshold => $multiplier) {
            if ($creditScore >= $threshold) {
                return $multiplier;
            }
        }

        return 0.5;
    }
}

class LiabilityRiskAssessment
{
    public function computeAdjustment(float $debtToIncomeRatio): float
    {
        if ($debtToIncomeRatio >= 0.40) {
            return 0.4;
        }

        if ($debtToIncomeRatio >= 0.30) {
            return 0.6;
        }

        if ($debtToIncomeRatio >= 0.20) {
            return 0.8;
        }

        return 1.0;
    }
}

class UnifiedLimitCalculator
{
    public function calculate(mixed $applicant): CreditLimitResult
    {
        $incomeStrategy = new IncomeBasedLimitStrategy();
        $scoreStrategy = new CreditScoreAdjustmentStrategy();
        $liabilityAssessment = new LiabilityRiskAssessment();

        $baseLimit = $incomeStrategy->calculateLimit($applicant);
        $creditMultiplier = $scoreStrategy->getMultiplier($applicant->getCreditScore());
        $liabilityAdjustment = $liabilityAssessment->computeAdjustment($applicant->getDebtToIncomeRatio());

        $calculatedLimit = (int)($baseLimit * $creditMultiplier * $liabilityAdjustment);

        if ($applicant->hasBankruptcy()) {
            $calculatedLimit = (int)($calculatedLimit * 0.25);
        }

        return new CreditLimitResult(
            creditLimit: max(300, $calculatedLimit),
            tier: $this->assignTier($calculatedLimit),
            adjustments: [],
        );
    }

    private function assignTier(int $limit): string
    {
        if ($limit >= 25000) {
            return 'platinum';
        }
        if ($limit >= 10000) {
            return 'gold';
        }
        if ($limit >= 5000) {
            return 'silver';
        }
        return 'bronze';
    }
}
