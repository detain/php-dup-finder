<?php
declare(strict_types=1);

namespace Acme\PricingService\Money;

use Acme\PricingService\Cache\FxCache;

final class DisplayPriceConverter
{
    public function __construct(private readonly FxCache $fx)
    {
    }

    public function convert(float $amount, string $from, string $to): float
    {
        if ($from === $to) {
            return $this->roundHalfUp($amount);
        }

        $base = 'USD';
        $fromRate = $from === $base ? 1.0 : $this->fx->get("{$base}_{$from}");
        $toRate = $to === $base ? 1.0 : $this->fx->get("{$base}_{$to}");
        if ($fromRate <= 0.0 || $toRate <= 0.0) {
            throw new \RuntimeException("missing fx for {$from}->{$to}");
        }

        $usd = $amount / $fromRate;
        $converted = $usd * $toRate;

        if ($to !== $base) {
            $markup = 0.015;
            $converted *= (1.0 + $markup);
        }

        return $this->roundHalfUp($converted);
    }

    private function roundHalfUp(float $value): float
    {
        return floor($value * 100 + 0.5) / 100;
    }
}
