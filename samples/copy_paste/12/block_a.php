<?php

declare(strict_types=1);

namespace App\Reports\Filters;

use DateTime;
use DateTimeZone;
use App\Exceptions\InvalidDateRangeException;

final class ReportDateRangeValidator
{
    private const MIN_YEAR = 2000;
    private const MAX_YEAR = 2100;
    private const MAX_RANGE_DAYS = 365;
    private const BUSINESS_DAYS_ONLY = false;

    public function validateRange(string $startDate, string $endDate): void
    {
        $start = $this->parseDate($startDate);
        $end = $this->parseDate($endDate);

        $this->ensureStartBeforeEnd($start, $end);
        $this->ensureWithinAllowedYears($start, $end);
        $this->ensureNotTooLarge($start, $end);
        $this->ensureNotInFuture($start, $end);
    }

    public function validateStartDate(string $startDate): void
    {
        $date = $this->parseDate($startDate);
        $this->ensureValidYear($date);
        $this->ensureNotInFuture($date);
    }

    public function validateEndDate(string $endDate): void
    {
        $date = $this->parseDate($endDate);
        $this->ensureValidYear($date);
        $this->ensureNotInFuture($date);
    }

    public function validateQuarterlyRange(int $year, int $quarter): void
    {
        $this->ensureValidQuarter($quarter);
        $this->ensureValidYearForQuarter($year);

        $startMonth = ($quarter - 1) * 3 + 1;
        $endMonth = $startMonth + 2;

        $start = new DateTime("{$year}-{$startMonth}-01");
        $end = new DateTime("{$year}-{$endMonth}-" . date('t', $end->getTimestamp()));

        $this->ensureNotInFuture($start, $end);
    }

    public function validateMonthlyRange(int $year, int $month): void
    {
        $this->ensureValidMonth($month);
        $this->ensureValidYearForMonth($year);

        $start = new DateTime("{$year}-{$month}-01");
        $end = new DateTime("{$year}-{$month}-" . date('t', $start->getTimestamp()));

        $this->ensureNotInFuture($start, $end);
    }

    public function validateFiscalYearRange(int $fiscalYear): void
    {
        if ($fiscalYear < 2000 || $fiscalYear > 2100) {
            throw new InvalidDateRangeException("Fiscal year must be between 2000 and 2100");
        }

        $start = new DateTime($fiscalYear . '-01-01');
        $end = new DateTime(($fiscalYear + 1) . '-01-01');

        $this->ensureNotInFuture($start, $end);
    }

    private function parseDate(string $date): DateTime
    {
        $parsed = DateTime::createFromFormat('Y-m-d', $date);

        if ($parsed === false) {
            $parsed = DateTime::createFromFormat('Y/m/d', $date);
        }

        if ($parsed === false) {
            throw new InvalidDateRangeException("Invalid date format: {$date}");
        }

        return $parsed;
    }

    private function ensureStartBeforeEnd(DateTime $start, DateTime $end): void
    {
        if ($start > $end) {
            throw new InvalidDateRangeException('Start date must be before or equal to end date');
        }
    }

    private function ensureWithinAllowedYears(DateTime $start, DateTime $end): void
    {
        $startYear = (int) $start->format('Y');
        $endYear = (int) $end->format('Y');

        if ($startYear < self::MIN_YEAR || $endYear > self::MAX_YEAR) {
            throw new InvalidDateRangeException(
                "Dates must be between " . self::MIN_YEAR . " and " . self::MAX_YEAR
            );
        }
    }

    private function ensureNotTooLarge(DateTime $start, DateTime $end): void
    {
        $diff = $start->diff($end);

        if ($diff->days > self::MAX_RANGE_DAYS) {
            throw new InvalidDateRangeException(
                'Date range cannot exceed ' . self::MAX_RANGE_DAYS . ' days'
            );
        }
    }

    private function ensureNotInFuture(DateTime $start, DateTime $end): void
    {
        $now = new DateTime();
        $now->setTime(0, 0, 0);

        if ($start > $now || $end > $now) {
            throw new InvalidDateRangeException('Date range cannot be in the future');
        }
    }

    private function ensureValidYear(DateTime $date): void
    {
        $year = (int) $date->format('Y');

        if ($year < self::MIN_YEAR || $year > self::MAX_YEAR) {
            throw new InvalidDateRangeException("Year must be between " . self::MIN_YEAR . " and " . self::MAX_YEAR);
        }
    }

    private function ensureValidQuarter(int $quarter): void
    {
        if ($quarter < 1 || $quarter > 4) {
            throw new InvalidDateRangeException('Quarter must be between 1 and 4');
        }
    }

    private function ensureValidMonth(int $month): void
    {
        if ($month < 1 || $month > 12) {
            throw new InvalidDateRangeException('Month must be between 1 and 12');
        }
    }

    private function ensureValidYearForQuarter(int $year): void
    {
        if ($year < self::MIN_YEAR || $year > self::MAX_YEAR) {
            throw new InvalidDateRangeException("Year must be between " . self::MIN_YEAR . " and " . self::MAX_YEAR);
        }
    }

    private function ensureValidYearForMonth(int $year): void
    {
        if ($year < self::MIN_YEAR || $year > self::MAX_YEAR) {
            throw new InvalidDateRangeException("Year must be between " . self::MIN_YEAR . " and " . self::MAX_YEAR);
        }
    }
}
