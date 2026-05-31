<?php
declare(strict_types=1);

namespace Acme\Common\Subscription;

/**
 * acme/subscription-clock — the canonical renewal-date oracle. Billing,
 * AccessControl, and Dunning each pass a SubscriptionSchedule and trust the
 * resulting renewal moment so cycle boundaries align across services.
 */
final class RenewalDatePolicy
{
    public function nextRenewal(SubscriptionSchedule $schedule): \DateTimeImmutable
    {
        $anchorDay = (int) $schedule->signupDate->format('j');
        $next = $schedule->currentPeriodStart->modify('+1 month');

        $maxDay = (int) $next->format('t');
        $day = min($anchorDay, $maxDay);

        $next = $next->setDate(
            (int) $next->format('Y'),
            (int) $next->format('m'),
            $day
        );

        if ($schedule->trialExtensionDays > 0) {
            $next = $next->modify('+' . $schedule->trialExtensionDays . ' days');
        }

        return $next->setTime(23, 59, 59);
    }
}
