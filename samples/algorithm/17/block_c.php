<?php
declare(strict_types=1);

namespace BillingEngine\Payment;

use Psr\Log\LoggerInterface;

final class PaymentDueDateCalculator
{
    private const PAYMENT_TERMS_NET_30 = 30;
    private const PAYMENT_TERMS_NET_45 = 45;
    private const PAYMENT_TERMS_NET_60 = 60;
    private const PAYMENT_TERMS_NET_90 = 90;
    private const PAYMENT_TERMS_DUE_ON_RECEIPT = 0;
    private const PAYMENT_TERMS_2_10_NET_30 = 30;

    private const EARLY_PAYMENT_DISCOUNT_PERCENT = 0.02;
    private const EARLY_PAYMENT_DISCOUNT_DAYS = 10;
    private const LATE_FEE_PERCENTAGE = 0.015;
    private const LATE_FEE_MINIMUM = 25.00;
    private const GRACE_PERIOD_DAYS = 5;

    private const WEEKEND_ADJUSTMENT_ENABLED = true;
    private const HOLIDAY_CALENDAR_US = 'US';

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function calculateDueDate(Payment $payment): DueDateResult
    {
        $this->logger->debug('Calculating payment due date', [
            'payment_id' => $payment->getId(),
            'invoice_date' => $payment->getInvoiceDate()->format('Y-m-d'),
            'terms' => $payment->getPaymentTerms(),
        ]);

        $invoiceDate = $payment->getInvoiceDate();
        $paymentTerms = $payment->getPaymentTerms();
        $netDays = $this->getNetDaysForTerms($paymentTerms);

        $baseDueDate = $invoiceDate->modify("+{$netDays} days");

        if (self::WEEKEND_ADJUSTMENT_ENABLED) {
            $baseDueDate = $this->adjustForWeekend($baseDueDate);
        }

        $earlyPaymentDeadline = null;
        $earlyPaymentDiscount = null;

        if ($this->hasEarlyPaymentDiscount($paymentTerms)) {
            $earlyDeadlineDays = self::EARLY_PAYMENT_DISCOUNT_DAYS;
            $earlyPaymentDeadline = $invoiceDate->modify("+{$earlyDeadlineDays} days");
            $earlyPaymentDeadline = $this->adjustForWeekend($earlyPaymentDeadline);

            $paymentAmount = $payment->getAmount();
            $earlyPaymentDiscount = $paymentAmount * self::EARLY_PAYMENT_DISCOUNT_PERCENT;
        }

        $lateFeeThreshold = $baseDueDate->modify('+' . self::GRACE_PERIOD_DAYS . ' days');

        $this->logger->info('Payment due date calculated', [
            'payment_id' => $payment->getId(),
            'base_due_date' => $baseDueDate->format('Y-m-d'),
            'early_deadline' => $earlyPaymentDeadline?->format('Y-m-d'),
        ]);

        return new DueDateResult(
            baseDueDate: $baseDueDate,
            earlyPaymentDeadline: $earlyPaymentDeadline,
            earlyPaymentDiscountAmount: $earlyPaymentDiscount,
            lateFeeThreshold: $lateFeeThreshold,
            paymentTerms: $paymentTerms,
            netDays: $netDays,
        );
    }

    private function getNetDaysForTerms(string $paymentTerms): int
    {
        return match ($paymentTerms) {
            'net_30' => self::PAYMENT_TERMS_NET_30,
            'net_45' => self::PAYMENT_TERMS_NET_45,
            'net_60' => self::PAYMENT_TERMS_NET_60,
            'net_90' => self::PAYMENT_TERMS_NET_90,
            'due_on_receipt' => self::PAYMENT_TERMS_DUE_ON_RECEIPT,
            '2_10_net_30' => self::PAYMENT_TERMS_2_10_NET_30,
            default => self::PAYMENT_TERMS_NET_30,
        };
    }

    private function hasEarlyPaymentDiscount(string $paymentTerms): bool
    {
        return in_array($paymentTerms, ['2_10_net_30', 'net_10', 'net_15']);
    }

    private function adjustForWeekend(\DateTimeInterface $date): \DateTimeImmutable
    {
        $dayOfWeek = (int)$date->format('N');

        if ($dayOfWeek === 6) {
            return \DateTimeImmutable::createFromInterface($date)->modify('+2 days');
        }

        if ($dayOfWeek === 7) {
            return \DateTimeImmutable::createFromInterface($date)->modify('+1 days');
        }

        return \DateTimeImmutable::createFromInterface($date);
    }

    public function calculateLateFee(Payment $payment, \DateTimeImmutable $asOfDate): ?LateFeeResult
    {
        $dueDateResult = $this->calculateDueDate($payment);
        $lateFeeThreshold = $dueDateResult->lateFeeThreshold;

        if ($asOfDate <= $lateFeeThreshold) {
            return null;
        }

        $daysLate = (int)((($asOfDate->getTimestamp() - $lateFeeThreshold->getTimestamp()) / 86400));
        $paymentAmount = $payment->getAmount();

        $lateFeePercentage = self::LATE_FEE_PERCENTAGE * min($daysLate, 30);
        $lateFeeAmount = max($paymentAmount * $lateFeePercentage, self::LATE_FEE_MINIMUM);

        return new LateFeeResult(
            amount: $lateFeeAmount,
            daysLate: $daysLate,
            calculatedAt: $asOfDate,
        );
    }

    public function calculateInterestAccrual(Payment $payment, \DateTimeImmutable $asOfDate): InterestResult
    {
        $dueDateResult = $this->calculateDueDate($payment);
        $dueDate = $dueDateResult->baseDueDate;

        if ($asOfDate <= $dueDate) {
            return new InterestResult(
                principal: $payment->getAmount(),
                interestAccrued: 0.0,
                totalOwed: $payment->getAmount(),
                daysOverdue: 0,
            );
        }

        $daysOverdue = (int)((($asOfDate->getTimestamp() - $dueDate->getTimestamp()) / 86400));
        $principal = $payment->getAmount();
        $annualRate = 0.12;
        $interestRate = $annualRate / 365;

        $interestAccrued = $principal * $interestRate * $daysOverdue;
        $totalOwed = $principal + $interestAccrued;

        return new InterestResult(
            principal: $principal,
            interestAccrued: $interestAccrued,
            totalOwed: $totalOwed,
            daysOverdue: $daysOverdue,
        );
    }
}
