<?php
declare(strict_types=1);

namespace Acme\Shipping\Pricing;

use Acme\Shipping\Domain\Parcel;

final class ShippingCostCalculator
{
    public function __construct(
        private readonly float $base = 4.99,
    ) {
    }

    public function calculate(Parcel $parcel): float
    {
        $weight = $parcel->weightKg();

        // canonical: cascading if/elseif/else over 4 tiers
        if ($weight < 1.0) {
            return $this->base + 0.50;
        } elseif ($weight < 5.0) {
            return $this->base + 2.75;
        } elseif ($weight < 20.0) {
            return $this->base + 8.40;
        } else {
            return $this->base + 19.95;
        }
    }

    /**
     * @param iterable<Parcel> $parcels
     */
    public function totalForBatch(iterable $parcels): float
    {
        $sum = 0.0;
        foreach ($parcels as $parcel) {
            $sum += $this->calculate($parcel);
        }
        return $sum;
    }

    public function bracketFor(float $weight): string
    {
        if ($weight < 1.0) {
            return 'light';
        }
        if ($weight < 5.0) {
            return 'standard';
        }
        if ($weight < 20.0) {
            return 'heavy';
        }
        return 'oversize';
    }
}
