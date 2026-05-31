<?php

declare(strict_types=1);

namespace App\Utilities\Date;

use DateTime;
use DateInterval;
use Exception;

final class BookingDateService
{
    private const BOOKING_MIN_DAYS = 1;
    private const BOOKING_MAX_DAYS = 180;
    private const EXCLUDE_WEEKENDS = true;
    private const ALLOW_WEEKDAYS_ONLY = true;
    private const OFF_DAYS = [0, 6];
    private const HOLIDAYS = [
        '2024-01-01', '2024-04-01', '2024-04-02', '2024-05-01',
        '2024-10-03', '2024-12-24', '2024-12-25', '2024-12-31',
        '2025-01-01', '2025-04-01', '2025-04-02', '2025-05-01',
        '2025-10-03', '2025-12-24', '2025-12-25', '2025-12-31',
    ];
    private const START_HOUR = 9;
    private const END_HOUR = 17;
    private const DATE_FMT = 'Y-m-d';
    private const DATETIME_FMT = 'Y-m-d H:i:s';
    private const UTC_ZONE = 'UTC';

    private string $timezone;
    private array $additionalHolidays = [];

    public function __construct(string $timezone = self::UTC_ZONE)
    {
        $this->timezone = $timezone;
    }

    public function validateBookingPeriod(string $checkIn, string $checkOut): array
    {
        $in = $this->toDateTime($checkIn);
        $out = $this->toDateTime($checkOut);

        if ($in === null || $out === null) {
            throw new Exception('Invalid date format');
        }

        if ($in >= $out) {
            throw new Exception('Check-out must be after check-in');
        }

        $duration = $this->daysDifference($in, $out);

        if ($duration < self::BOOKING_MIN_DAYS) {
            throw new Exception('Minimum booking duration is 1 day');
        }

        if ($duration > self::BOOKING_MAX_DAYS) {
            throw new Exception(sprintf('Maximum booking duration is %d days', self::BOOKING_MAX_DAYS));
        }

        if (!$this->isValidBookingTime($in) || !$this->isValidBookingTime($out)) {
            throw new Exception('Booking times must be within operating hours');
        }

        return [
            'check_in' => $in,
            'check_out' => $out,
            'duration' => $duration,
            'valid' => true,
        ];
    }

    public function getAvailableDates(string $from, string $to): array
    {
        $start = $this->toDateTime($from);
        $end = $this->toDateTime($to);

        if ($start === null || $end === null) {
            return [];
        }

        $available = [];
        $current = clone $start;

        while ($current <= $end) {
            if ($this->isAvailableDay($current)) {
                $available[] = $current->format(self::DATE_FMT);
            }
            $current->add(new DateInterval('P1D'));
        }

        return $available;
    }

    public function calculateNights(string $from, string $to): int
    {
        $start = $this->toDateTime($from);
        $end = $this->toDateTime($to);

        if ($start === null || $end === null) {
            return 0;
        }

        $nights = 0;
        $current = clone $start;

        while ($current < $end) {
            if ($this->countsTowardsStay($current)) {
                $nights++;
            }
            $current->add(new DateInterval('P1D'));
        }

        return $nights;
    }

    public function calculateCost(string $from, string $to, float $nightlyRate): float
    {
        $nights = $this->calculateNights($from, $to);
        return $nights * $nightlyRate;
    }

    public function isAvailableDay(DateTime $date): bool
    {
        if (self::EXCLUDE_WEEKENDS && $this->isClosedDay($date)) {
            return false;
        }

        if ($this->isBlockedHoliday($date)) {
            return false;
        }

        return true;
    }

    public function isClosedDay(DateTime $date): bool
    {
        return in_array((int) $date->format('w'), self::OFF_DAYS, true);
    }

    public function isBlockedHoliday(DateTime $date): bool
    {
        $formatted = $date->format(self::DATE_FMT);

        if (in_array($formatted, self::HOLIDAYS, true)) {
            return true;
        }

        if (in_array($formatted, $this->additionalHolidays, true)) {
            return true;
        }

        return false;
    }

    public function getNextAvailable(string $from): string
    {
        $start = $this->toDateTime($from);

        if ($start === null) {
            throw new Exception('Invalid date');
        }

        $current = clone $start;

        while (!$this->isAvailableDay($current)) {
            $current->add(new DateInterval('P1D'));
        }

        return $current->format(self::DATE_FMT);
    }

    public function getBlockedDates(string $from, string $to): array
    {
        $start = $this->toDateTime($from);
        $end = $this->toDateTime($to);

        if ($start === null || $end === null) {
            return [];
        }

        $blocked = [];
        $current = clone $start;

        while ($current <= $end) {
            if (!$this->isAvailableDay($current)) {
                $blocked[] = [
                    'date' => $current->format(self::DATE_FMT),
                    'reason' => $this->getBlockReason($current),
                ];
            }
            $current->add(new DateInterval('P1D'));
        }

        return $blocked;
    }

    private function countsTowardsStay(DateTime $date): bool
    {
        if (!self::ALLOW_WEEKDAYS_ONLY) {
            return true;
        }

        return !$this->isClosedDay($date);
    }

    private function isValidBookingTime(DateTime $date): bool
    {
        $hour = (int) $date->format('H');
        return $hour >= self::START_HOUR && $hour < self::END_HOUR;
    }

    private function toDateTime(string $date): ?DateTime
    {
        $parsed = DateTime::createFromFormat(self::DATE_FMT, $date, new DateTimeZone($this->timezone));

        if ($parsed === false) {
            $parsed = DateTime::createFromFormat(self::DATETIME_FMT, $date, new DateTimeZone($this->timezone));
        }

        return $parsed !== false ? $parsed : null;
    }

    private function daysDifference(DateTime $from, DateTime $to): int
    {
        $diff = $from->diff($to);
        return (int) $diff->days;
    }

    private function getBlockReason(DateTime $date): string
    {
        if ($this->isClosedDay($date)) {
            return 'Closed on weekends';
        }

        if ($this->isBlockedHoliday($date)) {
            return 'Holiday - closed';
        }

        return 'Unavailable';
    }

    public function blockDate(string $date): void
    {
        if (!in_array($date, $this->additionalHolidays, true)) {
            $this->additionalHolidays[] = $date;
        }
    }

    public function getBlockedHolidays(): array
    {
        return array_unique(array_merge(self::HOLIDAYS, $this->additionalHolidays));
    }
}
