<?php
declare(strict_types=1);

namespace Phpdup\Util;

final class MemoryDebug
{
    public static function getMemoryUsage(): string
    {
        $rss = self::toMb(memory_get_usage(false));
        $peak = self::toMb(memory_get_peak_usage(true));
        $free = self::getAvailableMemory();
        $freeStr = $free === -1 ? 'unlimited' : self::toMb($free) . 'MB';

        return "RSS {$rss}MB | peak {$peak}MB | free {$freeStr}";
    }

    private static function toMb(int $bytes): int
    {
        return (int) ($bytes / 1024 / 1024);
    }

    private static function getAvailableMemory(): int
    {
        $limit = ini_get('memory_limit');
        if ($limit === '-1' || $limit === '') {
            return -1; // unlimited
        }

        $limitBytes = self::parseMemoryLimit($limit);
        $currentUsage = memory_get_usage(false);

        return $limitBytes - $currentUsage;
    }

    private static function parseMemoryLimit(string $limit): int
    {
        $limit = trim($limit);
        $lastChar = strtolower(substr($limit, -1));
        $value = (int) $limit;

        return match ($lastChar) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }
}
