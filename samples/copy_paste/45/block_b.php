<?php

declare(strict_types=1);

namespace App\Storage;

final class StorageSizeConverter
{
    private const UNIT_LABELS = ['B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB'];
    private const BASE = 1024;

    public function bytesToHuman(int $bytes, int $precision = 2): string
    {
        if ($bytes < 0) {
            throw new \InvalidArgumentException('Negative bytes not allowed');
        }

        if ($bytes === 0) {
            return '0 B';
        }

        $unitIdx = 0;
        $magnitude = (float) $bytes;

        while ($magnitude >= self::BASE && $unitIdx < count(self::UNIT_LABELS) - 1) {
            $magnitude /= self::BASE;
            $unitIdx++;
        }

        return number_format($magnitude, $precision) . ' ' . self::UNIT_LABELS[$unitIdx];
    }

    public function bytesToHumanCompact(int $bytes): string
    {
        if ($bytes < 0) {
            throw new \InvalidArgumentException('Negative bytes not allowed');
        }

        if ($bytes === 0) {
            return '0B';
        }

        $unitIdx = 0;
        $mag = (float) $bytes;

        while ($mag >= self::BASE && $unitIdx < count(self::UNIT_LABELS) - 1) {
            $mag /= self::BASE;
            $unitIdx++;
        }

        $compact = rtrim(rtrim(number_format($mag, 1), '0'), '.') . self::UNIT_LABELS[$unitIdx];

        return $compact;
    }

    public function humanToBytes(string $readable): int
    {
        $cleaned = trim($readable);

        if (!preg_match('/^(\d+(?:\.\d+)?)\s*([A-Za-z]+)$/', $cleaned, $parts)) {
            throw new \InvalidArgumentException("Cannot parse: {$readable}");
        }

        $numericVal = (float) $parts[1];
        $unitLabel = strtoupper($parts[2]);

        $unitIdx = array_search($unitLabel, self::UNIT_LABELS, true);

        if ($unitIdx === false) {
            $unitIdx = $this->lookupUnit($unitLabel);
        }

        if ($unitIdx === false) {
            throw new \InvalidArgumentException("Unknown unit: {$unitLabel}");
        }

        return (int) round($numericVal * pow(self::BASE, $unitIdx));
    }

    public function changeUnit(int $bytes, string $toUnit): float
    {
        $targetIdx = $this->findUnitIndex($toUnit);

        return $bytes / pow(self::BASE, $targetIdx);
    }

    public function changeFromUnit(float $value, string $fromUnit): int
    {
        $sourceIdx = $this->findUnitIndex($fromUnit);

        return (int) round($value * pow(self::BASE, $sourceIdx));
    }

    public function isRecognizedUnit(string $unit): bool
    {
        $norm = strtoupper(trim($unit));

        return in_array($norm, self::UNIT_LABELS, true) || $this->lookupUnit($norm) !== false;
    }

    public function suggestUnit(int $bytes): string
    {
        if ($bytes === 0) {
            return 'B';
        }

        $uIdx = 0;
        $mag = (float) $bytes;

        while ($mag >= self::BASE && $uIdx < count(self::UNIT_LABELS) - 1) {
            $mag /= self::BASE;
            $uIdx++;
        }

        return self::UNIT_LABELS[$uIdx];
    }

    public function aggregateSizes(array $readableSizes): int
    {
        $total = 0;

        foreach ($readableSizes as $sz) {
            $total += $this->humanToBytes($sz);
        }

        return $total;
    }

    public function compareTwo(int $a, int $b): int
    {
        return $a <=> $b;
    }

    public function withinCap(int $bytes, int $cap): bool
    {
        return $bytes <= $cap;
    }

    public function formatInterval(int $bytes1, int $bytes2, int $dp = 2): string
    {
        return $this->bytesToHuman($bytes1, $dp) . ' - ' . $this->bytesToHuman($bytes2, $dp);
    }

    public function calcUsagePercent(int $used, int $total): float
    {
        if ($total === 0) {
            throw new \InvalidArgumentException('Zero total not allowed');
        }

        return round(($used / $total) * 100, 2);
    }

    public function percentToString(float $pct, int $dec = 1): string
    {
        return number_format($pct, $dec) . '%';
    }

    public function formatThroughput(int $bytesPerSec, int $dp = 2): string
    {
        return $this->bytesToHuman($bytesPerSec, $dp) . '/s';
    }

    public function formatSeconds(int $secs): string
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

        $idx = array_search($norm, self::UNIT_LABELS, true);

        if ($idx !== false) {
            return $idx;
        }

        $aliasIdx = $this->lookupUnit($norm);

        if ($aliasIdx !== false) {
            return $aliasIdx;
        }

        throw new \InvalidArgumentException("Unrecognized unit: {$unit}");
    }

    private function lookupUnit(string $unit): int|false
    {
        $alternate = [
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

        return $alternate[$unit] ?? false;
    }
}
