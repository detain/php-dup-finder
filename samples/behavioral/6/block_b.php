<?php

declare(strict_types=1);

namespace Queue\Backoff;

final class PowBasedBackoff
{
    public function __construct(
        private \Random\Randomizer $rng,
        private int $baseMs = 250,
        private int $capMs = 60_000,
        private float $jitterRatio = 0.20,
    ) {
    }

    public function compute(int $attempt): int
    {
        $attempt = max(1, $attempt);
        $exp = (int) min($attempt - 1, 16);

        $base = $this->baseMs * (int) pow(2, $exp);
        $base = (int) min($base, $this->capMs);

        $jitterMax = (int) round($base * $this->jitterRatio);
        $jitter = $this->rng->getInt(-$jitterMax, $jitterMax);

        $delay = $base + $jitter;

        return (int) max(0, min($delay, $this->capMs));
    }
}
