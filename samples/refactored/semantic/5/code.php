<?php

declare(strict_types=1);

namespace Acme\Shared\Policy;

use Acme\Shared\Model\Shipment;

final class FreeShippingPolicy
{
    /** @param list<string> $eligibleTiers */
    public function __construct(
        private int $maxWeightGrams = 5000,
        private string $domesticCountryCode = 'US',
        private array $eligibleTiers = ['gold', 'platinum'],
    ) {
    }

    public function qualifies(Shipment $shipment): bool
    {
        if ($shipment->totalWeightGrams() > $this->maxWeightGrams) {
            return false;
        }

        if ($shipment->destinationCountry() !== $this->domesticCountryCode) {
            return false;
        }

        $tier = strtolower($shipment->customerTier());
        return in_array($tier, $this->eligibleTiers, true);
    }
}

final class CartShippingCalculator
{
    public function __construct(private FreeShippingPolicy $policy) {}

    public function shippingCostCents(Shipment $s): int
    {
        if ($this->policy->qualifies($s)) {
            return 0;
        }
        return (int) round((4.99 + ($s->totalWeightGrams() / 1000) * 0.5) * 100);
    }
}
