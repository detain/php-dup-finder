<?php

namespace App\Services\Dates;

use DateTime;
use DateTimeZone;

final class TimezoneConfig
{
    public readonly array $supportedZones;

    public function __construct(array $supportedZones = [])
    {
        $this->supportedZones = $supportedZones;
    }
}

final class TimezoneService
{
    private TimezoneConfig $config;

    public function __construct(TimezoneConfig $config)
    {
        $this->config = $config;
    }

    public function convert(string $datetime, string $fromZone, string $toZone): DateTime
    {
        $this->validate($fromZone);
        $this->validate($toZone);

        $dt = new DateTime($datetime, new DateTimeZone($fromZone));

        return $dt->setTimezone(new DateTimeZone($toZone));
    }

    public function offset(string $zone, string $when = 'now'): int
    {
        $this->validate($zone);

        $dt = new DateTime($when, new DateTimeZone($zone));

        return (int) $dt->format('Z');
    }

    public function isDst(string $zone, string $when = 'now'): bool
    {
        $this->validate($zone);

        $dt = new DateTime($when, new DateTimeZone($zone));

        return $dt->format('I') === '1';
    }

    public function format(string $datetime, string $zone, string $format): string
    {
        $this->validate($zone);

        $dt = new DateTime($datetime, new DateTimeZone($zone));

        return $dt->format($format);
    }

    private function validate(string $zone): void
    {
        if (!empty($this->config->supportedZones) && !in_array($zone, $this->config->supportedZones, true)) {
            throw new \InvalidArgumentException("Unsupported timezone: {$zone}");
        }
    }
}
