<?php

declare(strict_types=1);

namespace App\Retry;

use Random\Randomizer;

final class ExponentialBackoff
{
    private const MAX_SHIFT = 16;

    public function __construct(
        private Randomizer $rng,
        private int $baseMs = 250,
        private int $capMs = 60_000,
        private float $jitterRatio = 0.20,
    ) {
        if ($baseMs <= 0 || $capMs < $baseMs) {
            throw new \InvalidArgumentException('Invalid base/cap');
        }
        if ($jitterRatio < 0.0 || $jitterRatio > 1.0) {
            throw new \InvalidArgumentException('jitterRatio must be in [0,1]');
        }
    }

    public function delayMs(int $attempt): int
    {
        $base = $this->baseDelayMs($attempt);
        $jitter = $this->jitterAround($base);

        return $this->clamp($base + $jitter);
    }

    private function baseDelayMs(int $attempt): int
    {
        $exponent = min(max($attempt, 1) - 1, self::MAX_SHIFT);
        $raw = $this->baseMs * (1 << $exponent);

        return min($raw, $this->capMs);
    }

    private function jitterAround(int $base): int
    {
        $window = (int) round($base * $this->jitterRatio);

        return $window === 0 ? 0 : $this->rng->getInt(-$window, $window);
    }

    private function clamp(int $value): int
    {
        return max(0, min($value, $this->capMs));
    }
}
