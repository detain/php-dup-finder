<?php

declare(strict_types=1);

namespace App\Utility;

use App\Exceptions\TimezoneException;
use DateTime;
use DateTimeZone;

final class ZoneCalculator
{
    private const STANDARD_ZONES = [
        'UTC',
        'America/New_York', 'America/Chicago', 'America/Denver', 'America/Los_Angeles',
        'Europe/London', 'Europe/Paris', 'Europe/Berlin', 'Europe/Madrid',
        'Asia/Tokyo', 'Asia/Shanghai', 'Asia/Singapore',
    ];

    public function shift(string $dateString, string $sourceZone, string $destZone): DateTime
    {
        $this->ensureValid($sourceZone);
        $this->ensureValid($destZone);

        $dt = new DateTime($dateString, new DateTimeZone($sourceZone));

        return $dt->setTimezone(new DateTimeZone($destZone));
    }

    public function shiftTo(string $dateString, string $sourceZone, string $destZone): DateTime
    {
        return $this->shift($dateString, $sourceZone, $destZone);
    }

    public function shiftFrom(string $dateString, string $sourceZone, string $destZone): DateTime
    {
        return $this->shift($dateString, $sourceZone, $destZone);
    }

    public function offsetSeconds(string $zone, string $moment = 'now'): int
    {
        $this->ensureValid($zone);

        $dt = new DateTime($moment, new DateTimeZone($zone));

        return (int) $dt->format('Z');
    }

    public function offsetFormatted(string $zone, string $moment = 'now'): string
    {
        $secs = $this->offsetSeconds($zone, $moment);
        $sign = $secs >= 0 ? '+' : '-';
        $abs = abs($secs);
        $h = intdiv($abs, 3600);
        $m = intdiv($abs % 3600, 60);

        return "UTC{$sign}{$h}:{$m}";
    }

    public function inDst(string $zone, string $moment = 'now'): bool
    {
        $this->ensureValid($zone);

        $dt = new DateTime($moment, new DateTimeZone($zone));

        return $dt->format('I') === '1';
    }

    public function nextDstSwitch(string $zone, string $moment = 'now'): ?DateTime
    {
        $this->ensureValid($zone);

        $current = new DateTime($moment, new DateTimeZone($zone));
        $yearNow = (int) $current->format('Y');

        for ($month = 1; $month <= 12; $month++) {
            $candidate = new DateTime("{$yearNow}-{$month}-15", new DateTimeZone($zone));

            if ($candidate->format('I') === '1' && $candidate > $current) {
                return $candidate;
            }
        }

        $candidate = new DateTime(($yearNow + 1) . '-01-01', new DateTimeZone($zone));

        if ($candidate->format('I') === '1') {
            return $candidate;
        }

        return null;
    }

    public function shiftBatch(array $dates, string $source, string $dest): array
    {
        $this->ensureValid($source);
        $this->ensureValid($dest);

        $results = [];

        foreach ($dates as $d) {
            $results[] = $this->shift($d, $source, $dest);
        }

        return $results;
    }

    public function zoneAbbrev(string $zone, string $moment = 'now'): string
    {
        $this->ensureValid($zone);

        $dt = new DateTime($moment, new DateTimeZone($zone));

        return $dt->format('T');
    }

    public function zoneIdentifier(string $zone): string
    {
        $this->ensureValid($zone);

        return (new DateTimeZone($zone))->getName();
    }

    public function allZonesForOffset(int $offsetSeconds): array
    {
        $found = [];

        foreach (DateTimeZone::listIdentifiers() as $id) {
            $dt = new DateTime('now', new DateTimeZone($id));

            if ((int) $dt->format('Z') === $offsetSeconds) {
                $found[] = $id;
            }
        }

        return $found;
    }

    public function standardZones(): array
    {
        return self::STANDARD_ZONES;
    }

    public function diffSeconds(string $fromZone, string $toZone, string $when = 'now'): int
    {
        return $this->offsetSeconds($toZone, $when) - $this->offsetSeconds($fromZone, $when);
    }

    public function display(string $dateString, string $zone, string $format): string
    {
        $this->ensureValid($zone);

        $dt = new DateTime($dateString, new DateTimeZone($zone));

        return $dt->format($format);
    }

    private function ensureValid(string $zone): void
    {
        if (!in_array($zone, self::STANDARD_ZONES, true)) {
            if (!in_array($zone, DateTimeZone::listIdentifiers(), true)) {
                throw new TimezoneException("Unrecognized timezone: {$zone}");
            }
        }
    }
}
