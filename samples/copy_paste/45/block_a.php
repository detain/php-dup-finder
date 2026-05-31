<?php

declare(strict_types=1);

namespace App\System;

final class FileSizeFormatter
{
    private const BYTE_UNITS = ['B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB'];
    private const BYTE_THRESHOLD = 1024;

    public function formatBytes(int $bytes, int $decimals = 2): string
    {
        if ($bytes < 0) {
            throw new \InvalidArgumentException('Bytes cannot be negative');
        }

        if ($bytes === 0) {
            return '0 B';
        }

        $unitIndex = 0;
        $size = (float) $bytes;

        while ($size >= self::BYTE_THRESHOLD && $unitIndex < count(self::BYTE_UNITS) - 1) {
            $size /= self::BYTE_THRESHOLD;
            $unitIndex++;
        }

        return number_format($size, $decimals) . ' ' . self::BYTE_UNITS[$unitIndex];
    }

    public function formatBytesShort(int $bytes): string
    {
        if ($bytes < 0) {
            throw new \InvalidArgumentException('Bytes cannot be negative');
        }

        if ($bytes === 0) {
            return '0B';
        }

        $unitIndex = 0;
        $size = (float) $bytes;

        while ($size >= self::BYTE_THRESHOLD && $unitIndex < count(self::BYTE_UNITS) - 1) {
            $size /= self::BYTE_THRESHOLD;
            $unitIndex++;
        }

        $shortUnit = rtrim(rtrim(number_format($size, 1), '0'), '.') . self::BYTE_UNITS[$unitIndex];

        return $shortUnit;
    }

    public function parseSizeString(string $sizeString): int
    {
        $sizeString = trim($sizeString);

        $pattern = '/^(\d+(?:\.\d+)?)\s*([A-Za-z]+)$/';

        if (!preg_match($pattern, $sizeString, $matches)) {
            throw new \InvalidArgumentException("Invalid size string: {$sizeString}");
        }

        $value = (float) $matches[1];
        $unit = strtoupper($matches[2]);

        $unitIndex = array_search($unit, self::BYTE_UNITS, true);

        if ($unitIndex === false) {
            $unitIndex = $this->resolveUnitAlias($unit);
        }

        if ($unitIndex === false) {
            throw new \InvalidArgumentException("Unknown unit: {$unit}");
        }

        return (int) round($value * pow(self::BYTE_THRESHOLD, $unitIndex));
    }

    public function convertToUnit(int $bytes, string $targetUnit): float
    {
        $unitIndex = $this->resolveUnitIndex($targetUnit);

        return $bytes / pow(self::BYTE_THRESHOLD, $unitIndex);
    }

    public function convertFromUnit(float $value, string $sourceUnit): int
    {
        $unitIndex = $this->resolveUnitIndex($sourceUnit);

        return (int) round($value * pow(self::BYTE_THRESHOLD, $unitIndex));
    }

    public function isValidUnit(string $unit): bool
    {
        $normalized = strtoupper(trim($unit));

        return in_array($normalized, self::BYTE_UNITS, true)
            || $this->resolveUnitAlias($normalized) !== false;
    }

    public function getBestUnit(int $bytes): string
    {
        if ($bytes === 0) {
            return 'B';
        }

        $unitIndex = 0;
        $size = (float) $bytes;

        while ($size >= self::BYTE_THRESHOLD && $unitIndex < count(self::BYTE_UNITS) - 1) {
            $size /= self::BYTE_THRESHOLD;
            $unitIndex++;
        }

        return self::BYTE_UNITS[$unitIndex];
    }

    public function sumSizes(array $sizeStrings): int
    {
        $total = 0;

        foreach ($sizeStrings as $sizeString) {
            $total += $this->parseSizeString($sizeString);
        }

        return $total;
    }

    public function compareSizes(int $bytes1, int $bytes2): int
    {
        return $bytes1 <=> $bytes2;
    }

    public function isWithinLimit(int $bytes, int $limit): bool
    {
        return $bytes <= $limit;
    }

    public function formatRange(int $minBytes, int $maxBytes, int $decimals = 2): string
    {
        return $this->formatBytes($minBytes, $decimals) . ' - ' . $this->formatBytes($maxBytes, $decimals);
    }

    public function percentageUsed(int $used, int $total): float
    {
        if ($total === 0) {
            throw new \InvalidArgumentException('Total cannot be zero');
        }

        return round(($used / $total) * 100, 2);
    }

    public function formatPercentage(float $percentage, int $decimals = 1): string
    {
        return number_format($percentage, $decimals) . '%';
    }

    public function format带宽(int $bytesPerSecond, int $decimals = 2): string
    {
        return $this->formatBytes($bytesPerSecond, $decimals) . '/s';
    }

    public function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . 's';
        }

        if ($seconds < 3600) {
            return round($seconds / 60, 1) . 'm';
        }

        if ($seconds < 86400) {
            return round($seconds / 3600, 1) . 'h';
        }

        return round($seconds / 86400, 1) . 'd';
    }

    private function resolveUnitIndex(string $unit): int
    {
        $normalized = strtoupper(trim($unit));

        $index = array_search($normalized, self::BYTE_UNITS, true);

        if ($index !== false) {
            return $index;
        }

        $aliasIndex = $this->resolveUnitAlias($normalized);

        if ($aliasIndex !== false) {
            return $aliasIndex;
        }

        throw new \InvalidArgumentException("Unknown unit: {$unit}");
    }

    private function resolveUnitAlias(string $unit): int|false
    {
        $aliases = [
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

        return $aliases[$unit] ?? false;
    }
}
