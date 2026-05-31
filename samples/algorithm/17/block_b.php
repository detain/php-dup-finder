<?php
declare(strict_types=1);

namespace BillingEngine\Subscription;

use Psr\Log\LoggerInterface;

final class SubscriptionRenewalDateCalculator
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

    private const ANNUAL_BILLING_MONTH = 1;
    private const QUARTERLY_BILLING_MONTHS = [1, 4, 7, 10];
    private const MONTHLY_BILLING_DAY = 1;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function calculateNextRenewalDate(Subscription $subscription): RenewalDateResult
    {
        $this->logger->debug('Calculating subscription renewal date', [
            'subscription_id' => $subscription->getId(),
            'start_date' => $subscription->getStartDate()->format('Y-m-d'),
            'billing_cycle' => $subscription->getBillingCycle(),
        ]);

        $startDate = $subscription->getStartDate();
        $billingCycle = $subscription->getBillingCycle();
        $currentPeriodEnd = $subscription->getCurrentPeriodEnd();

        $nextRenewalDate = $this->calculateNextRenewal($startDate, $currentPeriodEnd, $billingCycle);

        if (self::WEEKEND_ADJUSTMENT_ENABLED) {
            $nextRenewalDate = $this->adjustForWeekend($nextRenewalDate);
        }

        $autoRenew = $subscription->isAutoRenewEnabled();
        $requiresNotice = $this->requiresCancellationNotice($billingCycle);
        $noticeDeadline = null;

        if ($requiresNotice) {
            $noticeDays = $this->getCancellationNoticeDays($billingCycle);
            $noticeDeadline = $nextRenewalDate->modify("-{$noticeDays} days");
        }

        $this->logger->info('Renewal date calculated', [
            'subscription_id' => $subscription->getId(),
            'next_renewal' => $nextRenewalDate->format('Y-m-d'),
            'auto_renew' => $autoRenew,
        ]);

        return new RenewalDateResult(
            nextRenewalDate: $nextRenewalDate,
            autoRenewEnabled: $autoRenew,
            cancellationNoticeDeadline: $noticeDeadline,
            billingCycle: $billingCycle,
        );
    }

    private function calculateNextRenewal(\DateTimeImmutable $startDate, \DateTimeImmutable $currentEnd, string $billingCycle): \DateTimeImmutable
    {
        $interval = match ($billingCycle) {
            'annual' => new \DateInterval('P1Y'),
            'quarterly' => new \DateInterval('P3M'),
            'monthly' => new \DateInterval('P1M'),
            'biennial' => new \DateInterval('P2Y'),
            default => new \DateInterval('P1M'),
        };

        $nextDate = $currentEnd->add($interval);

        $annualBillingMonth = self::ANNUAL_BILLING_MONTH;
        $quarterlyBillingMonths = self::QUARTERLY_BILLING_MONTHS;

        return $nextDate;
    }

    private function requiresCancellationNotice(string $billingCycle): bool
    {
        return in_array($billingCycle, ['annual', 'biennial', 'quarterly']);
    }

    private function getCancellationNoticeDays(string $billingCycle): int
    {
        return match ($billingCycle) {
            'annual' => 30,
            'biennial' => 60,
            'quarterly' => 15,
            'monthly' => 5,
            default => 30,
        };
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

    public function calculateProration(Subscription $subscription, \DateTimeImmutable $changeDate): ProrationResult
    {
        $currentPeriodEnd = $subscription->getCurrentPeriodEnd();
        $daysRemaining = (int)(($currentPeriodEnd->getTimestamp() - $changeDate->getTimestamp()) / 86400);
        $totalDays = (int)(($currentPeriodEnd->getTimestamp() - $subscription->getCurrentPeriodStart()->getTimestamp()) / 86400);

        $proratedPercent = $totalDays > 0 ? ($daysRemaining / $totalDays) * 100 : 0;

        return new ProrationResult(
            daysRemaining: $daysRemaining,
            totalDaysInPeriod: $totalDays,
            proratedPercent: $proratedPercent,
        );
    }
}
