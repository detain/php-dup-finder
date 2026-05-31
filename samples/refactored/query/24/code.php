<?php

declare(strict_types=1);

namespace App\Utilities\Date;

use DateTime;
use DateInterval;

abstract class DateRangeHandler
{
    protected const DATE_FORMAT = 'Y-m-d';
    protected const DATETIME_FORMAT = 'Y-m-d H:i:s';
    protected const MIN_RANGE_DAYS = 1;
    protected const MAX_RANGE_DAYS = 365;
    protected const WEEKEND_DAYS = [0, 6];

    protected string $timezone;

    abstract protected function isHoliday(DateTime $date): bool;
    abstract protected function getHolidays(): array;

    public function validateRange(string $start, string $end): array
    {
        $startDate = $this->parseDate($start);
        $endDate = $this->parseDate($end);

        if ($startDate >= $endDate) {
            throw new \InvalidArgumentException('Start must be before end');
        }

        $days = $startDate->diff($endDate)->days;

        if ($days < self::MIN_RANGE_DAYS || $days > self::MAX_RANGE_DAYS) {
            throw new \InvalidArgumentException('Range out of bounds');
        }

        return ['start' => $startDate, 'end' => $endDate, 'days' => $days, 'valid' => true];
    }

    public function isBusinessDay(DateTime $date): bool
    {
        return !in_array((int) $date->format('w'), self::WEEKEND_DAYS) && !$this->isHoliday($date);
    }

    public function addBusinessDays(DateTime $date, int $days): DateTime
    {
        $direction = $days >= 0 ? 1 : -1;
        $added = 0;

        while ($added < abs($days)) {
            $date->modify(($direction > 0 ? '+1 day' : '-1 day'));
            if ($this->isBusinessDay($date)) {
                $added++;
            }
        }

        return $date;
    }

    protected function parseDate(string $date): ?DateTime
    {
        return DateTime::createFromFormat(self::DATE_FORMAT, $date, new \DateTimeZone($this->timezone))
            ?: DateTime::createFromFormat(self::DATETIME_FORMAT, $date, new \DateTimeZone($this->timezone));
    }
}
