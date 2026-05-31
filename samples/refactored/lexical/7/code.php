<?php
declare(strict_types=1);

namespace Acme\Common\Time;

/**
 * Parse an ISO moment, offset it, and emit a formatted string.
 */
final class MomentShifter
{
    public static function shift(string $iso, string $intervalSpec, string $format): string
    {
        $moment = new \DateTimeImmutable($iso);
        $moment = $moment->add(new \DateInterval($intervalSpec));
        return $moment->format($format);
    }
}

// per-domain usage
// MomentShifter::shift(
//     $subscription->startedAt()->format('c'),
//     $subscription->billingCycleIso(),
//     'Y-m-d H:i:s',
// );
//
// MomentShifter::shift($loan->checkedOutAt()->format('c'), $period, 'Y-m-d');
// MomentShifter::shift($event->startsAt()->format('c'), $leadIso, \DateTimeInterface::ATOM);
