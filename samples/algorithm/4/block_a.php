<?php

declare(strict_types=1);

namespace Acme\Observability\Metrics;

use Acme\Observability\Metrics\Dto\Sample;

final class CpuLoadAverager
{
    /** @var list<Sample> */
    private array $samples = [];

    public function __construct(private readonly int $windowSeconds = 60)
    {
    }

    public function record(float $value, int $timestamp): void
    {
        $this->samples[] = new Sample($timestamp, $value);

        $cutoff = $timestamp - $this->windowSeconds;
        while ($this->samples !== [] && $this->samples[0]->timestamp < $cutoff) {
            array_shift($this->samples);
        }
    }

    public function currentAverage(int $now): float
    {
        $cutoff = $now - $this->windowSeconds;
        $count = 0;
        $sum = 0.0;
        foreach ($this->samples as $sample) {
            if ($sample->timestamp < $cutoff) {
                continue;
            }
            $sum += $sample->value;
            $count++;
        }

        if ($count === 0) {
            return 0.0;
        }

        return round($sum / $count, 3);
    }

    public function reset(): void
    {
        $this->samples = [];
    }
}
