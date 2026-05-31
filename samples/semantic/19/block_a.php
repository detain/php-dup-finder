<?php
declare(strict_types=1);

namespace Billing\Rules;

final class PaymentPlanGenerator
{
    private const MINIMUM_INSTALLMENT_AMOUNT = 25;
    private const MAXIMUM_INSTALLMENT_PLAN_MONTHS = 24;
    private const MAXIMUM_INSTALLMENT_PLAN_AMOUNT = 50000;
    private const INTEREST_RATE_STANDARD = 0.12;
    private const INTEREST_RATE_PROMOTIONAL = 0.0;

    private const CREDIT_SCORE_THRESHOLD_EXCELLENT = 750;
    private const CREDIT_SCORE_THRESHOLD_GOOD = 680;
    private const CREDIT_SCORE_THRESHOLD_FAIR = 600;

    public function generatePaymentPlan(PaymentPlanRequest $request): PaymentPlanResult
    {
        $totalAmount = $request->getTotalDue();
        $customerCreditScore = $request->getCustomerCreditScore();
        $preferredTermMonths = $request->getRequestedTermMonths();

        $eligibilityCheck = $this->checkEligibility($totalAmount, $customerCreditScore);
        if (!$eligibilityCheck->eligible) {
            return new PaymentPlanResult(
                approved: false,
                rejectionReason: $eligibilityCheck->rejectionReason,
            );
        }

        $approvedTerm = $this->determineApprovedTerm(
            $preferredTermMonths,
            $customerCreditScore,
            $totalAmount
        );

        $interestRate = $this->determineInterestRate($customerCreditScore, $request->isPromotional());
        $monthlyPayment = $this->calculateMonthlyPayment($totalAmount, $approvedTerm, $interestRate);
        $totalInterest = $this->calculateTotalInterest($monthlyPayment, $approvedTerm, $totalAmount);

        return new PaymentPlanResult(
            approved: true,
            approvedTermMonths: $approvedTerm,
            monthlyPayment: $monthlyPayment,
            interestRate: $interestRate,
            totalInterest: $totalInterest,
            totalPayment: $totalAmount + $totalInterest,
        );
    }

    private function checkEligibility(float $totalAmount, int $creditScore): EligibilityResult
    {
        if ($totalAmount < self::MINIMUM_INSTALLMENT_AMOUNT) {
            return new EligibilityResult(
                eligible: false,
                rejectionReason: 'amount_below_minimum',
            );
        }

        if ($totalAmount > self::MAXIMUM_INSTALLMENT_PLAN_AMOUNT) {
            return new EligibilityResult(
                eligible: false,
                rejectionReason: 'amount_exceeds_maximum',
            );
        }

        if ($creditScore < self::CREDIT_SCORE_THRESHOLD_FAIR) {
            return new EligibilityResult(
                eligible: false,
                rejectionReason: 'credit_score_too_low',
            );
        }

        return new EligibilityResult(
            eligible: true,
            rejectionReason: null,
        );
    }

    private function determineApprovedTerm(
        int $preferredTerm,
        int $creditScore,
        float $totalAmount
    ): int {
        $maximumAllowedTerm = $this->getMaximumTermForCreditScore($creditScore);

        $termBasedOnAmount = $this->getMaximumTermForAmount($totalAmount);

        $approvedTerm = min($preferredTerm, $maximumAllowedTerm, $termBasedOnAmount);

        return max(3, $approvedTerm);
    }

    private function getMaximumTermForCreditScore(int $creditScore): int
    {
        if ($creditScore >= self::CREDIT_SCORE_THRESHOLD_EXCELLENT) {
            return 24;
        }

        if ($creditScore >= self::CREDIT_SCORE_THRESHOLD_GOOD) {
            return 18;
        }

        if ($creditScore >= self::CREDIT_SCORE_THRESHOLD_FAIR) {
            return 12;
        }

        return 6;
    }

    private function getMaximumTermForAmount(float $amount): int
    {
        if ($amount <= 500) {
            return 3;
        }

        if ($amount <= 1500) {
            return 6;
        }

        if ($amount <= 5000) {
            return 12;
        }

        if ($amount <= 15000) {
            return 18;
        }

        return 24;
    }

    private function determineInterestRate(int $creditScore, bool $isPromotional): float
    {
        if ($isPromotional) {
            return self::INTEREST_RATE_PROMOTIONAL;
        }

        if ($creditScore >= self::CREDIT_SCORE_THRESHOLD_EXCELLENT) {
            return self::INTEREST_RATE_STANDARD - 0.03;
        }

        if ($creditScore >= self::CREDIT_SCORE_THRESHOLD_GOOD) {
            return self::INTEREST_RATE_STANDARD - 0.01;
        }

        return self::INTEREST_RATE_STANDARD;
    }

    private function calculateMonthlyPayment(float $principal, int $termMonths, float $annualInterestRate): float
    {
        if ($annualInterestRate === 0.0) {
            return $principal / $termMonths;
        }

        $monthlyInterestRate = $annualInterestRate / 12;

        $payment = $principal * ($monthlyInterestRate * pow(1 + $monthlyInterestRate, $termMonths))
            / (pow(1 + $monthlyInterestRate, $termMonths) - 1);

        return $payment;
    }

    private function calculateTotalInterest(float $monthlyPayment, int $termMonths, float $principal): float
    {
        $totalPayments = $monthlyPayment * $termMonths;

        return max(0, $totalPayments - $principal);
    }
}
