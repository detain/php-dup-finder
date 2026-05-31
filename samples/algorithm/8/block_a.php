<?php

declare(strict_types=1);

namespace Acme\Shipping\Ups;

use Acme\Shipping\Common\ShipmentRequest;
use Acme\Shipping\Common\Quote;

final class UpsGroundEstimator
{
    /** @var array<string,array<string,float>> */
    private const ZONE_RATES = [
        'WEST'    => ['WEST' => 0.85, 'CENTRAL' => 1.10, 'EAST' => 1.45],
        'CENTRAL' => ['WEST' => 1.10, 'CENTRAL' => 0.90, 'EAST' => 1.05],
        'EAST'    => ['WEST' => 1.45, 'CENTRAL' => 1.05, 'EAST' => 0.80],
    ];

    private const SERVICE_MULTIPLIER = [
        'ground'     => 1.0,
        'three_day'  => 1.4,
        'two_day'    => 1.8,
        'next_day'   => 2.6,
    ];

    public function estimate(ShipmentRequest $request): Quote
    {
        $originZone = $this->zoneOf($request->originZip);
        $destZone = $this->zoneOf($request->destinationZip);

        $perPound = self::ZONE_RATES[$originZone][$destZone] ?? 1.50;
        $base = $request->weightLb * $perPound;
        $multiplier = self::SERVICE_MULTIPLIER[$request->serviceLevel] ?? 1.0;

        $total = round($base * $multiplier, 2);

        return new Quote(carrier: 'ups', service: $request->serviceLevel, amount: $total);
    }

    private function zoneOf(string $zip): string
    {
        $prefix = (int) substr($zip, 0, 1);
        if ($prefix <= 3) {
            return 'WEST';
        }
        if ($prefix <= 6) {
            return 'CENTRAL';
        }

        return 'EAST';
    }
}
