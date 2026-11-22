<?php
declare(strict_types=1);

namespace Phpdup\Util;

/**
 * One-line memory gauge for debug output (php-dup-finder-engine-045).
 *
 * Every number on the line speaks ONE currency: PHP's emalloc arena as
 * actually allocated from the system — the memory_get_usage(true) /
 * memory_get_peak_usage(true) family, which is what PHP enforces
 * memory_limit against and what the --max-memory ceiling measures.
 * They are NOT OS RSS (kernel-resident pages incl. interpreter
 * text/libs): the old label read "RSS" over memory_get_usage(false)
 * bytes while "peak" silently switched to the true-argument family,
 * mixing two metrics in one line. Current and peak are now directly
 * comparable, and free is genuine headroom before the memory_limit.
 */
final class MemoryDebug
{
    public static function getMemoryUsage(): string
    {
        $emalloc = self::toMb(memory_get_usage(true));
        $peak = self::toMb(memory_get_peak_usage(true));
        $free = self::getAvailableMemory();
        $freeStr = $free === -1 ? 'unlimited' : self::toMb($free) . 'MB';

        return "emalloc={$emalloc}MB | peak_alloc={$peak}MB | free {$freeStr}";
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
        // memory_limit is enforced on the true-argument (real allocation)
        // family — headroom must be measured in the same currency.
        $currentUsage = memory_get_usage(true);

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
