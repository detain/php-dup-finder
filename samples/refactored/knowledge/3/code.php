<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use DateTimeImmutable;

final class SubscriptionGracePolicy
{
    public const GRACE_PERIOD_DAYS = 7;

    public static function daysOverdue(DateTimeImmutable $periodEnd, DateTimeImmutable $now): int
    {
        return $now > $periodEnd ? (int) $now->diff($periodEnd)->days : 0;
    }

    public static function isInGrace(DateTimeImmutable $periodEnd, DateTimeImmutable $now): bool
    {
        return self::daysOverdue($periodEnd, $now) <= self::GRACE_PERIOD_DAYS;
    }

    public static function daysRemaining(DateTimeImmutable $periodEnd, DateTimeImmutable $now): int
    {
        return max(0, self::GRACE_PERIOD_DAYS - self::daysOverdue($periodEnd, $now));
    }
}

// Renewal job:
// if (SubscriptionGracePolicy::isInGrace($sub->currentPeriodEnd, $now)) { /* retry */ } else { /* cancel */ }

// Dunning scheduler:
// $daysRemaining = SubscriptionGracePolicy::daysRemaining($sub->currentPeriodEnd, $now);

// Middleware:
// if (SubscriptionGracePolicy::isInGrace($sub->currentPeriodEnd, $now)) { return $next($request); }
// return Response::redirect('/billing/update?reason=locked');
