<?php
declare(strict_types=1);

namespace Billing\Rules;

final class InstallmentPlanCalculator
{
    private const MINIMUM_PAYMENT_AMOUNT = 25;
    private const MAXIMUM_TERM_MONTHS = 24;
    private const MAXIMUM_PLAN_AMOUNT = 50000;
    private const STANDARD_APR = 0.12;
    private const PROMOTIONAL_APR = 0.0;

    private const FICO_EXCELLENT = 750;
    private const FICO_GOOD = 680;
    private const FICO_FAIR = 600;

    public function calculateInstallmentPlan(InstallmentPlanRequest $request): InstallmentPlanOutput
    {
        $balanceDue = $request->getAccountBalance();
        $applicantFico = $request->getApplicantFicoScore();
        $requestedDuration = $request->getDesiredTermMonths();

        $qualificationStatus = $this->evaluateQualification($balanceDue, $applicantFico);
        if (!$qualificationStatus->qualified) {
            return new InstallmentPlanOutput(
                approved: false,
                denialReason: $qualificationStatus->denialReason,
            );
        }

        $grantedTerm = $this->determineApprovedDuration(
            $requestedDuration,
            $applicantFico,
            $balanceDue
        );

        $appliedApr = $this->determineAPR($applicantFico, $request->hasPromotionalOffer());
        $calculatedPayment = $this->computeMonthlyPayment($balanceDue, $grantedTerm, $appliedApr);
        $calculatedTotalInterest = $this->computeTotalInterest($calculatedPayment, $grantedTerm, $balanceDue);

        return new InstallmentPlanOutput(
            approved: true,
            approvedTermMonths: $grantedTerm,
            monthlyPaymentAmount: $calculatedPayment,
            appliedApr: $appliedApr,
            totalInterestCharged: $calculatedTotalInterest,
            totalAmountPayable: $balanceDue + $calculatedTotalInterest,
        );
    }

    private function evaluateQualification(float $balanceDue, int $applicantFico): QualificationResult
    {
        if ($balanceDue < self::MINIMUM_PAYMENT_AMOUNT) {
            return new QualificationResult(
                qualified: false,
                denialReason: 'balance_insufficient_for_installment_plan',
            );
        }

        if ($balanceDue > self::MAXIMUM_PLAN_AMOUNT) {
            return new QualificationResult(
                qualified: false,
                denialReason: 'balance_exceeds_installment_eligibility',
            );
        }

        if ($applicantFico < self::FICO_FAIR) {
            return new QualificationResult(
                qualified: false,
                denialReason: 'credit_score_below_approval_threshold',
            );
        }

        return new QualificationResult(
            qualified: true,
            denialReason: null,
        );
    }

    private function determineApprovedDuration(
        int $requestedDuration,
        int $applicantFico,
        float $balanceDue
    ): int {
        $termCeilingByScore = $this->getTermCeilingByCreditRating($applicantFico);
        $termCeilingByAmount = $this->getTermCeilingByBalance($balanceDue);

        $grantedDuration = min($requestedDuration, $termCeilingByScore, $termCeilingByAmount);

        return max(3, $grantedDuration);
    }

    private function getTermCeilingByCreditRating(int $ficoScore): int
    {
        if ($ficoScore >= self::FICO_EXCELLENT) {
            return 24;
        }

        if ($ficoScore >= self::FICO_GOOD) {
            return 18;
        }

        if ($ficoScore >= self::FICO_FAIR) {
            return 12;
        }

        return 6;
    }

    private function getTermCeilingByBalance(float $balance): int
    {
        if ($balance <= 500) {
            return 3;
        }

        if ($balance <= 2000) {
            return 6;
        }

        if ($balance <= 7500) {
            return 12;
        }

        if ($balance <= 20000) {
            return 18;
        }

        return 24;
    }

    private function determineAPR(int $ficoScore, bool $hasPromotionalOffer): float
    {
        if ($hasPromotionalOffer) {
            return self::PROMOTIONAL_APR;
        }

        if ($ficoScore >= self::FICO_EXCELLENT) {
            return self::STANDARD_APR - 0.03;
        }

        if ($ficoScore >= self::FICO_GOOD) {
            return self::STANDARD_APR - 0.01;
        }

        return self::STANDARD_APR;
    }

    private function computeMonthlyPayment(float $principal, int $termMonths, float $annualRate): float
    {
        if ($annualRate === 0.0) {
            return $principal / $termMonths;
        }

        $monthlyRate = $annualRate / 12;

        $payment = $principal * ($monthlyRate * pow(1 + $monthlyRate, $termMonths))
            / (pow(1 + $monthlyRate, $termMonths) - 1);

        return $payment;
    }

    private function computeTotalInterest(float $monthlyPayment, int $termMonths, float $principal): float
    {
        $totalPayments = $monthlyPayment * $termMonths;

        return max(0, $totalPayments - $principal);
    }
}
