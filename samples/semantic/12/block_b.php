<?php
declare(strict_types=1);

namespace Finance\Rules;

final class LendingRiskAssessor
{
    private const RISK_TIER_BRONZE_LIMIT = 1000;
    private const RISK_TIER_SILVER_LIMIT = 5000;
    private const RISK_TIER_GOLD_LIMIT = 10000;
    private const RISK_TIER_PLATINUM_LIMIT = 25000;

    private const ANNUAL_INCOME_BRONZE = 30000;
    private const ANNUAL_INCOME_SILVER = 60000;
    private const ANNUAL_INCOME_GOLD = 100000;

    private const FICO_EXCELLENT = 750;
    private const FICO_VERY_GOOD = 700;
    private const FICO_GOOD = 650;
    private const FICO_FAIR = 600;

    public function assessLoanEligibility(LoanApplicant $applicant): LendingDecision
    {
        $maxLoanAmount = $this->computeMaximumLoanAmount($applicant);
        $riskGrade = $this->determineRiskGrade($applicant);
        $interestRate = $this->calculateInterestRate($applicant, $riskGrade);

        $approvalStatus = $this->evaluateApproval($applicant, $maxLoanAmount);

        return new LendingDecision(
            approved: $approvalStatus,
            maxLoanAmount: $maxLoanAmount,
            riskGrade: $riskGrade,
            suggestedInterestRate: $interestRate,
            reasons: $this->buildDecisionReasons($applicant),
        );
    }

    private function computeMaximumLoanAmount(LoanApplicant $applicant): int
    {
        $incomeBasedLimit = $this->getIncomeBasedLimit($applicant->getStatedIncome());
        $creditScoreMultiplier = $this->getScoreMultiplier($applicant->getFicoScore());

        $calculatedLimit = (int)($incomeBasedLimit * $creditScoreMultiplier);

        $existingDebtRatio = $this->getExistingDebtRatio($applicant);
        if ($existingDebtRatio > 0.36) {
            $calculatedLimit = (int)($calculatedLimit * 0.5);
        } elseif ($existingDebtRatio > 0.28) {
            $calculatedLimit = (int)($calculatedLimit * 0.75);
        }

        if ($applicant->hasChargeOffs()) {
            $calculatedLimit = (int)($calculatedLimit * 0.3);
        }

        if ($applicant->hasRecentLatePayments()) {
            $calculatedLimit = (int)($calculatedLimit * 0.7);
        }

        return $calculatedLimit;
    }

    private function getIncomeBasedLimit(int $annualIncome): int
    {
        if ($annualIncome >= 120000) {
            return self::RISK_TIER_PLATINUM_LIMIT;
        }

        if ($annualIncome >= self::ANNUAL_INCOME_GOLD) {
            return self::RISK_TIER_GOLD_LIMIT;
        }

        if ($annualIncome >= self::ANNUAL_INCOME_SILVER) {
            return self::RISK_TIER_SILVER_LIMIT;
        }

        if ($annualIncome >= self::ANNUAL_INCOME_BRONZE) {
            return self::RISK_TIER_BRONZE_LIMIT;
        }

        return 500;
    }

    private function getScoreMultiplier(int $ficoScore): float
    {
        if ($ficoScore >= self::FICO_EXCELLENT) {
            return 1.5;
        }

        if ($ficoScore >= self::FICO_VERY_GOOD) {
            return 1.25;
        }

        if ($ficoScore >= self::FICO_GOOD) {
            return 1.0;
        }

        if ($ficoScore >= self::FICO_FAIR) {
            return 0.75;
        }

        return 0.5;
    }

    private function getExistingDebtRatio(LoanApplicant $applicant): float
    {
        $monthlyDebt = $applicant->getMonthlyObligations();
        $monthlyGross = $applicant->getMonthlyGrossIncome();

        if ($monthlyGross <= 0) {
            return 1.0;
        }

        return $monthlyDebt / $monthlyGross;
    }

    private function determineRiskGrade(LoanApplicant $applicant): string
    {
        $score = $applicant->getFicoScore();
        $income = $applicant->getStatedIncome();

        if ($score >= self::FICO_EXCELLENT && $income >= 100000) {
            return 'A';
        }

        if ($score >= self::FICO_VERY_GOOD && $income >= 60000) {
            return 'B';
        }

        if ($score >= self::FICO_GOOD) {
            return 'C';
        }

        return 'D';
    }

    private function calculateInterestRate(LoanApplicant $applicant, string $riskGrade): float
    {
        $baseRates = [
            'A' => 6.99,
            'B' => 9.99,
            'C' => 14.99,
            'D' => 19.99,
        ];

        $baseRate = $baseRates[$riskGrade] ?? 24.99;

        if ($applicant->hasBankruptcy()) {
            $baseRate += 5.0;
        }

        return $baseRate;
    }

    private function evaluateApproval(LoanApplicant $applicant, int $maxAmount): bool
    {
        if ($applicant->getFicoScore() < self::FICO_FAIR) {
            return false;
        }

        if ($applicant->hasActiveDelinquencies()) {
            return false;
        }

        if ($maxAmount < 500) {
            return false;
        }

        return true;
    }

    private function buildDecisionReasons(LoanApplicant $applicant): array
    {
        $reasons = [];

        if ($applicant->hasBankruptcy()) {
            $reasons[] = 'Bankruptcy on record reduces approval likelihood';
        }

        if ($applicant->hasActiveDelinquencies()) {
            $reasons[] = 'Active delinquencies require resolution';
        }

        return $reasons;
    }
}
