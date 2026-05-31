<?php

namespace App\Services\Social;

final class TimeFormatConfig
{
    public readonly bool $verbose;
    public readonly int $recentThreshold;

    public function __construct(bool $verbose = false, int $recentThreshold = 300)
    {
        $this->verbose = $verbose;
        $this->recentThreshold = $recentThreshold;
    }
}

final class RelativeTimeService
{
    private TimeFormatConfig $config;

    private const UNITS = [
        ['name' => 'second', 'seconds' => 1],
        ['name' => 'minute', 'seconds' => 60],
        ['name' => 'hour', 'seconds' => 3600],
        ['name' => 'day', 'seconds' => 86400],
        ['name' => 'week', 'seconds' => 604800],
        ['name' => 'month', 'seconds' => 2592000],
        ['name' => 'year', 'seconds' => 31536000],
    ];

    public function __construct(TimeFormatConfig $config)
    {
        $this->config = $config;
    }

    public function formatPast(\DateTimeImmutable $date, ?\DateTimeImmutable $now = null): string
    {
        $reference = $now ?? new \DateTimeImmutable();
        $diff = $reference->getTimestamp() - $date->getTimestamp();

        if ($diff < 0) {
            return 'in ' . $this->formatInterval(abs($diff));
        }

        return $this->formatInterval($diff) . ' ago';
    }

    public function formatInterval(int $seconds): string
    {
        foreach (self::UNITS as $index => $unit) {
            if ($seconds < $unit['seconds'] || $index === count(self::UNITS) - 1) {
                $value = (int) floor($seconds / ($index > 0 ? self::UNITS[$index - 1]['seconds'] : 1));
                $unitName = $index > 0 ? self::UNITS[$index - 1]['name'] : $unit['name'];

                return $value . ' ' . $unitName . ($value !== 1 ? 's' : '');
            }
        }

        return '1 year';
    }

    private function resolveUnitIndex(int $seconds): int
    {
        if ($seconds < 60) {
            return 0;
        }
        if ($seconds < 3600) {
            return 1;
        }
        if ($seconds < 86400) {
            return 2;
        }
        if ($seconds < 604800) {
            return 3;
        }
        if ($seconds < 2592000) {
            return 4;
        }
        if ($seconds < 31536000) {
            return 5;
        }

        return 6;
    }
}
