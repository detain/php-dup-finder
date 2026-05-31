<?php

declare(strict_types=1);

namespace App\Services\Scheduling;

use DateTime;
use DateInterval;
use DatePeriod;
use DateTimeZone;
use App\Exceptions\InvalidDateRangeException;

final class DateRangeValidator
{
    private const DEFAULT_TIMEZONE = 'UTC';
    private const MAX_RANGE_DAYS = 365;
    private const MIN_RANGE_DAYS = 1;
    private const BUSINESS_DAYS_ONLY = false;
    private const INCLUDE_WEEKENDS = true;
    private const WEEKEND_DAYS = [0, 6];
    private const HOLIDAY_CALENDAR = [
        '2024-01-01', '2024-07-04', '2024-11-28', '2024-12-25',
        '2025-01-01', '2025-07-04', '2025-11-27', '2025-12-25',
        '2026-01-01', '2026-07-04', '2026-11-26', '2026-12-25',
    ];
    private const BUSINESS_HOURS_START = 9;
    private const BUSINESS_HOURS_END = 17;
    private const DEFAULT_DATE_FORMAT = 'Y-m-d';
    private const DEFAULT_DATETIME_FORMAT = 'Y-m-d H:i:s';

    private string $timezone;
    private array $holidays;

    public function __construct(?string $timezone = null)
    {
        $this->timezone = $timezone ?? self::DEFAULT_TIMEZONE;
        $this->holidays = self::HOLIDAY_CALENDAR;
    }

    public function validateRange(string $startDate, string $endDate): array
    {
        $start = $this->parseDate($startDate);
        $end = $this->parseDate($endDate);

        if ($start === null || $end === null) {
            throw new InvalidDateRangeException('Invalid date format provided');
        }

        if (!$this->isStartBeforeEnd($start, $end)) {
            throw new InvalidDateRangeException('Start date must be before end date');
        }

        $diff = $this->calculateDaysBetween($start, $end);

        if ($diff < self::MIN_RANGE_DAYS) {
            throw new InvalidDateRangeException(
                sprintf('Date range must be at least %d day(s)', self::MIN_RANGE_DAYS)
            );
        }

        if ($diff > self::MAX_RANGE_DAYS) {
            throw new InvalidDateRangeException(
                sprintf('Date range cannot exceed %d days', self::MAX_RANGE_DAYS)
            );
        }

        if (!$this->isWithinBusinessHours($start) || !$this->isWithinBusinessHours($end)) {
            throw new InvalidDateRangeException('Dates must be within business hours');
        }

        return [
            'start' => $start,
            'end' => $end,
            'days' => $diff,
            'valid' => true,
        ];
    }

    public function calculateBusinessDays(string $startDate, string $endDate): int
    {
        $start = $this->parseDate($startDate);
        $end = $this->parseDate($endDate);

        if ($start === null || $end === null) {
            return 0;
        }

        $businessDays = 0;
        $current = clone $start;

        while ($current <= $end) {
            if ($this->isBusinessDay($current)) {
                $businessDays++;
            }
            $current->modify('+1 day');
        }

        return $businessDays;
    }

    public function addBusinessDays(string $date, int $days): string
    {
        $result = $this->parseDate($date);

        if ($result === null) {
            throw new InvalidDateRangeException('Invalid start date');
        }

        $added = 0;
        $direction = $days >= 0 ? 1 : -1;
        $days = abs($days);

        while ($added < $days) {
            $result->modify(($direction > 0 ? '+1 day' : '-1 day'));

            if ($this->isBusinessDay($result)) {
                $added++;
            }
        }

        return $result->format(self::DEFAULT_DATE_FORMAT);
    }

    public function isBusinessDay(DateTime $date): bool
    {
        $dayOfWeek = (int) $date->format('w');

        if (in_array($dayOfWeek, self::WEEKEND_DAYS, true)) {
            return false;
        }

        $dateString = $date->format('Y-m-d');

        if (in_array($dateString, $this->holidays, true)) {
            return false;
        }

        return true;
    }

    public function isWeekend(DateTime $date): bool
    {
        $dayOfWeek = (int) $date->format('w');
        return in_array($dayOfWeek, self::WEEKEND_DAYS, true);
    }

    public function isHoliday(DateTime $date): bool
    {
        return in_array($date->format('Y-m-d'), $this->holidays, true);
    }

    public function getNextBusinessDay(string $date): string
    {
        $result = $this->parseDate($date);

        if ($result === null) {
            throw new InvalidDateRangeException('Invalid date');
        }

        do {
            $result->modify('+1 day');
        } while (!$this->isBusinessDay($result));

        return $result->format(self::DEFAULT_DATE_FORMAT);
    }

    public function getPreviousBusinessDay(string $date): string
    {
        $result = $this->parseDate($date);

        if ($result === null) {
            throw new InvalidDateRangeException('Invalid date');
        }

        do {
            $result->modify('-1 day');
        } while (!$this->isBusinessDay($result));

        return $result->format(self::DEFAULT_DATE_FORMAT);
    }

    public function getBusinessDaysBetween(string $startDate, string $endDate): array
    {
        $start = $this->parseDate($startDate);
        $end = $this->parseDate($endDate);

        if ($start === null || $end === null) {
            return [];
        }

        $businessDays = [];
        $current = clone $start;

        while ($current <= $end) {
            if ($this->isBusinessDay($current)) {
                $businessDays[] = $current->format(self::DEFAULT_DATE_FORMAT);
            }
            $current->modify('+1 day');
        }

        return $businessDays;
    }

    private function parseDate(string $date): ?DateTime
    {
        $parsed = DateTime::createFromFormat(self::DEFAULT_DATE_FORMAT, $date, new DateTimeZone($this->timezone));

        if ($parsed === false) {
            $parsed = DateTime::createFromFormat(self::DEFAULT_DATETIME_FORMAT, $date, new DateTimeZone($this->timezone));
        }

        return $parsed;
    }

    private function isStartBeforeEnd(DateTime $start, DateTime $end): bool
    {
        return $start < $end;
    }

    private function calculateDaysBetween(DateTime $start, DateTime $end): int
    {
        $diff = $start->diff($end);
        return (int) $diff->days;
    }

    private function isWithinBusinessHours(DateTime $date): bool
    {
        $hour = (int) $date->format('H');

        return $hour >= self::BUSINESS_HOURS_START && $hour < self::BUSINESS_HOURS_END;
    }

    public function addHoliday(string $date): void
    {
        $this->holidays[] = $date;
    }

    public function getHolidays(): array
    {
        return $this->holidays;
    }
}
