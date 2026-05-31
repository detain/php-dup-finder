<?php
declare(strict_types=1);

namespace Billing\Shared;

interface PaymentPlanStrategy
{
    public function canApprove(PaymentPlanApplication $application): ApprovalResult;
    public function calculatePaymentPlan(PaymentPlanApplication $application): PaymentPlanDetails;
}

abstract class BasePaymentPlanCalculator implements PaymentPlanStrategy
{
    protected LoggerInterface $logger;

    protected const MINIMUM_AMOUNT = 25;
    protected const MAXIMUM_AMOUNT = 50000;
    protected const MAXIMUM_TERM_MONTHS = 24;
    protected const STANDARD_APR = 0.12;
    protected const PROMOTIONAL_APR = 0.0;

    protected const CREDIT_SCORE_EXCELLENT = 750;
    protected const CREDIT_SCORE_GOOD = 680;
    protected const CREDIT_SCORE_FAIR = 600;

    public function canApprove(PaymentPlanApplication $application): ApprovalResult
    {
        $amount = $application->getAmount();
        $score = $application->getCreditScore();

        if ($amount < self::MINIMUM_AMOUNT || $amount > self::MAXIMUM_AMOUNT) {
            return ApprovalResult::denied('amount_outside_eligible_range');
        }

        if ($score < self::CREDIT_SCORE_FAIR) {
            return ApprovalResult::denied('credit_score_below_threshold');
        }

        return ApprovalResult::approved();
    }

    public function calculatePaymentPlan(PaymentPlanApplication $application): PaymentPlanDetails
    {
        $amount = $application->getAmount();
        $score = $application->getCreditScore();
        $requestedTerm = $application->getRequestedTerm();

        $approvedTerm = $this->determineTerm($requestedTerm, $score, $amount);
        $apr = $this->determineAPR($score, $application->hasPromotionalRate());
        $monthlyPayment = $this->computeMonthlyPayment($amount, $approvedTerm, $apr);
        $totalInterest = $this->computeTotalInterest($monthlyPayment, $approvedTerm, $amount);

        return new PaymentPlanDetails(
            approvedTermMonths: $approvedTerm,
            monthlyPayment: $monthlyPayment,
            apr: $apr,
            totalInterest: $totalInterest,
            totalPayment: $amount + $totalInterest,
        );
    }

    protected function determineTerm(int $requested, int $score, float $amount): int
    {
        $maxByScore = $this->getMaxTermByCreditScore($score);
        $maxByAmount = $this->getMaxTermByAmount($amount);

        return min($requested, $maxByScore, $maxByAmount);
    }

    protected function getMaxTermByCreditScore(int $score): int
    {
        if ($score >= self::CREDIT_SCORE_EXCELLENT) {
            return 24;
        }
        if ($score >= self::CREDIT_SCORE_GOOD) {
            return 18;
        }
        if ($score >= self::CREDIT_SCORE_FAIR) {
            return 12;
        }
        return 6;
    }

    protected function getMaxTermByAmount(float $amount): int
    {
        if ($amount <= 500) {
            return 3;
        }
        if ($amount <= 2000) {
            return 6;
        }
        if ($amount <= 7500) {
            return 12;
        }
        if ($amount <= 20000) {
            return 18;
        }
        return 24;
    }

    protected function determineAPR(int $score, bool $promotional): float
    {
        if ($promotional) {
            return self::PROMOTIONAL_APR;
        }
        if ($score >= self::CREDIT_SCORE_EXCELLENT) {
            return self::STANDARD_APR - 0.03;
        }
        if ($score >= self::CREDIT_SCORE_GOOD) {
            return self::STANDARD_APR - 0.01;
        }
        return self::STANDARD_APR;
    }

    protected function computeMonthlyPayment(float $principal, int $termMonths, float $annualRate): float
    {
        if ($annualRate === 0.0) {
            return $principal / $termMonths;
        }

        $monthlyRate = $annualRate / 12;
        $payment = $principal * ($monthlyRate * pow(1 + $monthlyRate, $termMonths))
            / (pow(1 + $monthlyRate, $termMonths) - 1);

        return $payment;
    }

    protected function computeTotalInterest(float $monthlyPayment, int $termMonths, float $principal): float
    {
        return max(0, ($monthlyPayment * $termMonths) - $principal);
    }
}
