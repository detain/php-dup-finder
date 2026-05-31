<?php

declare(strict_types=1);

namespace Clinic\Availability;

use Clinic\Booking\BookingRepository;
use Clinic\Schedule\BlackoutCalendar;
use Clinic\Schedule\BusinessHours;

final class TimeslotAvailability
{
    public function __construct(
        private BookingRepository $bookings,
        private BusinessHours $hours,
        private BlackoutCalendar $blackouts,
    ) {
    }

    public function isBookable(
        int $providerId,
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
    ): bool {
        if (!$this->validInterval($start, $end)) {
            return false;
        }

        if (!$this->hours->coversInterval($providerId, $start, $end)) {
            return false;
        }

        if ($this->blackouts->intersects($providerId, $start, $end)) {
            return false;
        }

        return !$this->bookings->hasOverlap($providerId, $start, $end);
    }

    private function validInterval(
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
    ): bool {
        return $start < $end;
    }
}
