<?php

declare(strict_types=1);

namespace App\Scheduling;

use App\Exceptions\ZoneConversionException;
use DateTime;
use DateTimeZone;

final class ZoneTransitionHandler
{
    private const POPULAR_ZONES = [
        'UTC', 'GMT',
        'America/New_York', 'America/Chicago', 'America/Denver', 'America/Los_Angeles',
        'America/Toronto', 'America/Vancouver',
        'Europe/London', 'Europe/Paris', 'Europe/Berlin', 'Europe/Madrid', 'Europe/Rome',
        'Asia/Tokyo', 'Asia/Shanghai', 'Asia/Singapore', 'Asia/Dubai',
        'Australia/Sydney', 'Australia/Melbourne',
    ];

    public function translate(string $value, string $sourceZone, string $targetZone): DateTime
    {
        $this->checkZoneValidity($sourceZone);
        $this->checkZoneValidity($targetZone);

        $source = new DateTime($value, new DateTimeZone($sourceZone));

        return $source->setTimezone(new DateTimeZone($targetZone));
    }

    public function translateToUtc(string $value, string $sourceZone): DateTime
    {
        return $this->translate($value, $sourceZone, 'UTC');
    }

    public function translateFromUtc(string $value, string $targetZone): DateTime
    {
        return $this->translate($value, 'UTC', $targetZone);
    }

    public function offsetOf(string $zone, string $when = 'now'): int
    {
        $this->checkZoneValidity($zone);

        $moment = new DateTime($when, new DateTimeZone($zone));

        return (int) $moment->format('Z');
    }

    public function formattedOffset(string $zone, string $when = 'now'): string
    {
        $seconds = $this->offsetOf($zone, $when);
        $absSeconds = abs($seconds);
        $hours = intdiv($absSeconds, 3600);
        $minutes = intdiv($absSeconds % 3600, 60);
        $sign = $seconds >= 0 ? '+' : '-';

        return "UTC{$sign}{$hours}:{$minutes}";
    }

    public function isObservingDst(string $zone, string $when = 'now'): bool
    {
        $this->checkZoneValidity($zone);

        $moment = new DateTime($when, new DateTimeZone($zone));

        return $moment->format('I') === '1';
    }

    public function upcomingDstChange(string $zone, string $when = 'now'): ?DateTime
    {
        $this->checkZoneValidity($zone);

        $current = new DateTime($when, new DateTimeZone($zone));
        $currentYear = (int) $current->format('Y');

        for ($m = 1; $m <= 12; $m++) {
            $candidate = new DateTime("{$currentYear}-{$m}-01", new DateTimeZone($zone));

            if ($candidate->format('I') === '1' && $candidate > $current) {
                return $candidate;
            }
        }

        $nextYear = new DateTime(($currentYear + 1) . '-01-01', new DateTimeZone($zone));

        if ($nextYear->format('I') === '1') {
            return $nextYear;
        }

        return null;
    }

    public function translateArray(array $values, string $sourceZone, string $targetZone): array
    {
        $this->checkZoneValidity($sourceZone);
        $this->checkZoneValidity($targetZone);

        return array_map(
            fn($v) => $this->translate($v, $sourceZone, $targetZone),
            $values
        );
    }

    public function abbreviation(string $zone, string $when = 'now'): string
    {
        $this->checkZoneValidity($zone);

        $moment = new DateTime($when, new DateTimeZone($zone));

        return $moment->format('T');
    }

    public function name(string $zone): string
    {
        $this->checkZoneValidity($zone);

        return (new DateTimeZone($zone))->getName();
    }

    public function zonesMatchingOffset(int $offsetSeconds): array
    {
        $matches = [];

        foreach (DateTimeZone::listIdentifiers() as $identifier) {
            $tz = new DateTimeZone($identifier);
            $now = new DateTime('now', $tz);

            if ((int) $now->format('Z') === $offsetSeconds) {
                $matches[] = $identifier;
            }
        }

        return $matches;
    }

    public function commonZones(): array
    {
        return self::POPULAR_ZONES;
    }

    public function differenceSeconds(string $fromZone, string $toZone, string $when = 'now'): int
    {
        return $this->offsetOf($toZone, $when) - $this->offsetOf($fromZone, $when);
    }

    public function formatted(string $datetime, string $zone, string $format): string
    {
        $this->checkZoneValidity($zone);

        $moment = new DateTime($datetime, new DateTimeZone($zone));

        return $moment->format($format);
    }

    private function checkZoneValidity(string $zone): void
    {
        if (!in_array($zone, self::POPULAR_ZONES, true)) {
            if (!in_array($zone, DateTimeZone::listIdentifiers(), true)) {
                throw new ZoneConversionException("Invalid timezone identifier: {$zone}");
            }
        }
    }
}
