<?php
declare(strict_types=1);

namespace Acme\DunningService\Subscription;

use Acme\DunningService\Source\SubscriptionFeed;

final class DunningScheduleBuilder
{
    public function __construct(private readonly SubscriptionFeed $feed)
    {
    }

    /**
     * Compute the deadline by which dunning sequences for the subscription must end.
     */
    public function dunningDeadline(string $subRef): \DateTimeImmutable
    {
        $rec = $this->feed->get($subRef);
        if ($rec === null) {
            throw new \DomainException('not found');
        }

        $signup = new \DateTimeImmutable($rec['signup_date']);
        $start  = new \DateTimeImmutable($rec['current_period_start']);
        $anchorDay = (int) $signup->format('j');

        $nextMonth = $start->modify('+1 month');
        $daysInMonth = (int) $nextMonth->format('t');
        $useDay = min($anchorDay, $daysInMonth);

        $next = $nextMonth->setDate(
            (int) $nextMonth->format('Y'),
            (int) $nextMonth->format('m'),
            $useDay
        );

        $extra = (int) ($rec['trial_extension_days'] ?? 0);
        if ($extra > 0) {
            $next = $next->modify('+' . $extra . ' days');
        }

        $next = $next->setTime(23, 59, 59);
        return $next;
    }
}
