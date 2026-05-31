<?php

declare(strict_types=1);

namespace Clinic\Booking\Rules;

use Clinic\Booking\Booking;
use Clinic\Booking\BookingRepository;
use Clinic\Schedule\BusinessHours;
use Clinic\Schedule\BlackoutCalendar;

final class SlotAvailabilityChecker
{
    public function __construct(
        private BookingRepository $bookings,
        private BusinessHours $hours,
        private BlackoutCalendar $blackouts,
    ) {
    }

    public function isBookable(int $providerId, \DateTimeImmutable $start, \DateTimeImmutable $end): bool
    {
        if ($start >= $end) {
            return false;
        }

        if (!$this->hours->coversInterval($providerId, $start, $end)) {
            return false;
        }

        if ($this->blackouts->intersects($providerId, $start, $end)) {
            return false;
        }

        $existing = $this->bookings->forProviderOnDay($providerId, $start);
        foreach ($existing as $booking) {
            if ($booking->start() < $end && $start < $booking->end()) {
                return false;
            }
        }

        return true;
    }
}
