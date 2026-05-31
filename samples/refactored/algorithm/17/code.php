<?php
declare(strict_types=1);

namespace BillingEngine\Shared;

final class PaymentTermConstants
{
    public const NET_30 = 30;
    public const NET_45 = 45;
    public const NET_60 = 60;
    public const NET_90 = 90;
    public const DUE_ON_RECEIPT = 0;
    public const NET_10 = 10;
    public const NET_15 = 15;
    public const TWO_10_NET_30 = 30;

    public const EARLY_DISCOUNT_PERCENT = 0.02;
    public const EARLY_DISCOUNT_DAYS = 10;

    public const LATE_FEE_PERCENT = 0.015;
    public const LATE_FEE_MIN = 25.00;
    public const GRACE_PERIOD_DAYS = 5;
}

interface DueDateCalculatorInterface
{
    public function calculateDueDate(mixed $entity): DueDateResult;
}

abstract class BaseDueDateCalculator implements DueDateCalculatorInterface
{
    protected LoggerInterface $logger;
    protected bool $adjustForWeekend = true;

    public function calculateDueDate(mixed $entity): DueDateResult
    {
        $issueDate = $this->getIssueDate($entity);
        $paymentTerms = $this->getPaymentTerms($entity);
        $netDays = $this->getNetDaysForTerms($paymentTerms);

        $baseDueDate = $issueDate->modify("+{$netDays} days");

        if ($this->adjustForWeekend) {
            $baseDueDate = $this->adjustForWeekend($baseDueDate);
        }

        $earlyDeadline = null;
        $earlyDiscount = null;

        if ($this->hasEarlyPaymentDiscount($paymentTerms)) {
            $earlyDeadline = $issueDate->modify('+' . PaymentTermConstants::EARLY_DISCOUNT_DAYS . ' days');
            $earlyDeadline = $this->adjustForWeekend($earlyDeadline);
            $earlyDiscount = $this->calculateEarlyDiscount($entity);
        }

        $lateFeeThreshold = $baseDueDate->modify('+' . PaymentTermConstants::GRACE_PERIOD_DAYS . ' days');

        return new DueDateResult(
            baseDueDate: $baseDueDate,
            earlyPaymentDeadline: $earlyDeadline,
            earlyPaymentDiscountAmount: $earlyDiscount,
            lateFeeThreshold: $lateFeeThreshold,
            paymentTerms: $paymentTerms,
            netDays: $netDays,
        );
    }

    abstract protected function getIssueDate(mixed $entity): \DateTimeImmutable;
    abstract protected function getPaymentTerms(mixed $entity): string;

    protected function getNetDaysForTerms(string $terms): int
    {
        return match ($terms) {
            'net_30' => PaymentTermConstants::NET_30,
            'net_45' => PaymentTermConstants::NET_45,
            'net_60' => PaymentTermConstants::NET_60,
            'net_90' => PaymentTermConstants::NET_90,
            'due_on_receipt' => PaymentTermConstants::DUE_ON_RECEIPT,
            '2_10_net_30' => PaymentTermConstants::TWO_10_NET_30,
            default => PaymentTermConstants::NET_30,
        };
    }

    protected function hasEarlyPaymentDiscount(string $terms): bool
    {
        return in_array($terms, ['2_10_net_30', 'net_10', 'net_15']);
    }

    protected function adjustForWeekend(\DateTimeInterface $date): \DateTimeImmutable
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

    abstract protected function calculateEarlyDiscount(mixed $entity): float;
}

final class InvoiceDueDateCalculator extends BaseDueDateCalculator
{
    protected function getIssueDate(mixed $entity): \DateTimeImmutable
    {
        return $entity->getIssueDate();
    }

    protected function getPaymentTerms(mixed $entity): string
    {
        return $entity->getPaymentTerms();
    }

    protected function calculateEarlyDiscount(mixed $entity): float
    {
        return $entity->getTotalAmount() * PaymentTermConstants::EARLY_DISCOUNT_PERCENT;
    }
}
