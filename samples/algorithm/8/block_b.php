<?php

declare(strict_types=1);

namespace Acme\Shipping\Usps;

use Acme\Shipping\Common\ShipmentRequest;
use Acme\Shipping\Common\Quote;

final class UspsRetailEstimator
{
    /** @var array<string,array<string,float>> */
    private const ZONE_RATES = [
        'Z1' => ['Z1' => 0.65, 'Z2' => 0.85, 'Z3' => 1.00],
        'Z2' => ['Z1' => 0.85, 'Z2' => 0.70, 'Z3' => 0.95],
        'Z3' => ['Z1' => 1.00, 'Z2' => 0.95, 'Z3' => 0.75],
    ];

    private const SERVICE_MULTIPLIER = [
        'media'          => 0.7,
        'first_class'    => 1.0,
        'priority'       => 1.5,
        'priority_express' => 2.4,
    ];

    public function estimate(ShipmentRequest $request): Quote
    {
        $originZone = $this->zoneOf($request->originZip);
        $destZone = $this->zoneOf($request->destinationZip);

        $perPound = self::ZONE_RATES[$originZone][$destZone] ?? 1.20;
        $base = $request->weightLb * $perPound;
        $multiplier = self::SERVICE_MULTIPLIER[$request->serviceLevel] ?? 1.0;

        $total = round($base * $multiplier, 2);

        return new Quote(carrier: 'usps', service: $request->serviceLevel, amount: $total);
    }

    private function zoneOf(string $zip): string
    {
        $prefix = (int) substr($zip, 0, 1);
        if ($prefix <= 2) {
            return 'Z1';
        }
        if ($prefix <= 6) {
            return 'Z2';
        }

        return 'Z3';
    }
}
