<?php

declare(strict_types=1);

namespace Acme\Trading\Indicators;

use Acme\Trading\Indicators\Dto\PriceTick;

final class TrailingHighWaterMark
{
    /** @var list<PriceTick> */
    private array $ticks = [];

    public function __construct(private readonly int $windowSeconds = 1800)
    {
    }

    public function tick(float $price, int $timestamp): void
    {
        $this->ticks[] = new PriceTick($timestamp, $price);

        $cutoff = $timestamp - $this->windowSeconds;
        while ($this->ticks !== [] && $this->ticks[0]->timestamp < $cutoff) {
            array_shift($this->ticks);
        }
    }

    public function currentHigh(int $now): float
    {
        $cutoff = $now - $this->windowSeconds;
        $max = PHP_FLOAT_MIN;
        $found = false;
        foreach ($this->ticks as $tick) {
            if ($tick->timestamp < $cutoff) {
                continue;
            }
            if ($tick->price > $max) {
                $max = $tick->price;
                $found = true;
            }
        }

        return $found ? round($max, 4) : 0.0;
    }

    public function flush(): void
    {
        $this->ticks = [];
    }
}
