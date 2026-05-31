<?php

declare(strict_types=1);

namespace App\Scheduling\Availability;

use DateTime;
use App\Exceptions\InvalidScheduleException;

final class ScheduleRangeChecker
{
    private const YEAR_LOWER_BOUND = 2000;
    private const YEAR_UPPER_BOUND = 2100;
    private const DEFAULT_MAX_DAYS = 365;
    private const BLOCK_FUTURE = true;

    public function checkRange(string $begin, string $finish): void
    {
        $start = $this->makeDateObject($begin);
        $end = $this->makeDateObject($finish);

        $this->confirmStartPrecedesEnd($start, $end);
        $this->confirmYearRange($start, $end);
        $this->confirmDurationWithinLimit($start, $end);
        $this->confirmNoFutureDates($start, $end);
    }

    public function checkDate(string $date): void
    {
        $parsed = $this->makeDateObject($date);
        $this->confirmYearIsValid($parsed);
        $this->confirmNotFuture($parsed);
    }

    public function checkSemester(int $year, int $semester): void
    {
        $this->semesterNumberIsValid($semester);
        $this->semesterYearIsValid($year);

        $startMonth = $semester === 1 ? 1 : 8;
        $endMonth = $semester === 1 ? 5 : 12;

        $semesterStart = new DateTime("{$year}-{$startMonth}-01");
        $semesterEnd = new DateTime("{$year}-{$endMonth}-" . date('t', $semesterEnd->getTimestamp()));

        $this->confirmNoFutureDates($semesterStart, $semesterEnd);
    }

    public function checkAnnual(int $year): void
    {
        if ($year < self::YEAR_LOWER_BOUND || $year > self::YEAR_UPPER_BOUND) {
            throw new InvalidScheduleException("Year must be between " . self::YEAR_LOWER_BOUND . " and " . self::YEAR_UPPER_BOUND);
        }

        $annualStart = new DateTime("{$year}-01-01");
        $annualEnd = new DateTime("{$year}-12-31");

        $this->confirmNoFutureDates($annualStart, $annualEnd);
    }

    public function checkBiannual(int $year, int $period): void
    {
        $this->periodIsValid($period);
        $this->periodYearIsValid($year);

        $start = new DateTime("{$year}-" . ($period === 1 ? '01' : '07') . '-01');
        $end = new DateTime("{$year}-" . ($period === 1 ? '06' : '12') . '-31');

        $this->confirmNoFutureDates($start, $end);
    }

    private function makeDateObject(string $date): DateTime
    {
        $dt = DateTime::createFromFormat('Y-m-d', $date);

        if ($dt === false) {
            $dt = DateTime::createFromFormat('Y/m/d', $date);
        }

        if ($dt === false) {
            throw new InvalidScheduleException("Date parsing failed: {$date}");
        }

        $dt->setTime(0, 0, 0);
        return $dt;
    }

    private function confirmStartPrecedesEnd(DateTime $start, DateTime $end): void
    {
        if ($start > $end) {
            throw new InvalidScheduleException('Start must be before end');
        }
    }

    private function confirmYearRange(DateTime $start, DateTime $end): void
    {
        $startYear = (int) $start->format('Y');
        $endYear = (int) $end->format('Y');

        if ($startYear < self::YEAR_LOWER_BOUND || $endYear > self::YEAR_UPPER_BOUND) {
            throw new InvalidScheduleException(
                "Year must be between " . self::YEAR_LOWER_BOUND . " and " . self::YEAR_UPPER_BOUND
            );
        }
    }

    private function confirmDurationWithinLimit(DateTime $start, DateTime $end): void
    {
        $diff = $start->diff($end);

        if ($diff->days > self::DEFAULT_MAX_DAYS) {
            throw new InvalidScheduleException('Duration exceeds ' . self::DEFAULT_MAX_DAYS . ' days');
        }
    }

    private function confirmNoFutureDates(DateTime $start, DateTime $end): void
    {
        if (!self::BLOCK_FUTURE) {
            return;
        }

        $now = new DateTime();
        $now->setTime(0, 0, 0);

        if ($start > $now || $end > $now) {
            throw new InvalidScheduleException('Schedule cannot include future dates');
        }
    }

    private function confirmYearIsValid(DateTime $date): void
    {
        $year = (int) $date->format('Y');

        if ($year < self::YEAR_LOWER_BOUND || $year > self::YEAR_UPPER_BOUND) {
            throw new InvalidScheduleException("Year must be between " . self::YEAR_LOWER_BOUND . " and " . self::YEAR_UPPER_BOUND);
        }
    }

    private function confirmNotFuture(DateTime $date): void
    {
        if (!self::BLOCK_FUTURE) {
            return;
        }

        $now = new DateTime();

        if ($date > $now) {
            throw new InvalidScheduleException('Date cannot be in the future');
        }
    }

    private function semesterNumberIsValid(int $semester): void
    {
        if ($semester < 1 || $semester > 2) {
            throw new InvalidScheduleException('Semester must be 1 or 2');
        }
    }

    private function semesterYearIsValid(int $year): void
    {
        if ($year < self::YEAR_LOWER_BOUND || $year > self::YEAR_UPPER_BOUND) {
            throw new InvalidScheduleException("Year must be between " . self::YEAR_LOWER_BOUND . " and " . self::YEAR_UPPER_BOUND);
        }
    }

    private function periodIsValid(int $period): void
    {
        if ($period < 1 || $period > 2) {
            throw new InvalidScheduleException('Period must be 1 or 2');
        }
    }

    private function periodYearIsValid(int $year): void
    {
        if ($year < self::YEAR_LOWER_BOUND || $year > self::YEAR_UPPER_BOUND) {
            throw new InvalidScheduleException("Year must be between " . self::YEAR_LOWER_BOUND . " and " . self::YEAR_UPPER_BOUND);
        }
    }
}
