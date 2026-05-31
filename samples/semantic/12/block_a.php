<?php
declare(strict_types=1);

namespace Finance\Rules;

final class CreditLimitCalculator
{
    private const CREDIT_LIMIT_TIER_1 = 1000;
    private const CREDIT_LIMIT_TIER_2 = 5000;
    private const CREDIT_LIMIT_TIER_3 = 10000;
    private const CREDIT_LIMIT_TIER_4 = 25000;

    private const INCOME_THRESHOLD_TIER_1 = 30000;
    private const INCOME_THRESHOLD_TIER_2 = 60000;
    private const INCOME_THRESHOLD_TIER_3 = 100000;

    private const CREDIT_SCORE_FLOOR = 300;
    private const CREDIT_SCORE_EXCELLENT = 750;
    private const CREDIT_SCORE_VERY_GOOD = 700;
    private const CREDIT_SCORE_GOOD = 650;

    public function calculateCreditLimit(CustomerFinancialProfile $profile): CreditLimitResult
    {
        $baseLimit = $this->determineBaseLimit($profile->getAnnualIncome());
        $creditScoreMultiplier = $this->getCreditScoreMultiplier($profile->getCreditScore());
        $debtToIncomeRatio = $this->calculateDebtToIncomeRatio($profile);

        $adjustedLimit = $baseLimit * $creditScoreMultiplier;

        if ($debtToIncomeRatio > 0.3) {
            $adjustedLimit *= 0.5;
        } elseif ($debtToIncomeRatio > 0.2) {
            $adjustedLimit *= 0.75;
        }

        if ($profile->hasBankruptcyHistory()) {
            $adjustedLimit *= 0.25;
        }

        if ($profile->hasDelinquencyHistory()) {
            $adjustedLimit *= 0.5;
        }

        $finalLimit = (int) floor($adjustedLimit);

        return new CreditLimitResult(
            creditLimit: $finalLimit,
            tier: $this->determineTier($finalLimit),
            adjustments: $this->gatherAdjustments($profile),
        );
    }

    private function determineBaseLimit(int $annualIncome): int
    {
        if ($annualIncome >= self::INCOME_THRESHOLD_TIER_3) {
            return self::CREDIT_LIMIT_TIER_4;
        }

        if ($annualIncome >= self::INCOME_THRESHOLD_TIER_2) {
            return self::CREDIT_LIMIT_TIER_3;
        }

        if ($annualIncome >= self::INCOME_THRESHOLD_TIER_1) {
            return self::CREDIT_LIMIT_TIER_2;
        }

        return self::CREDIT_LIMIT_TIER_1;
    }

    private function getCreditScoreMultiplier(int $creditScore): float
    {
        if ($creditScore >= self::CREDIT_SCORE_EXCELLENT) {
            return 1.5;
        }

        if ($creditScore >= self::CREDIT_SCORE_VERY_GOOD) {
            return 1.25;
        }

        if ($creditScore >= self::CREDIT_SCORE_GOOD) {
            return 1.0;
        }

        if ($creditScore >= self::CREDIT_SCORE_FLOOR) {
            return 0.75;
        }

        return 0.5;
    }

    private function calculateDebtToIncomeRatio(CustomerFinancialProfile $profile): float
    {
        $monthlyDebt = $profile->getMonthlyDebtPayments();
        $monthlyIncome = $profile->getMonthlyIncome();

        if ($monthlyIncome <= 0) {
            return 1.0;
        }

        return $monthlyDebt / $monthlyIncome;
    }

    private function determineTier(int $creditLimit): string
    {
        if ($creditLimit >= self::CREDIT_LIMIT_TIER_4) {
            return 'platinum';
        }

        if ($creditLimit >= self::CREDIT_LIMIT_TIER_3) {
            return 'gold';
        }

        if ($creditLimit >= self::CREDIT_LIMIT_TIER_2) {
            return 'silver';
        }

        return 'bronze';
    }

    private function gatherAdjustments(CustomerFinancialProfile $profile): array
    {
        $adjustments = [];

        if ($profile->hasBankruptcyHistory()) {
            $adjustments[] = 'bankruptcy_adjustment';
        }

        if ($profile->hasDelinquencyHistory()) {
            $adjustments[] = 'delinquency_adjustment';
        }

        $dti = $this->calculateDebtToIncomeRatio($profile);
        if ($dti > 0.3) {
            $adjustments[] = 'high_dti_adjustment';
        }

        return $adjustments;
    }
}
