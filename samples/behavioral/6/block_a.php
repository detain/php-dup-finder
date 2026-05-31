<?php

declare(strict_types=1);

namespace Jobs\Retry\Schedule;

final class BitShiftBackoff
{
    private const BASE_MS = 250;
    private const MAX_MS  = 60_000;
    private const JITTER_BPS = 2_000; // 20% jitter

    public function __construct(private \Random\Randomizer $rng)
    {
    }

    public function delayMs(int $attempt): int
    {
        if ($attempt < 1) {
            $attempt = 1;
        }

        $shift = min($attempt - 1, 16);
        $base = self::BASE_MS * (1 << $shift);

        if ($base > self::MAX_MS) {
            $base = self::MAX_MS;
        }

        $jitterRange = (int) ($base * self::JITTER_BPS / 10_000);
        $offset = $this->rng->getInt(-$jitterRange, $jitterRange);

        $result = $base + $offset;
        if ($result < 0) {
            $result = 0;
        }
        if ($result > self::MAX_MS) {
            $result = self::MAX_MS;
        }

        return $result;
    }
}
