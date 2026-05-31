<?php
declare(strict_types=1);

namespace Billing\Rules;

final class DeferredPaymentEvaluator
{
    private const MINIMUM_DEFERRED_AMOUNT = 25;
    private const MAXIMUM_DEFERRED_TERM_WEEKS = 96;
    private const MAXIMUM_DEFERRED_BALANCE = 50000;
    private const STANDARD_EQUIVALENT_RATE = 0.12;
    private const ZERO_RATE_PROMOTION = 0.0;

    private const SCORE_TIER_PREMIUM = 750;
    private const SCORE_TIER_STANDARD = 680;
    private const SCORE_TIER_BASIC = 600;

    public function evaluateDeferredPayment(DeferredPaymentApplication $application): DeferredPaymentDecision
    {
        $outstandingBalance = $application->getOutstandingBalance();
        $consumerScore = $application->getConsumerScore();
        $requestedDurationWeeks = $application->getRequestedDurationWeeks();

        $qualificationResult = $this->performQualificationCheck($outstandingBalance, $consumerScore);
        if (!$qualificationResult->qualified) {
            return new DeferredPaymentDecision(
                approved: false,
                reason: $qualificationResult->failureReason,
            );
        }

        $grantedDuration = $this->resolveApprovedDuration(
            $requestedDurationWeeks,
            $consumerScore,
            $outstandingBalance
        );

        $financingRate = $this->resolveEquivantRate($consumerScore, $application->isZeroRatePromotion());
        $calculatedPayment = $this->resolveMonthlyPayment($outstandingBalance, $grantedDuration, $financingRate);
        $totalFinancingCost = $this->resolveTotalFinancingCost($calculatedPayment, $grantedDuration, $outstandingBalance);

        return new DeferredPaymentDecision(
            approved: true,
            approvedDurationWeeks: $grantedDuration,
            periodicPayment: $calculatedPayment,
            equivalentRate: $financingRate,
            totalFinancingCharge: $totalFinancingCost,
            totalObligation: $outstandingBalance + $totalFinancingCost,
        );
    }

    private function performQualificationCheck(float $balance, int $score): QualificationOutcome
    {
        if ($balance < self::MINIMUM_DEFERRED_AMOUNT) {
            return new QualificationOutcome(
                qualified: false,
                failureReason: 'balance_too_small_for_deferred_payment',
            );
        }

        if ($balance > self::MAXIMUM_DEFERRED_BALANCE) {
            return new QualificationOutcome(
                qualified: false,
                failureReason: 'balance_exceeds_maximum_deferred_limit',
            );
        }

        if ($score < self::SCORE_TIER_BASIC) {
            return new QualificationOutcome(
                qualified: false,
                failureReason: 'credit_score_insufficient',
            );
        }

        return new QualificationOutcome(
            qualified: true,
            failureReason: null,
        );
    }

    private function resolveApprovedDuration(
        int $requestedWeeks,
        int $score,
        float $balance
    ): int {
        $maxWeeksByScore = $this->getMaxWeeksByCreditTier($score);
        $maxWeeksByBalance = $this->getMaxWeeksByBalanceAmount($balance);

        $approvedWeeks = min($requestedWeeks, $maxWeeksByScore, $maxWeeksByBalance);

        return max(12, $approvedWeeks);
    }

    private function getMaxWeeksByCreditTier(int $score): int
    {
        if ($score >= self::SCORE_TIER_PREMIUM) {
            return self::MAXIMUM_DEFERRED_TERM_WEEKS;
        }

        if ($score >= self::SCORE_TIER_STANDARD) {
            return 72;
        }

        if ($score >= self::SCORE_TIER_BASIC) {
            return 48;
        }

        return 24;
    }

    private function getMaxWeeksByBalanceAmount(float $balance): int
    {
        if ($balance <= 500) {
            return 12;
        }

        if ($balance <= 2000) {
            return 24;
        }

        if ($balance <= 7500) {
            return 48;
        }

        if ($balance <= 20000) {
            return 72;
        }

        return 96;
    }

    private function resolveEquivantRate(int $score, bool $zeroRateFlag): float
    {
        if ($zeroRateFlag) {
            return self::ZERO_RATE_PROMOTION;
        }

        if ($score >= self::SCORE_TIER_PREMIUM) {
            return self::STANDARD_EQUIVALENT_RATE - 0.03;
        }

        if ($score >= self::SCORE_TIER_STANDARD) {
            return self::STANDARD_EQUIVALENT_RATE - 0.01;
        }

        return self::STANDARD_EQUIVALENT_RATE;
    }

    private function resolveMonthlyPayment(float $principal, int $termWeeks, float $annualRate): float
    {
        $termMonths = (int)ceil($termWeeks / 4);

        if ($annualRate === 0.0) {
            return $principal / $termMonths;
        }

        $monthlyRate = $annualRate / 12;

        $payment = $principal * ($monthlyRate * pow(1 + $monthlyRate, $termMonths))
            / (pow(1 + $monthlyRate, $termMonths) - 1);

        return $payment;
    }

    private function resolveTotalFinancingCost(float $monthlyPayment, int $termWeeks, float $principal): float
    {
        $termMonths = (int)ceil($termWeeks / 4);
        $totalPayments = $monthlyPayment * $termMonths;

        return max(0, $totalPayments - $principal);
    }
}
