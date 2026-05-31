<?php
declare(strict_types=1);

namespace Acme\BillingService\Subscription;

use Acme\BillingService\Repository\SubscriptionRepository;

final class RenewalScheduler
{
    public function __construct(private readonly SubscriptionRepository $subscriptions)
    {
    }

    public function nextRenewalDate(string $subscriptionId): \DateTimeImmutable
    {
        $sub = $this->subscriptions->find($subscriptionId);
        if ($sub === null) {
            throw new \RuntimeException('subscription not found');
        }

        $anchor = (int) (new \DateTimeImmutable($sub->signupDate))->format('j');
        $current = new \DateTimeImmutable($sub->currentPeriodStart);

        $next = $current->modify('+1 month');
        $maxDayInMonth = (int) $next->format('t');
        $day = min($anchor, $maxDayInMonth);
        $next = $next->setDate(
            (int) $next->format('Y'),
            (int) $next->format('m'),
            $day
        );

        if ($sub->trialExtensionDays > 0) {
            $next = $next->modify('+' . $sub->trialExtensionDays . ' days');
        }

        $cycleEndHour = 23;
        $next = $next->setTime($cycleEndHour, 59, 59);

        return $next;
    }
}
