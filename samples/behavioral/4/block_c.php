<?php

declare(strict_types=1);

namespace Med\Schedule\Open;

use Clinic\Booking\Booking;
use Clinic\Booking\BookingRepository;
use Clinic\Schedule\BusinessHours;
use Clinic\Schedule\BlackoutCalendar;

final class IntervalTreeSlotChecker
{
    public function __construct(
        private BookingRepository $repository,
        private BusinessHours $hours,
        private BlackoutCalendar $blackouts,
    ) {
    }

    public function check(int $providerId, \DateTimeImmutable $from, \DateTimeImmutable $to): bool
    {
        if ($from >= $to
            || !$this->hours->coversInterval($providerId, $from, $to)
            || $this->blackouts->intersects($providerId, $from, $to)) {
            return false;
        }

        $existing = $this->repository->forProviderOnDay($providerId, $from);
        $intervals = array_map(
            static fn(Booking $b): array => [$b->start()->getTimestamp(), $b->end()->getTimestamp()],
            iterator_to_array($existing, false),
        );

        usort($intervals, static fn(array $a, array $b): int => $a[0] <=> $b[0]);

        $fromTs = $from->getTimestamp();
        $toTs   = $to->getTimestamp();

        foreach ($intervals as [$s, $e]) {
            if ($s >= $toTs) {
                break;
            }
            if ($e > $fromTs) {
                return false;
            }
        }

        return true;
    }
}
