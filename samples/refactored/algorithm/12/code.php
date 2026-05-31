<?php
declare(strict_types=1);

namespace ShippingEngine\Shared;

interface ShippingCalculatorStrategy
{
    public function calculateCost(ShipmentDetails $shipment): ShippingCostResult;
    public function getServiceType(): string;
    public function getBaseRate(): float;
    public function getWeightRatePerKg(): float;
    public function getVolumeRatePerCm3(): float;
    public function getMaxWeightKg(): float;
    public function getFuelSurchargePercentage(): float;
    public function getSurcharges(ShipmentDetails $shipment): array;
}

abstract class BaseShippingCalculator implements ShippingCalculatorStrategy
{
    protected LoggerInterface $logger;

    protected function calculateChargeableWeight(ShipmentDetails $shipment): float
    {
        $actualWeight = $shipment->getPackageWeight();
        $volumeCm3 = $shipment->getLength() * $shipment->getWidth() * $shipment->getHeight();
        $volumetricWeight = $volumeCm3 * $this->getVolumeRatePerCm3();
        return max($actualWeight, $volumetricWeight);
    }

    protected function calculateSubtotal(ShipmentDetails $shipment, float $zoneMultiplier): float
    {
        $chargeableWeight = $this->calculateChargeableWeight($shipment);
        $baseCost = $this->getBaseRate();
        $weightCharge = $chargeableWeight * $this->getWeightRatePerKg();
        $volumeCharge = $this->calculateVolumeCharge($shipment);

        return ($baseCost + $weightCharge + $volumeCharge) * $zoneMultiplier;
    }

    protected function calculateVolumeCharge(ShipmentDetails $shipment): float
    {
        $volumeCm3 = $shipment->getLength() * $shipment->getWidth() * $shipment->getHeight();
        return $volumeCm3 * $this->getVolumeRatePerCm3();
    }

    protected function getZoneMultiplier(string $zone): float
    {
        $multipliers = [
            'zone_1' => 1.0,
            'zone_2' => 1.5,
            'zone_3' => 2.0,
            'zone_4' => 2.5,
            'zone_5' => 3.0,
        ];
        return $multipliers[$zone] ?? 2.0;
    }

    protected function calculateDeliveryDays(string $zone): int
    {
        return 3;
    }

    protected function validateWeight(ShipmentDetails $shipment): void
    {
        if ($shipment->getPackageWeight() > $this->getMaxWeightKg()) {
            throw new \InvalidArgumentException(
                "Package weight exceeds maximum for {$this->getServiceType()} shipping"
            );
        }
    }

    public function calculateCost(ShipmentDetails $shipment): ShippingCostResult
    {
        $this->validateWeight($shipment);

        $zoneMultiplier = $this->getZoneMultiplier($shipment->getDestinationZone());
        $subtotal = $this->calculateSubtotal($shipment, $zoneMultiplier);
        $surcharges = $this->getSurcharges($shipment);
        $totalSurcharges = array_sum($surcharges);
        $fuelSurcharge = $subtotal * $this->getFuelSurchargePercentage();
        $totalCost = $subtotal + $totalSurcharges + $fuelSurcharge;

        return new ShippingCostResult(
            baseCost: $subtotal,
            surcharges: $surcharges,
            fuelSurcharge: $fuelSurcharge,
            totalCost: $totalCost,
            currency: 'USD',
            estimatedDeliveryDays: $this->calculateDeliveryDays($shipment->getDestinationZone()),
            serviceType: $this->getServiceType(),
        );
    }
}

final class StandardShippingCalculator extends BaseShippingCalculator
{
    public function getServiceType(): string { return 'standard'; }
    public function getBaseRate(): float { return 5.99; }
    public function getWeightRatePerKg(): float { return 1.50; }
    public function getVolumeRatePerCm3(): float { return 0.0002; }
    public function getMaxWeightKg(): float { return 30.0; }
    public function getFuelSurchargePercentage(): float { return 0.08; }

    public function getSurcharges(ShipmentDetails $shipment): array
    {
        $surcharges = [];
        if ($shipment->isResidentialDelivery()) { $surcharges['residential'] = 3.50; }
        if ($shipment->requiresSignature()) { $surcharges['signature'] = 2.99; }
        return $surcharges;
    }

    protected function calculateDeliveryDays(string $zone): int
    {
        return match ($zone) {
            'zone_1' => 3, 'zone_2' => 5, 'zone_3' => 7, 'zone_4' => 10, 'zone_5' => 14, default => 7,
        };
    }
}

final class ExpressShippingCalculator extends BaseShippingCalculator
{
    public function getServiceType(): string { return 'express'; }
    public function getBaseRate(): float { return 15.99; }
    public function getWeightRatePerKg(): float { return 3.00; }
    public function getVolumeRatePerCm3(): float { return 0.0004; }
    public function getMaxWeightKg(): float { return 50.0; }
    public function getFuelSurchargePercentage(): float { return 0.10; }

    public function getSurcharges(ShipmentDetails $shipment): array
    {
        $surcharges = [];
        if ($shipment->isResidentialDelivery()) { $surcharges['residential'] = 4.50; }
        if ($shipment->requiresSignature()) { $surcharges['signature'] = 2.99; }
        if ($shipment->hasPriorityHandling()) { $surcharges['priority_handling'] = 5.00; }
        return $surcharges;
    }

    protected function calculateDeliveryDays(string $zone): int
    {
        return match ($zone) {
            'zone_1' => 1, 'zone_2' => 2, 'zone_3' => 2, 'zone_4' => 3, 'zone_5' => 3, default => 2,
        };
    }
}
