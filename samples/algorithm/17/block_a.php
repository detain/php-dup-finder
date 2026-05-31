<?php
declare(strict_types=1);

namespace BillingEngine\Invoice;

use Psr\Log\LoggerInterface;

final class InvoiceDueDateCalculator
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

    public function calculateDueDate(Invoice $invoice): DueDateResult
    {
        $this->logger->debug('Calculating invoice due date', [
            'invoice_id' => $invoice->getId(),
            'issue_date' => $invoice->getIssueDate()->format('Y-m-d'),
            'terms' => $invoice->getPaymentTerms(),
        ]);

        $issueDate = $invoice->getIssueDate();
        $paymentTerms = $invoice->getPaymentTerms();
        $netDays = $this->getNetDaysForTerms($paymentTerms);

        $baseDueDate = $issueDate->modify("+{$netDays} days");

        if (self::WEEKEND_ADJUSTMENT_ENABLED) {
            $baseDueDate = $this->adjustForWeekend($baseDueDate);
        }

        $earlyPaymentDeadline = null;
        $earlyPaymentDiscount = null;

        if ($this->hasEarlyPaymentDiscount($paymentTerms)) {
            $earlyDeadlineDays = self::EARLY_PAYMENT_DISCOUNT_DAYS;
            $earlyPaymentDeadline = $issueDate->modify("+{$earlyDeadlineDays} days");
            $earlyPaymentDeadline = $this->adjustForWeekend($earlyPaymentDeadline);

            $invoiceTotal = $invoice->getTotalAmount();
            $earlyPaymentDiscount = $invoiceTotal * self::EARLY_PAYMENT_DISCOUNT_PERCENT;
        }

        $lateFeeThreshold = $baseDueDate->modify('+' . self::GRACE_PERIOD_DAYS . ' days');

        $this->logger->info('Due date calculated', [
            'invoice_id' => $invoice->getId(),
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

    public function calculateLateFee(Invoice $invoice, \DateTimeImmutable $asOfDate): ?LateFeeResult
    {
        $dueDateResult = $this->calculateDueDate($invoice);
        $lateFeeThreshold = $dueDateResult->lateFeeThreshold;

        if ($asOfDate <= $lateFeeThreshold) {
            return null;
        }

        $daysLate = (int)((($asOfDate->getTimestamp() - $lateFeeThreshold->getTimestamp()) / 86400));
        $invoiceTotal = $invoice->getTotalAmount();

        $lateFeePercentage = self::LATE_FEE_PERCENTAGE * min($daysLate, 30);
        $lateFeeAmount = max($invoiceTotal * $lateFeePercentage, self::LATE_FEE_MINIMUM);

        return new LateFeeResult(
            amount: $lateFeeAmount,
            daysLate: $daysLate,
            calculatedAt: $asOfDate,
        );
    }
}
