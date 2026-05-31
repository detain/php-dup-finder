<?php
declare(strict_types=1);

namespace Shipping\Rules;

final class ShippingCostCalculator
{
    private const FREE_SHIPPING_THRESHOLD = 75;
    private const FREE_SHIPPING_WEIGHT_LIMIT = 5;
    private const EXPEDITED_BASE_COST = 15;
    private const OVERSIZED = 25;
    private const FRAGILE_SURCHARGE = 10;
    private const HAZMAT_SURCHARGE = 30;

    private const ZONE_1_MULTIPLIER = 1.0;
    private const ZONE_2_MULTIPLIER = 1.5;
    private const ZONE_3_MULTIPLIER = 2.0;
    private const ZONE_4_MULTIPLIER = 2.5;

    public function calculateShipping(ShipmentRequest $request): ShippingCostResult
    {
        $baseRate = $this->determineBaseRate($request);
        $weightSurcharge = $this->calculateWeightSurcharge($request);
        $dimensionSurcharge = $this->calculateDimensionSurcharge($request);
        $handlingSurcharges = $this->calculateHandlingSurcharges($request);
        $zoneMultiplier = $this->getZoneMultiplier($request->getDestinationZone());

        $subtotal = $baseRate + $weightSurcharge + $dimensionSurcharge + $handlingSurcharges;
        $totalCost = $subtotal * $zoneMultiplier;

        $freeShippingEligible = $this->checkFreeShippingEligibility($request, $totalCost);

        return new ShippingCostResult(
            baseCost: $baseRate,
            surcharges: [
                'weight' => $weightSurcharge,
                'dimension' => $dimensionSurcharge,
                'handling' => $handlingSurcharges,
            ],
            zoneMultiplier: $zoneMultiplier,
            totalCost: $totalCost,
            freeShippingApplied: $freeShippingEligible,
            deliveryDays: $this->estimateDeliveryDays($request),
        );
    }

    private function determineBaseRate(ShipmentRequest $request): float
    {
        $serviceLevel = $request->getServiceLevel();

        $baseRates = [
            'standard' => 5.99,
            'expedited' => self::EXPEDITED_BASE_COST,
            'overnight' => 35.00,
            'freight' => 75.00,
        ];

        return $baseRates[$serviceLevel] ?? 5.99;
    }

    private function calculateWeightSurcharge(ShipmentRequest $request): float
    {
        $weight = $request->getWeightKilograms();

        if ($weight <= 1) {
            return 0.0;
        }

        if ($weight <= 5) {
            return 2.50;
        }

        if ($weight <= 10) {
            return 5.00;
        }

        if ($weight <= 25) {
            return 10.00;
        }

        if ($weight <= 50) {
            return 18.00;
        }

        return 25.00 + (($weight - 50) * 0.50);
    }

    private function calculateDimensionSurcharge(ShipmentRequest $request): float
    {
        $length = $request->getLengthCentimeters();
        $width = $request->getWidthCentimeters();
        $height = $request->getHeightCentimeters();

        $volumetricWeight = ($length * $width * $height) / 5000;

        if ($volumetricWeight > 50) {
            return self::OVERSIZED;
        }

        if ($volumetricWeight > 25) {
            return 15.00;
        }

        return 0.0;
    }

    private function calculateHandlingSurcharges(ShipmentRequest $request): float
    {
        $totalSurcharge = 0.0;

        if ($request->isFragile()) {
            $totalSurcharge += self::FRAGILE_SURCHARGE;
        }

        if ($request->containsHazmat()) {
            $totalSurcharge += self::HAZMAT_SURCHARGE;
        }

        if ($request->requiresSignature()) {
            $totalSurcharge += 3.50;
        }

        if ($request->isRefrigerated()) {
            $totalSurcharge += 12.00;
        }

        return $totalSurcharge;
    }

    private function getZoneMultiplier(string $destinationZone): float
    {
        return match ($destinationZone) {
            'zone_1' => self::ZONE_1_MULTIPLIER,
            'zone_2' => self::ZONE_2_MULTIPLIER,
            'zone_3' => self::ZONE_3_MULTIPLIER,
            'zone_4' => self::ZONE_4_MULTIPLIER,
            default => self::ZONE_1_MULTIPLIER,
        };
    }

    private function checkFreeShippingEligibility(ShipmentRequest $request, float $totalCost): bool
    {
        $cartTotal = $request->getCartTotal();

        if ($cartTotal >= self::FREE_SHIPPING_THRESHOLD) {
            if ($request->getWeightKilograms() <= self::FREE_SHIPPING_WEIGHT_LIMIT) {
                return true;
            }
        }

        return false;
    }

    private function estimateDeliveryDays(ShipmentRequest $request): int
    {
        $serviceLevel = $request->getServiceLevel();
        $destinationZone = $request->getDestinationZone();

        $baseDays = match ($serviceLevel) {
            'standard' => 5,
            'expedited' => 2,
            'overnight' => 1,
            'freight' => 7,
            default => 5,
        };

        $zoneDelay = match ($destinationZone) {
            'zone_1' => 0,
            'zone_2' => 1,
            'zone_3' => 2,
            'zone_4' => 3,
            default => 0,
        };

        return $baseDays + $zoneDelay;
    }
}
