<?php

declare(strict_types=1);

namespace App\Services\Scheduling;

use DateTime;
use DateInterval;

final class DateRangeConfig
{
    public readonly int $minYear;
    public readonly int $maxYear;
    public readonly int $maxDays;
    public readonly bool $blockFuture;

    public function __construct(
        int $minYear = 2000,
        int $maxYear = 2100,
        int $maxDays = 365,
        bool $blockFuture = true
    ) {
        $this->minYear = $minYear;
        $this->maxYear = $maxYear;
        $this->maxDays = $maxDays;
        $this->blockFuture = $blockFuture;
    }
}

final class DateRangeValidator
{
    private DateRangeConfig $config;

    public function __construct(DateRangeConfig $config)
    {
        $this->config = $config;
    }

    public function validate(string $startDate, string $endDate): void
    {
        $start = $this->parse($startDate);
        $end = $this->parse($endDate);

        $this->validateChronology($start, $end);
        $this->validateYearRange($start, $end);
        $this->validateSpan($start, $end);
        $this->validateFutureBlock($start, $end);
    }

    private function parse(string $date): DateTime
    {
        $parsed = DateTime::createFromFormat('Y-m-d', $date)
            ?? DateTime::createFromFormat('Y/m/d', $date);

        if ($parsed === false) {
            throw new \InvalidArgumentException("Cannot parse date: {$date}");
        }

        $parsed->setTime(0, 0, 0);
        return $parsed;
    }

    private function validateChronology(DateTime $start, DateTime $end): void
    {
        if ($start > $end) {
            throw new \InvalidArgumentException('Start date must precede end date');
        }
    }

    private function validateYearRange(DateTime $start, DateTime $end): void
    {
        $startYear = (int) $start->format('Y');
        $endYear = (int) $end->format('Y');

        if ($startYear < $this->config->minYear || $endYear > $this->config->maxYear) {
            throw new \InvalidArgumentException(
                "Year must be between {$this->config->minYear} and {$this->config->maxYear}"
            );
        }
    }

    private function validateSpan(DateTime $start, DateTime $end): void
    {
        $span = $start->diff($end);

        if ($span->days > $this->config->maxDays) {
            throw new \InvalidArgumentException("Span cannot exceed {$this->config->maxDays} days");
        }
    }

    private function validateFutureBlock(DateTime $start, DateTime $end): void
    {
        if (!$this->config->blockFuture) {
            return;
        }

        $now = new DateTime();
        $now->setTime(0, 0, 0);

        if ($start > $now || $end > $now) {
            throw new \InvalidArgumentException('Date cannot be in the future');
        }
    }
}
