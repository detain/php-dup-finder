<?php

declare(strict_types=1);

namespace Worker\RetryPolicy;

final class TableLookupBackoff
{
    private const TABLE_MS = [
        250, 500, 1_000, 2_000, 4_000, 8_000, 16_000,
        32_000, 60_000, 60_000, 60_000, 60_000, 60_000,
        60_000, 60_000, 60_000, 60_000,
    ];
    private const MAX_MS = 60_000;

    public function __construct(private \Random\Randomizer $rng)
    {
    }

    public function nextDelay(int $attempt): int
    {
        $idx = $attempt <= 0 ? 0 : $attempt - 1;
        $idx = $idx >= count(self::TABLE_MS) ? count(self::TABLE_MS) - 1 : $idx;

        $base = self::TABLE_MS[$idx];

        $jitterWindow = intdiv($base * 20, 100);
        $offset = $jitterWindow === 0
            ? 0
            : $this->rng->getInt(-$jitterWindow, $jitterWindow);

        $value = $base + $offset;

        if ($value < 0) {
            return 0;
        }
        if ($value > self::MAX_MS) {
            return self::MAX_MS;
        }

        return $value;
    }
}
