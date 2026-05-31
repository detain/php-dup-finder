<?php
declare(strict_types=1);

namespace Shipping\Shared;

interface ShippingRateStrategy
{
    public function calculateRate(ShippingContext $context): ShippingRate;
    public function getServiceType(): string;
    public function getBaseRate(): float;
}

abstract class BaseShippingRateCalculator
{
    protected LoggerInterface $logger;

    protected const FREE_SHIPPING_THRESHOLD = 75;
    protected const FREE_SHIPPING_WEIGHT_LIMIT = 5;

    public function calculate(ShippingContext $context): ShippingRate
    {
        $baseRate = $this->getBaseRate();
        $weightSurcharge = $this->calculateWeightSurcharge($context->getWeight());
        $sizeSurcharge = $this->calculateSizeSurcharge($context->getDimensions());
        $handlingSurcharge = $this->calculateHandlingSurcharge($context);
        $zoneMultiplier = $this->getZoneMultiplier($context->getZone());

        $subtotal = $baseRate + $weightSurcharge + $sizeSurcharge + $handlingSurcharge;
        $totalCost = $subtotal * $zoneMultiplier;

        $freeShipping = $this->checkFreeShipping($context, $totalCost);

        return new ShippingRate(
            baseRate: $baseRate,
            surcharges: [
                'weight' => $weightSurcharge,
                'size' => $sizeSurcharge,
                'handling' => $handlingSurcharge,
            ],
            zoneMultiplier: $zoneMultiplier,
            totalCost: $freeShipping ? 0.0 : $totalCost,
            freeShippingApplied: $freeShipping,
        );
    }

    protected function calculateWeightSurcharge(float $weightKg): float
    {
        if ($weightKg <= 1) {
            return 0.0;
        }
        if ($weightKg <= 5) {
            return 2.50;
        }
        if ($weightKg <= 10) {
            return 5.00;
        }
        return 10.00 + (($weightKg - 10) * 0.75);
    }

    protected function calculateSizeSurcharge(array $dimensions): float
    {
        $volumetricWeight = ($dimensions['l'] * $dimensions['w'] * $dimensions['h']) / 5000;
        if ($volumetricWeight > 50) {
            return 25.0;
        }
        if ($volumetricWeight > 25) {
            return 15.0;
        }
        return 0.0;
    }

    protected function calculateHandlingSurcharge(ShippingContext $context): float
    {
        $surcharge = 0.0;

        if ($context->isFragile()) {
            $surcharge += 10.0;
        }
        if ($context->isHazmat()) {
            $surcharge += 30.0;
        }
        if ($context->requiresTemperatureControl()) {
            $surcharge += 15.0;
        }

        return $surcharge;
    }

    protected function checkFreeShipping(ShippingContext $context, float $totalCost): bool
    {
        if ($context->getOrderTotal() >= self::FREE_SHIPPING_THRESHOLD) {
            if ($context->getWeight() <= self::FREE_SHIPPING_WEIGHT_LIMIT) {
                return true;
            }
        }
        return false;
    }

    abstract public function getServiceType(): string;
    abstract public function getBaseRate(): float;
    abstract public function getZoneMultiplier(string $zone): float;
}

final class StandardShippingCalculator extends BaseShippingRateCalculator
{
    public function getServiceType(): string
    {
        return 'standard';
    }

    public function getBaseRate(): float
    {
        return 5.99;
    }

    public function getZoneMultiplier(string $zone): float
    {
        return match ($zone) {
            'zone_1' => 1.0,
            'zone_2' => 1.5,
            'zone_3' => 2.0,
            default => 1.0,
        };
    }
}
