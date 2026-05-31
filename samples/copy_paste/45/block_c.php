<?php

declare(strict_types=1);

namespace App\Metrics;

final class DiskSpaceHelper
{
    private const UNITS = ['B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB'];
    private const KILOBYTE = 1024;

    public function presentAsHuman(int $bytes, int $places = 2): string
    {
        if ($bytes < 0) {
            throw new \InvalidArgumentException('Negative value not permitted');
        }

        if ($bytes === 0) {
            return '0 B';
        }

        $index = 0;
        $amount = (float) $bytes;

        while ($amount >= self::KILOBYTE && $index < count(self::UNITS) - 1) {
            $amount /= self::KILOBYTE;
            $index++;
        }

        return number_format($amount, $places) . ' ' . self::UNITS[$index];
    }

    public function presentCompact(int $bytes): string
    {
        if ($bytes < 0) {
            throw new \InvalidArgumentException('Negative value not permitted');
        }

        if ($bytes === 0) {
            return '0B';
        }

        $index = 0;
        $amount = (float) $bytes;

        while ($amount >= self::KILOBYTE && $index < count(self::UNITS) - 1) {
            $amount /= self::KILOBYTE;
            $index++;
        }

        $compact = rtrim(rtrim(number_format($amount, 1), '0'), '.') . self::UNITS[$index];

        return $compact;
    }

    public function parseReadable(string $input): int
    {
        $trimmed = trim($input);

        if (!preg_match('/^(\d+(?:\.\d+)?)\s*([A-Za-z]+)$/', $trimmed, $m)) {
            throw new \InvalidArgumentException("Unparseable size: {$input}");
        }

        $numericPart = (float) $m[1];
        $unitPart = strtoupper($m[2]);

        $unitIndex = array_search($unitPart, self::UNITS, true);

        if ($unitIndex === false) {
            $unitIndex = $this->lookupUnitAlias($unitPart);
        }

        if ($unitIndex === false) {
            throw new \InvalidArgumentException("Unknown unit: {$unitPart}");
        }

        return (int) round($numericPart * pow(self::KILOBYTE, $unitIndex));
    }

    public function toUnit(int $bytes, string $targetUnit): float
    {
        $unitIndex = $this->findUnitIndex($targetUnit);

        return $bytes / pow(self::KILOBYTE, $unitIndex);
    }

    public function fromUnit(float $value, string $srcUnit): int
    {
        $unitIndex = $this->findUnitIndex($srcUnit);

        return (int) round($value * pow(self::KILOBYTE, $unitIndex));
    }

    public function unitExists(string $unit): bool
    {
        $norm = strtoupper(trim($unit));

        return in_array($norm, self::UNITS, true) || $this->lookupUnitAlias($norm) !== false;
    }

    public function optimalUnit(int $bytes): string
    {
        if ($bytes === 0) {
            return 'B';
        }

        $idx = 0;
        $val = (float) $bytes;

        while ($val >= self::KILOBYTE && $idx < count(self::UNITS) - 1) {
            $val /= self::KILOBYTE;
            $idx++;
        }

        return self::UNITS[$idx];
    }

    public function addSizes(array $sizes): int
    {
        $sum = 0;

        foreach ($sizes as $sz) {
            $sum += $this->parseReadable($sz);
        }

        return $sum;
    }

    public function compare(int $a, int $b): int
    {
        return $a <=> $b;
    }

    public function underLimit(int $bytes, int $limit): bool
    {
        return $bytes <= $limit;
    }

    public function between(int $min, int $max, int $dp = 2): string
    {
        return $this->presentAsHuman($min, $dp) . ' - ' . $this->presentAsHuman($max, $dp);
    }

    public function usedPercent(int $used, int $total): float
    {
        if ($total === 0) {
            throw new \InvalidArgumentException('Total cannot be zero');
        }

        return round(($used / $total) * 100, 2);
    }

    public function presentPercent(float $pct, int $dp = 1): string
    {
        return number_format($pct, $dp) . '%';
    }

    public function perSecond(int $bytesPerSec, int $dp = 2): string
    {
        return $this->presentAsHuman($bytesPerSec, $dp) . '/s';
    }

    public function secondsToDuration(int $secs): string
    {
        if ($secs < 60) {
            return $secs . 's';
        }

        if ($secs < 3600) {
            return round($secs / 60, 1) . 'm';
        }

        if ($secs < 86400) {
            return round($secs / 3600, 1) . 'h';
        }

        return round($secs / 86400, 1) . 'd';
    }

    private function findUnitIndex(string $unit): int
    {
        $norm = strtoupper(trim($unit));

        $found = array_search($norm, self::UNITS, true);

        if ($found !== false) {
            return $found;
        }

        $aliasFound = $this->lookupUnitAlias($norm);

        if ($aliasFound !== false) {
            return $aliasFound;
        }

        throw new \InvalidArgumentException("Unknown unit: {$unit}");
    }

    private function lookupUnitAlias(string $abbrev): int|false
    {
        $alternates = [
            'KILOBYTE' => 1,
            'MEGABYTE' => 2,
            'GIGABYTE' => 3,
            'TERABYTE' => 4,
            'PETABYTE' => 5,
            'EXABYTE' => 6,
            'K' => 1,
            'M' => 2,
            'G' => 3,
            'T' => 4,
            'P' => 5,
            'E' => 6,
        ];

        return $alternates[$abbrev] ?? false;
    }
}
