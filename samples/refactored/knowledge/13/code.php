<?php
declare(strict_types=1);

namespace App\BusinessHours;

use DateTimeImmutable;

final class BusinessHours
{
    private const DEFAULT_WEEKDAY_OPEN = '09:00';
    private const DEFAULT_WEEKDAY_CLOSE = '17:00';
    private const SATURDAY_OPEN = '10:00';
    private const SATURDAY_CLOSE = '14:00';

    public function __construct(
        private readonly array $schedule = [],
        private readonly array $holidays = []
    ) {}

    public static function default(): self
    {
        return new self([
            1 => ['open' => '09:00', 'close' => '17:00'],
            2 => ['open' => '09:00', 'close' => '17:00'],
            3 => ['open' => '09:00', 'close' => '17:00'],
            4 => ['open' => '09:00', 'close' => '17:00'],
            5 => ['open' => '09:00', 'close' => '17:00'],
            6 => ['open' => '10:00', 'close' => '14:00'],
            7 => null,
        ]);
    }

    public function isOpenAt(DateTimeImmutable $dateTime): bool
    {
        if ($this->isHoliday($dateTime)) {
            return false;
        }

        $hours = $this->getHoursForDay((int) $dateTime->format('N'));
        if ($hours === null) {
            return false;
        }

        $currentTime = $dateTime->format('H:i');
        return $currentTime >= $hours['open'] && $currentTime <= $hours['close'];
    }

    public function getHoursForDay(int $dayOfWeek): ?array
    {
        return $this->schedule[$dayOfWeek] ?? null;
    }

    public function getNextOpening(DateTimeImmutable $from): DateTimeImmutable
    {
        $current = $from;

        for ($i = 0; $i < 7; $i++) {
            $current = $current->modify('+1 day');
            $hours = $this->getHoursForDay((int) $current->format('N'));

            if ($hours !== null && !$this->isHoliday($current)) {
                [$h, $m] = explode(':', $hours['open']);
                return $current->setTime((int) $h, (int) $m);
            }
        }

        return $from->modify('+1 day')->setTime(9, 0);
    }

    public function isHoliday(DateTimeImmutable $date): bool
    {
        return in_array($date->format('Y-m-d'), $this->holidays, true);
    }
}
