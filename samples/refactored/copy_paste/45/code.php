<?php

namespace App\Services\System;

final class FileSizeConfig
{
    public readonly int $decimals;
    public readonly bool $compact;

    public function __construct(int $decimals = 2, bool $compact = false)
    {
        $this->decimals = $decimals;
        $this->compact = $compact;
    }
}

final class FileSizeService
{
    private FileSizeConfig $config;

    private const UNITS = ['B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB'];
    private const BASE = 1024;

    public function __construct(FileSizeConfig $config)
    {
        $this->config = $config;
    }

    public function format(int $bytes): string
    {
        if ($bytes < 0) {
            throw new \InvalidArgumentException('Bytes cannot be negative');
        }

        if ($bytes === 0) {
            return '0 B';
        }

        $unitIndex = 0;
        $size = (float) $bytes;

        while ($size >= self::BASE && $unitIndex < count(self::UNITS) - 1) {
            $size /= self::BASE;
            $unitIndex++;
        }

        $formatted = number_format($size, $this->config->decimals);

        if ($this->config->compact) {
            $formatted = rtrim(rtrim($formatted, '0'), '.');
        }

        return $formatted . ' ' . self::UNITS[$unitIndex];
    }

    public function parse(string $sizeString): int
    {
        if (!preg_match('/^(\d+(?:\.\d+)?)\s*([A-Za-z]+)$/', trim($sizeString), $matches)) {
            throw new \InvalidArgumentException("Invalid size string: {$sizeString}");
        }

        $value = (float) $matches[1];
        $unit = strtoupper($matches[2]);

        $unitIndex = $this->resolveUnitIndex($unit);

        return (int) round($value * pow(self::BASE, $unitIndex));
    }

    public function getUnitIndex(string $unit): int
    {
        return $this->resolveUnitIndex($unit);
    }

    private function resolveUnitIndex(string $unit): int
    {
        $index = array_search($unit, self::UNITS, true);

        if ($index !== false) {
            return $index;
        }

        $aliases = ['K' => 1, 'M' => 2, 'G' => 3, 'T' => 4, 'P' => 5, 'E' => 6];

        if (isset($aliases[$unit])) {
            return $aliases[$unit];
        }

        throw new \InvalidArgumentException("Unknown unit: {$unit}");
    }
}
