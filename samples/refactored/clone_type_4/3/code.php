<?php

declare(strict_types=1);

namespace App\Shared;

use DateTimeInterface;
use Psr\Log\LoggerInterface;

interface AgeCalculatorInterface
{
    public function calculate(DateTimeInterface $fromDate, DateTimeInterface $toDate): int;
    public function getDateType(): string;
}

final class DateIntervalAgeCalculator implements AgeCalculatorInterface
{
    public function calculate(DateTimeInterface $fromDate, DateTimeInterface $toDate): int
    {
        return $fromDate->diff($toDate)->y;
    }

    public function getDateType(): string
    {
        return 'date_interval';
    }
}

final class TimestampAgeCalculator implements AgeCalculatorInterface
{
    private const SECONDS_PER_YEAR = 365.25 * 24 * 60 * 60;

    public function calculate(DateTimeInterface $fromDate, DateTimeInterface $toDate): int
    {
        $secondsElapsed = $toDate->getTimestamp() - $fromDate->getTimestamp();
        $yearsElapsed = $secondsElapsed / self::SECONDS_PER_YEAR;
        return (int) floor($yearsElapsed);
    }

    public function getDateType(): string
    {
        return 'timestamp';
    }
}

final class CalendarAgeCalculator implements AgeCalculatorInterface
{
    public function calculate(DateTimeInterface $fromDate, DateTimeInterface $toDate): int
    {
        $fromYear = (int) $fromDate->format('Y');
        $toYear = (int) $toDate->format('Y');
        $years = $toYear - $fromYear;

        $fromMonthDay = $fromDate->format('md');
        $toMonthDay = $toDate->format('md');

        if ($toMonthDay < $fromMonthDay) {
            $years--;
        }

        return $years;
    }

    public function getDateType(): string
    {
        return 'calendar';
    }
}

final class AgeCalculationService
{
    /** @var AgeCalculatorInterface[] */
    private array $calculators = [];

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function registerCalculator(AgeCalculatorInterface $calculator): void
    {
        $this->calculators[$calculator->getDateType()] = $calculator;
    }

    public function calculateAge(string $dateType, DateTimeInterface $fromDate, ?DateTimeInterface $toDate = null): int
    {
        $calculator = $this->calculators[$dateType] ?? null;

        if ($calculator === null) {
            throw new \InvalidArgumentException("No calculator found for type: {$dateType}");
        }

        $toDate ??= new \DateTimeImmutable();

        return $calculator->calculate($fromDate, $toDate);
    }
}
