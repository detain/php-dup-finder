<?php

declare(strict_types=1);

namespace Acme\Shipping\Dhl;

use Acme\Shipping\Common\ShipmentRequest;
use Acme\Shipping\Common\Quote;

final class DhlExpressEstimator
{
    /** @var array<string,array<string,float>> */
    private const ZONE_RATES = [
        'NA' => ['NA' => 1.00, 'EU' => 4.50, 'APAC' => 5.50],
        'EU' => ['NA' => 4.50, 'EU' => 1.20, 'APAC' => 4.80],
        'APAC' => ['NA' => 5.50, 'EU' => 4.80, 'APAC' => 1.40],
    ];

    private const SERVICE_MULTIPLIER = [
        'economy'   => 1.0,
        'standard'  => 1.3,
        'express'   => 2.0,
        'overnight' => 3.5,
    ];

    public function estimate(ShipmentRequest $request): Quote
    {
        $originZone = $this->regionOf($request->originZip);
        $destZone = $this->regionOf($request->destinationZip);

        $perPound = self::ZONE_RATES[$originZone][$destZone] ?? 5.00;
        $base = $request->weightLb * $perPound;
        $multiplier = self::SERVICE_MULTIPLIER[$request->serviceLevel] ?? 1.0;

        $total = round($base * $multiplier, 2);

        return new Quote(carrier: 'dhl', service: $request->serviceLevel, amount: $total);
    }

    private function regionOf(string $code): string
    {
        return match (substr($code, 0, 2)) {
            'US', 'CA', 'MX' => 'NA',
            'GB', 'DE', 'FR', 'IT', 'ES', 'NL' => 'EU',
            default => 'APAC',
        };
    }
}
