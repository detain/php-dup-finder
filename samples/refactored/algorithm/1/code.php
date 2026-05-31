<?php

declare(strict_types=1);

namespace Acme\Wholesale\Pricing;

use Acme\Wholesale\Cart\LineItem;

final class TieredVolumeDiscount
{
    /**
     * @param list<array{min:int, rate:float}> $tiers
     */
    public function __construct(
        private readonly array $tiers,
        private readonly TierObserver $observer,
        private readonly string $channel,
    ) {
    }

    /**
     * @param LineItem[] $items
     */
    public function priceCart(array $items, string $accountKey): float
    {
        $subtotal = 0.0;
        $units = 0;
        foreach ($items as $line) {
            $subtotal += $line->unitPrice * $line->quantity;
            $units += $line->quantity;
        }

        $tiers = $this->tiers;
        usort($tiers, static fn(array $a, array $b): int => $a['min'] <=> $b['min']);

        $rate = 0.0;
        foreach ($tiers as $tier) {
            if ($units >= $tier['min']) {
                $rate = $tier['rate'];
            }
        }

        $finalPrice = $subtotal - ($subtotal * $rate);

        $this->observer->tierApplied($this->channel, $accountKey, $units, $rate, $subtotal, $finalPrice);

        return round($finalPrice, 2);
    }
}
