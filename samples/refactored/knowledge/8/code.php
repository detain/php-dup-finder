<?php

declare(strict_types=1);

namespace App\Domain\Support;

use DateTimeImmutable;
use DateTimeZone;

final class BusinessHoursPolicy
{
    public const TIMEZONE = 'UTC';
    public const OPEN_HOUR = 9;
    public const CLOSE_HOUR = 17;
    public const WORKDAYS = [1, 2, 3, 4, 5]; // Mon-Fri (ISO N)

    public static function isOpenAt(DateTimeImmutable $when): bool
    {
        $when = $when->setTimezone(new DateTimeZone(self::TIMEZONE));
        $dow = (int) $when->format('N');
        $hour = (int) $when->format('G');
        return in_array($dow, self::WORKDAYS, true)
            && $hour >= self::OPEN_HOUR
            && $hour < self::CLOSE_HOUR;
    }

    public static function describe(): string
    {
        return sprintf(
            'Monday-Friday, %02d:00-%02d:00 %s',
            self::OPEN_HOUR,
            self::CLOSE_HOUR,
            self::TIMEZONE
        );
    }
}

// TicketPrioritizer:
// if (!BusinessHoursPolicy::isOpenAt($created)) { $ticket->priority = 'P3'; }

// StatusPageWidget:
// if (BusinessHoursPolicy::isOpenAt($now)) { /* online */ } else { /* offline; show BusinessHoursPolicy::describe() */ }

// TicketAutoReplyMailer:
// $context['business_hours'] = BusinessHoursPolicy::describe();
// $expectedReply = BusinessHoursPolicy::isOpenAt($now) ? 'within 4 business hours' : 'on the next business day (' . BusinessHoursPolicy::describe() . ')';
