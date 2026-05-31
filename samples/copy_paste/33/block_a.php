<?php

declare(strict_types=1);

namespace App\Dates;

use App\Exceptions\TimezoneException;
use DateTime;
use DateTimeZone;
use DateInterval;

final class TimezoneConverter
{
    private const SUPPORTED_TIMEZONES = [
        'UTC', 'America/New_York', 'America/Chicago', 'America/Denver', 'America/Los_Angeles',
        'America/Toronto', 'America/Vancouver', 'America/Mexico_City', 'America/Sao_Paulo',
        'Europe/London', 'Europe/Paris', 'Europe/Berlin', 'Europe/Madrid', 'Europe/Rome',
        'Europe/Moscow', 'Europe/Istanbul', 'Asia/Tokyo', 'Asia/Shanghai', 'Asia/Hong_Kong',
        'Asia/Singapore', 'Asia/Dubai', 'Asia/Kolkata', 'Australia/Sydney', 'Australia/Melbourne',
        'Pacific/Auckland', 'Africa/Johannesburg', 'Africa/Cairo',
    ];

    public function convert(string $datetime, string $fromTimezone, string $toTimezone): DateTime
    {
        $this->validateTimezone($fromTimezone);
        $this->validateTimezone($toTimezone);

        $date = new DateTime($datetime, new DateTimeZone($fromTimezone));
        $date->setTimezone(new DateTimeZone($toTimezone));

        return $date;
    }

    public function convertToUtc(string $datetime, string $fromTimezone): DateTime
    {
        return $this->convert($datetime, $fromTimezone, 'UTC');
    }

    public function convertFromUtc(string $datetime, string $toTimezone): DateTime
    {
        return $this->convert($datetime, 'UTC', $toTimezone);
    }

    public function getOffset(string $timezone, string $datetime = 'now'): int
    {
        $this->validateTimezone($timezone);

        $date = new DateTime($datetime, new DateTimeZone($timezone));

        return (int) $date->format('Z');
    }

    public function getOffsetString(string $timezone, string $datetime = 'now'): string
    {
        $offset = $this->getOffset($timezone, $datetime);
        $hours = (int) floor(abs($offset) / 3600);
        $minutes = (int) floor((abs($offset) % 3600) / 60);
        $sign = $offset >= 0 ? '+' : '-';

        return "UTC{$sign}{$hours}:{$minutes}";
    }

    public function isDst(string $timezone, string $datetime = 'now'): bool
    {
        $this->validateTimezone($timezone);

        $date = new DateTime($datetime, new DateTimeZone($timezone));

        return (bool) $date->format('I');
    }

    public function getNextDstTransition(string $timezone, string $datetime = 'now'): ?DateTime
    {
        $this->validateTimezone($timezone);

        $date = new DateTime($datetime, new DateTimeZone($timezone));
        $year = (int) $date->format('Y');

        for ($month = 1; $month <= 12; $month++) {
            $check = new DateTime("{$year}-{$month}-01", new DateTimeZone($timezone));

            if ($check->format('I') === '1') {
                if ($check >= $date) {
                    return $check;
                }
            }
        }

        $check = new DateTime(($year + 1) . '-01-01', new DateTimeZone($timezone));

        if ($check->format('I') === '1') {
            return $check;
        }

        return null;
    }

    public function convertMultiple(array $datetimes, string $fromTimezone, string $toTimezone): array
    {
        $this->validateTimezone($fromTimezone);
        $this->validateTimezone($toTimezone);

        $results = [];

        foreach ($datetimes as $datetime) {
            $date = new DateTime($datetime, new DateTimeZone($fromTimezone));
            $date->setTimezone(new DateTimeZone($toTimezone));
            $results[] = $date;
        }

        return $results;
    }

    public function getTimezoneAbbreviation(string $timezone, string $datetime = 'now'): string
    {
        $this->validateTimezone($timezone);

        $date = new DateTime($datetime, new DateTimeZone($timezone));

        return $date->format('T');
    }

    public function getTimezoneName(string $timezone): string
    {
        $this->validateTimezone($timezone);

        $tz = new DateTimeZone($timezone);

        return $tz->getName();
    }

    public function getTimezonesForOffset(int $offsetSeconds): array
    {
        $matching = [];
        $allTimezones = DateTimeZone::listIdentifiers();

        foreach ($allTimezones as $tzIdentifier) {
            $tz = new DateTimeZone($tzIdentifier);
            $now = new DateTime('now', $tz);
            $tzOffset = (int) $now->format('Z');

            if ($tzOffset === $offsetSeconds) {
                $matching[] = $tzIdentifier;
            }
        }

        return $matching;
    }

    public function getCommonTimezones(): array
    {
        return self::SUPPORTED_TIMEZONES;
    }

    public function calculateTimeDifference(string $fromTimezone, string $toTimezone, string $datetime = 'now'): int
    {
        $fromOffset = $this->getOffset($fromTimezone, $datetime);
        $toOffset = $this->getOffset($toTimezone, $datetime);

        return $toOffset - $fromOffset;
    }

    public function formatInTimezone(DateTime $date, string $timezone, string $format): string
    {
        $this->validateTimezone($timezone);

        $cloned = clone $date;
        $cloned->setTimezone(new DateTimeZone($timezone));

        return $cloned->format($format);
    }

    private function validateTimezone(string $timezone): void
    {
        if (!in_array($timezone, self::SUPPORTED_TIMEZONES, true)) {
            if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
                throw new TimezoneException("Unsupported timezone: {$timezone}");
            }
        }
    }
}
