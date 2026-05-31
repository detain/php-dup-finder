<?php
declare(strict_types=1);

namespace Acme\Common\Pricing;

/**
 * Resolve a tiered surcharge by amount: the first tier whose threshold the
 * amount falls under wins, otherwise the open-ended top tier wins.
 */
final class TieredAmountResolver
{
    /**
     * @param array<int, array{0:float,1:float}> $tiers  list of [thresholdExclusive, surcharge]
     */
    public static function resolve(float $amount, array $tiers, float $base, float $topTierSurcharge): float
    {
        foreach ($tiers as [$threshold, $surcharge]) {
            if ($amount < $threshold) {
                return $base + $surcharge;
            }
        }
        return $base + $topTierSurcharge;
    }
}

// usage
// TieredAmountResolver::resolve($weight, [
//     [1.0,  0.50],
//     [5.0,  2.75],
//     [20.0, 8.40],
// ], $this->base, 19.95);
//
// TieredAmountResolver::resolve($coverage, [
//     [10_000.0,  12.00],
//     [50_000.0,  65.00],
//     [250_000.0, 280.00],
// ], $this->minimumPremium, 950.00);
//
// TieredAmountResolver::resolve($principal, [
//     [5_000.0,   25.00],
//     [25_000.0,  150.00],
//     [100_000.0, 625.00],
// ], $this->floor, 2_400.00);
