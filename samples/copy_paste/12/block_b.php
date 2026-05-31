<?php

declare(strict_types=1);

namespace App\Analytics\Periods;

use DateTime;
use App\Exceptions\InvalidPeriodException;

final class PeriodRangeValidator
{
    private const ALLOWED_MIN_YEAR = 2000;
    private const ALLOWED_MAX_YEAR = 2100;
    private const MAX_SPAN_DAYS = 365;
    private const NO_FUTURE_DATES = true;

    public function validatePeriod(string $from, string $to): void
    {
        $start = $this->createDateTime($from);
        $finish = $this->createDateTime($to);

        $this->verifyChronologicalOrder($start, $finish);
        $this->verifyYearBounds($start, $finish);
        $this->verifySpanLimit($start, $finish);
        $this->verifyNoFutureOccurrence($start, $finish);
    }

    public function validateSingleDate(string $date): void
    {
        $parsed = $this->createDateTime($date);
        $this->verifyYearValidity($parsed);
        $this->verifyNotFuture($parsed);
    }

    public function validateWeekRange(int $year, int $week): void
    {
        $this->verifyWeekNumber($week);
        $this->verifyWeekYear($year);

        $weekStart = $this->calculateWeekStart($year, $week);
        $weekEnd = clone $weekStart;
        $weekEnd->modify('+6 days');

        $this->verifyNoFutureOccurrence($weekStart, $weekEnd);
    }

    public function validateHalfYear(int $year, int $half): void
    {
        $this->verifyHalfNumber($half);
        $this->verifyHalfYearValidity($year);

        $startMonth = $half === 1 ? 1 : 7;
        $endMonth = $half === 1 ? 6 : 12;

        $start = new DateTime("{$year}-{$startMonth}-01");
        $end = new DateTime("{$year}-{$endMonth}-" . date('t', $end->getTimestamp()));

        $this->verifyNoFutureOccurrence($start, $end);
    }

    public function validateCustomRange(string $start, string $end, int $maxDays): void
    {
        $from = $this->createDateTime($start);
        $to = $this->createDateTime($end);

        $this->verifyChronologicalOrder($from, $to);
        $this->verifyYearBounds($from, $to);
        $this->verifyCustomSpan($from, $to, $maxDays);
        $this->verifyNoFutureOccurrence($from, $to);
    }

    private function createDateTime(string $date): DateTime
    {
        $dt = DateTime::createFromFormat('Y-m-d', $date);

        if ($dt === false) {
            $dt = DateTime::createFromFormat('Y/m/d', $date);
        }

        if ($dt === false) {
            throw new InvalidPeriodException("Cannot parse date: {$date}");
        }

        $dt->setTime(0, 0, 0);
        return $dt;
    }

    private function verifyChronologicalOrder(DateTime $from, DateTime $to): void
    {
        if ($from > $to) {
            throw new InvalidPeriodException('Start date must precede end date');
        }
    }

    private function verifyYearBounds(DateTime $from, DateTime $to): void
    {
        $fromYear = (int) $from->format('Y');
        $toYear = (int) $to->format('Y');

        if ($fromYear < self::ALLOWED_MIN_YEAR || $toYear > self::ALLOWED_MAX_YEAR) {
            throw new InvalidPeriodException(
                "Year must be between " . self::ALLOWED_MIN_YEAR . " and " . self::ALLOWED_MAX_YEAR
            );
        }
    }

    private function verifySpanLimit(DateTime $from, DateTime $to): void
    {
        $interval = $from->diff($to);

        if ($interval->days > self::MAX_SPAN_DAYS) {
            throw new InvalidPeriodException('Period cannot exceed ' . self::MAX_SPAN_DAYS . ' days');
        }
    }

    private function verifyNoFutureOccurrence(DateTime $from, DateTime $to): void
    {
        if (!self::NO_FUTURE_DATES) {
            return;
        }

        $today = new DateTime();
        $today->setTime(0, 0, 0);

        if ($from > $today || $to > $today) {
            throw new InvalidPeriodException('Period cannot contain future dates');
        }
    }

    private function verifyYearValidity(DateTime $date): void
    {
        $year = (int) $date->format('Y');

        if ($year < self::ALLOWED_MIN_YEAR || $year > self::ALLOWED_MAX_YEAR) {
            throw new InvalidPeriodException("Year must be between " . self::ALLOWED_MIN_YEAR . " and " . self::ALLOWED_MAX_YEAR);
        }
    }

    private function verifyNotFuture(DateTime $date): void
    {
        if (!self::NO_FUTURE_DATES) {
            return;
        }

        $today = new DateTime();

        if ($date > $today) {
            throw new InvalidPeriodException('Date cannot be in the future');
        }
    }

    private function verifyWeekNumber(int $week): void
    {
        if ($week < 1 || $week > 53) {
            throw new InvalidPeriodException('Week number must be between 1 and 53');
        }
    }

    private function verifyWeekYear(int $year): void
    {
        if ($year < self::ALLOWED_MIN_YEAR || $year > self::ALLOWED_MAX_YEAR) {
            throw new InvalidPeriodException("Year must be between " . self::ALLOWED_MIN_YEAR . " and " . self::ALLOWED_MAX_YEAR);
        }
    }

    private function calculateWeekStart(int $year, int $week): DateTime
    {
        $firstDay = new DateTime("{$year}-01-01");
        $firstDay->modify('+' . ($week - 1) . ' weeks');
        $firstDay->modify('monday this week');

        return $firstDay;
    }

    private function verifyHalfNumber(int $half): void
    {
        if ($half < 1 || $half > 2) {
            throw new InvalidPeriodException('Half must be 1 or 2');
        }
    }

    private function verifyHalfYearValidity(int $year): void
    {
        if ($year < self::ALLOWED_MIN_YEAR || $year > self::ALLOWED_MAX_YEAR) {
            throw new InvalidPeriodException("Year must be between " . self::ALLOWED_MIN_YEAR . " and " . self::ALLOWED_MAX_YEAR);
        }
    }

    private function verifyCustomSpan(DateTime $from, DateTime $to, int $maxDays): void
    {
        $interval = $from->diff($to);

        if ($interval->days > $maxDays) {
            throw new InvalidPeriodException("Period cannot exceed {$maxDays} days");
        }
    }
}
