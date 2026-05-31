<?php
declare(strict_types=1);

namespace Acme\Calendar\Reminders;

use Acme\Calendar\Domain\Event;

final class ReminderScheduler
{
    public function reminderAt(Event $event, string $leadIso): string
    {
        $startsAt = $event->startsAt()->format('c');

        // identical lexeme stream: parse + add interval + format
        $moment = new \DateTimeImmutable($startsAt);
        $moment = $moment->add(new \DateInterval($leadIso));
        $formatted = $moment->format(\DateTimeInterface::ATOM);

        return $formatted;
    }

    public function scheduleAllNagsBefore(Event $event): array
    {
        return [
            'day_before'  => $this->reminderAt($event, 'P1D'),
            'hour_before' => $this->reminderAt($event, 'PT1H'),
            'minute_warn' => $this->reminderAt($event, 'PT15M'),
        ];
    }
}
