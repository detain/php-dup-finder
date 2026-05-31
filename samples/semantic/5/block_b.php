<?php

declare(strict_types=1);

namespace Acme\Api\Checkout;

use Acme\Api\Model\Shipment;
use Acme\Api\Enum\Region;

final class ShippingEstimateController
{
    public function estimate(Shipment $shipment): array
    {
        $kg = $shipment->totalWeightKg();
        $region = $shipment->destinationRegion();
        $tier = $shipment->customer()->tier();

        $freeShipping = $kg <= 5.0
            && $region === Region::DOMESTIC
            && in_array($tier, ['gold', 'platinum'], true);

        if ($freeShipping) {
            return [
                'method' => 'standard',
                'cost_cents' => 0,
                'promo' => 'FREE_SHIPPING_MEMBER',
            ];
        }

        return [
            'method' => 'standard',
            'cost_cents' => $this->computeCost($kg),
            'promo' => null,
        ];
    }

    private function computeCost(float $kg): int
    {
        return (int) round((4.99 + $kg * 0.5) * 100);
    }
}
