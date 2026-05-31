<?php

declare(strict_types=1);

namespace Acme\Shipping\Common;

final class ZoneRateEstimator
{
    /**
     * @param array<string,array<string,float>> $zoneRates
     * @param array<string,float>               $serviceMultipliers
     * @param callable(string):string           $zoneResolver
     */
    public function __construct(
        private readonly string $carrier,
        private readonly array $zoneRates,
        private readonly array $serviceMultipliers,
        private readonly float $fallbackRate,
        private $zoneResolver,
    ) {
    }

    public function estimate(ShipmentRequest $request): Quote
    {
        $origin = ($this->zoneResolver)($request->originZip);
        $destination = ($this->zoneResolver)($request->destinationZip);

        $perPound = $this->zoneRates[$origin][$destination] ?? $this->fallbackRate;
        $base = $request->weightLb * $perPound;
        $multiplier = $this->serviceMultipliers[$request->serviceLevel] ?? 1.0;

        return new Quote(
            carrier: $this->carrier,
            service: $request->serviceLevel,
            amount: round($base * $multiplier, 2),
        );
    }
}
