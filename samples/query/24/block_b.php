<?php

declare(strict_types=1);

namespace App\Services\Date;

use DateTime;
use DateInterval;
use App\Exceptions\DateValidationException;

final class DateRangeCalculator
{
    private const MIN_START_DAYS = 1;
    private const MAX_RANGE_DAYS = 365;
    private const SKIP_WEEKENDS = true;
    private const COUNT_WEEKEND_DAYS = false;
    private const WEEKEND_INDEXES = [0, 6];
    private const FEDERAL_HOLIDAYS = [
        '2024-01-01', '2024-01-15', '2024-02-19', '2024-05-27',
        '2024-07-04', '2024-09-02', '2024-11-28', '2024-12-25',
        '2025-01-01', '2025-01-20', '2025-02-17', '2025-05-26',
        '2025-07-04', '2025-09-01', '2025-11-27', '2025-12-25',
    ];
    private const OPERATING_HOURS_START = 8;
    private const OPERATING_HOURS_END = 18;
    private const DATE_ONLY_FORMAT = 'Y-m-d';
    private const DATETIME_FORMAT = 'Y-m-d H:i:s';
    private const TIMEZONE_DEFAULT = 'America/New_York';

    private string $timezone;
    private array $customHolidays;

    public function __construct(string $timezone = self::TIMEZONE_DEFAULT)
    {
        $this->timezone = $timezone;
        $this->customHolidays = [];
    }

    public function validateDateRange(string $from, string $to): array
    {
        $start = $this->createDateTime($from);
        $end = $this->createDateTime($to);

        if ($start === null || $end === null) {
            throw new DateValidationException('Invalid date format provided');
        }

        if (!$this->isChronological($start, $end)) {
            throw new DateValidationException('From date must precede to date');
        }

        $totalDays = $this->getDayDifference($start, $end);

        if ($totalDays < self::MIN_START_DAYS) {
            throw new DateValidationException('Minimum range is 1 day');
        }

        if ($totalDays > self::MAX_RANGE_DAYS) {
            throw new DateValidationException(
                sprintf('Maximum range of %d days exceeded', self::MAX_RANGE_DAYS)
            );
        }

        if (!$this->isDuringOperatingHours($start) || !$this->isDuringOperatingHours($end)) {
            throw new DateValidationException('Dates must be during operating hours');
        }

        return [
            'from' => $start,
            'to' => $end,
            'total_days' => $totalDays,
            'is_valid' => true,
        ];
    }

    public function countWorkingDays(string $from, string $to): int
    {
        $start = $this->createDateTime($from);
        $end = $this->createDateTime($to);

        if ($start === null || $end === null) {
            return 0;
        }

        $workingDays = 0;
        $current = clone $start;

        while ($current <= $end) {
            if ($this->isWorkingDay($current)) {
                $workingDays++;
            }
            $current->add(new DateInterval('P1D'));
        }

        return $workingDays;
    }

    public function countWeekendDays(string $from, string $to): int
    {
        $start = $this->createDateTime($from);
        $end = $this->createDateTime($to);

        if ($start === null || $end === null) {
            return 0;
        }

        $weekendDays = 0;
        $current = clone $start;

        while ($current <= $end) {
            if ($this->isWeekendDay($current)) {
                $weekendDays++;
            }
            $current->add(new DateInterval('P1D'));
        }

        return $weekendDays;
    }

    public function isWorkingDay(DateTime $date): bool
    {
        if ($this->isWeekendDay($date)) {
            return false;
        }

        return !$this->isHoliday($date);
    }

    public function isWeekendDay(DateTime $date): bool
    {
        $weekday = (int) $date->format('w');
        return in_array($weekday, self::WEEKEND_INDEXES, true);
    }

    public function isHoliday(DateTime $date): bool
    {
        $dateStr = $date->format(self::DATE_ONLY_FORMAT);

        if (in_array($dateStr, self::FEDERAL_HOLIDAYS, true)) {
            return true;
        }

        if (in_array($dateStr, $this->customHolidays, true)) {
            return true;
        }

        return false;
    }

    public function getWorkingDaysBetween(string $from, string $to): array
    {
        $start = $this->createDateTime($from);
        $end = $this->createDateTime($to);

        if ($start === null || $end === null) {
            return [];
        }

        $workingDays = [];
        $current = clone $start;

        while ($current <= $end) {
            if ($this->isWorkingDay($current)) {
                $workingDays[] = $current->format(self::DATE_ONLY_FORMAT);
            }
            $current->add(new DateInterval('P1D'));
        }

        return $workingDays;
    }

    public function subtractWorkingDays(string $date, int $days): string
    {
        $result = $this->createDateTime($date);

        if ($result === null) {
            throw new DateValidationException('Invalid date provided');
        }

        $subtracted = 0;

        while ($subtracted < $days) {
            $result->sub(new DateInterval('P1D'));

            if ($this->isWorkingDay($result)) {
                $subtracted++;
            }
        }

        return $result->format(self::DATE_ONLY_FORMAT);
    }

    public function addWorkingDays(string $date, int $days): string
    {
        $result = $this->createDateTime($date);

        if ($result === null) {
            throw new DateValidationException('Invalid date provided');
        }

        $added = 0;

        while ($added < $days) {
            $result->add(new DateInterval('P1D'));

            if ($this->isWorkingDay($result)) {
                $added++;
            }
        }

        return $result->format(self::DATE_ONLY_FORMAT);
    }

    private function createDateTime(string $date): ?DateTime
    {
        $parsed = DateTime::createFromFormat(self::DATE_ONLY_FORMAT, $date, new DateTimeZone($this->timezone));

        if ($parsed === false) {
            $parsed = DateTime::createFromFormat(self::DATETIME_FORMAT, $date, new DateTimeZone($this->timezone));
        }

        return $parsed !== false ? $parsed : null;
    }

    private function isChronological(DateTime $from, DateTime $to): bool
    {
        return $from < $to;
    }

    private function getDayDifference(DateTime $from, DateTime $to): int
    {
        $interval = $from->diff($to);
        return (int) $interval->days;
    }

    private function isDuringOperatingHours(DateTime $date): bool
    {
        $hour = (int) $date->format('H');
        return $hour >= self::OPERATING_HOURS_START && $hour < self::OPERATING_HOURS_END;
    }

    public function registerHoliday(string $date): void
    {
        if (!in_array($date, $this->customHolidays, true)) {
            $this->customHolidays[] = $date;
        }
    }

    public function getAllHolidays(): array
    {
        return array_unique(array_merge(self::FEDERAL_HOLIDAYS, $this->customHolidays));
    }
}
