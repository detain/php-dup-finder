<?php
declare(strict_types=1);

namespace ShippingEngine\CostCalculator;

use Psr\Log\LoggerInterface;

final class StandardShippingCalculator
{
    private const BASE_RATE = 5.99;
    private const WEIGHT_RATE_PER_KG = 1.50;
    private const VOLUME_RATE_PER_CUBIC_CM = 0.0002;
    private const ZONE_MULTIPLIER_ZONE_1 = 1.0;
    private const ZONE_MULTIPLIER_ZONE_2 = 1.5;
    private const ZONE_MULTIPLIER_ZONE_3 = 2.0;
    private const ZONE_MULTIPLIER_ZONE_4 = 2.5;
    private const ZONE_MULTIPLIER_ZONE_5 = 3.0;

    private const MAX_WEIGHT_KG = 30.0;
    private const MAX_DIMENSION_CM = 200.0;
    private const FREE_SHIPPING_THRESHOLD = 100.00;
    private const FUEL_SURCHARGE_PERCENTAGE = 0.08;
    private const RESIDENTIAL_SURCHARGE = 3.50;
    private const SIGNATURE_REQUIRED_FEE = 2.99;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function calculateCost(ShipmentDetails $shipment): ShippingCostResult
    {
        $this->logger->debug('Calculating standard shipping cost', [
            'destination' => $shipment->getDestinationZone(),
        ]);

        if ($shipment->getPackageWeight() > self::MAX_WEIGHT_KG) {
            throw new \InvalidArgumentException('Package weight exceeds maximum for standard shipping');
        }

        $volumetricWeight = $this->calculateVolumetricWeight($shipment);
        $chargeableWeight = max($shipment->getPackageWeight(), $volumetricWeight);

        $baseShippingCost = self::BASE_RATE;
        $weightCharge = $chargeableWeight * self::WEIGHT_RATE_PER_KG;
        $volumeCharge = $this->calculateVolumeCharge($shipment);
        $zoneMultiplier = $this->getZoneMultiplier($shipment->getDestinationZone());

        $subtotal = ($baseShippingCost + $weightCharge + $volumeCharge) * $zoneMultiplier;

        $surcharges = $this->calculateSurcharges($shipment);
        $totalSurcharges = array_sum($surcharges);

        $fuelSurcharge = $subtotal * self::FUEL_SURCHARGE_PERCENTAGE;

        $totalCost = $subtotal + $totalSurcharges + $fuelSurcharge;

        if ($shipment->getSubtotal() >= self::FREE_SHIPPING_THRESHOLD) {
            $totalCost = 0.0;
            $this->logger->info('Free shipping applied', ['subtotal' => $shipment->getSubtotal()]);
        }

        $estimatedDays = $this->calculateDeliveryDays($shipment->getDestinationZone());

        $this->logger->info('Standard shipping cost calculated', [
            'subtotal' => $subtotal,
            'surcharges' => $totalSurcharges,
            'fuel_surcharge' => $fuelSurcharge,
            'total' => $totalCost,
            'delivery_days' => $estimatedDays,
        ]);

        return new ShippingCostResult(
            baseCost: $subtotal,
            surcharges: $surcharges,
            fuelSurcharge: $fuelSurcharge,
            totalCost: $totalCost,
            currency: 'USD',
            estimatedDeliveryDays: $estimatedDays,
            serviceType: 'standard',
        );
    }

    private function calculateVolumetricWeight(ShipmentDetails $shipment): float
    {
        $volumeCm3 = $shipment->getLength() * $shipment->getWidth() * $shipment->getHeight();
        return $volumeCm3 * self::VOLUME_RATE_PER_CUBIC_CM;
    }

    private function calculateVolumeCharge(ShipmentDetails $shipment): float
    {
        $volumeCm3 = $shipment->getLength() * $shipment->getWidth() * $shipment->getHeight();
        return $volumeCm3 * self::VOLUME_RATE_PER_CUBIC_CM;
    }

    private function getZoneMultiplier(string $zone): float
    {
        return match ($zone) {
            'zone_1' => self::ZONE_MULTIPLIER_ZONE_1,
            'zone_2' => self::ZONE_MULTIPLIER_ZONE_2,
            'zone_3' => self::ZONE_MULTIPLIER_ZONE_3,
            'zone_4' => self::ZONE_MULTIPLIER_ZONE_4,
            'zone_5' => self::ZONE_MULTIPLIER_ZONE_5,
            default => self::ZONE_MULTIPLIER_ZONE_3,
        };
    }

    private function calculateSurcharges(ShipmentDetails $shipment): array
    {
        $surcharges = [];

        if ($shipment->isResidentialDelivery()) {
            $surcharges['residential'] = self::RESIDENTIAL_SURCHARGE;
        }

        if ($shipment->requiresSignature()) {
            $surcharges['signature'] = self::SIGNATURE_REQUIRED_FEE;
        }

        return $surcharges;
    }

    private function calculateDeliveryDays(string $zone): int
    {
        return match ($zone) {
            'zone_1' => 3,
            'zone_2' => 5,
            'zone_3' => 7,
            'zone_4' => 10,
            'zone_5' => 14,
            default => 7,
        };
    }
}
