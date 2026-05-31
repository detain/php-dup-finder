<?php
declare(strict_types=1);

namespace Shipping\Rules;

final class ParcelRateCalculator
{
    private const FREE_SHIPPING_ORDER_TOTAL = 75;
    private const FREE_SHIPPING_MAX_WEIGHT = 5;
    private const GROUND_RATE = 5.99;
    private const EXPRESS_RATE = 15.00;
    private const OVERNIGHT_RATE = 35.00;
    private const WHITE_GLOVE_RATE = 89.00;
    private const OVERSIZED_PENALTY = 25;
    private const CARE_PACKAGE_FEE = 10;
    private const DANGEROUS_GOODS_FEE = 30;

    private const COASTAL_MULTIPLIER = 1.0;
    private const MIDWEST_MULTIPLIER = 1.25;
    private const MOUNTAIN_MULTIPLIER = 1.50;
    private const WEST_COAST_MULTIPLIER = 2.0;
    private const EAST_COAST_MULTIPLIER = 1.15;

    public function calculateParcelRate(ParcelShipmentQuote $quote): ParcelRateQuote
    {
        $transportCost = $this->deriveTransportCost($quote->getServiceClass());
        $massSurcharge = $this->deriveMassSurcharge($quote->getWeightKg());
        $volumeSurcharge = $this->deriveVolumeSurcharge($quote->getCubicCentimeters());
        $handlingCosts = $this->deriveHandlingCosts($quote);
        $distanceMultiplier = $this->resolveDistanceMultiplier($quote->getOriginRegion(), $quote->getDestinationRegion());

        $assessedCost = $transportCost + $massSurcharge + $volumeSurcharge + $handlingCosts;
        $billedCost = $assessedCost * $distanceMultiplier;

        $waivedShipping = $this->evaluateShippingWaiver($quote, $billedCost);

        return new ParcelRateQuote(
            transportCost: $transportCost,
            surcharges: [
                'mass' => $massSurcharge,
                'volume' => $volumeSurcharge,
                'handling' => $handlingCosts,
            ],
            distanceMultiplier: $distanceMultiplier,
            billedCost: $billedCost,
            shippingWaived: $waivedShipping,
            transitTimeDays: $this->projectTransitDays($quote),
        );
    }

    private function deriveTransportCost(string $serviceClass): float
    {
        return match ($serviceClass) {
            'ground' => self::GROUND_RATE,
            'express' => self::EXPRESS_RATE,
            'overnight' => self::OVERNIGHT_RATE,
            'white_glove' => self::WHITE_GLOVE_RATE,
            'economy' => 4.99,
            default => self::GROUND_RATE,
        };
    }

    private function deriveMassSurcharge(float $massKg): float
    {
        if ($massKg <= 0.5) {
            return 0.0;
        }

        if ($massKg <= 2) {
            return 1.00;
        }

        if ($massKg <= 5) {
            return 2.50;
        }

        if ($massKg <= 10) {
            return 5.00;
        }

        if ($massKg <= 20) {
            return 9.00;
        }

        if ($massKg <= 40) {
            return 15.00;
        }

        return 20.00 + (($massKg - 40) * 0.35);
    }

    private function deriveVolumeSurcharge(float $cubicCm): float
    {
        $volumetricMass = $cubicCm / 5000;

        if ($volumetricMass > 60) {
            return self::OVERSIZED_PENALTY;
        }

        if ($volumetricMass > 30) {
            return 18.00;
        }

        if ($volumetricMass > 15) {
            return 9.00;
        }

        return 0.0;
    }

    private function deriveHandlingCosts(ParcelShipmentQuote $quote): float
    {
        $handling = 0.0;

        if ($quote->isFragile()) {
            $handling += self::CARE_PACKAGE_FEE;
        }

        if ($quote->containsLithiumBatteries()) {
            $handling += 8.00;
        }

        if ($quote->isControlledTemperature()) {
            $handling += 14.00;
        }

        if ($quote->requiresCustomsDocumentation()) {
            $handling += 12.00;
        }

        return $handling;
    }

    private function resolveDistanceMultiplier(string $origin, string $destination): float
    {
        $regionMultipliers = [
            'coastal' => self::COASTAL_MULTIPLIER,
            'midwest' => self::MIDWEST_MULTIPLIER,
            'mountain' => self::MOUNTAIN_MULTIPLIER,
            'west' => self::WEST_COAST_MULTIPLIER,
            'east' => self::EAST_COAST_MULTIPLIER,
        ];

        $destMultiplier = $regionMultipliers[$destination] ?? 1.0;

        return $destMultiplier;
    }

    private function evaluateShippingWaiver(ParcelShipmentQuote $quote, float $currentCost): bool
    {
        $orderValue = $quote->getOrderValue();

        if ($orderValue >= self::FREE_SHIPPING_ORDER_TOTAL) {
            if ($quote->getWeightKg() <= self::FREE_SHIPPING_MAX_WEIGHT) {
                return true;
            }
        }

        return false;
    }

    private function projectTransitDays(ParcelShipmentQuote $quote): int
    {
        $serviceClass = $quote->getServiceClass();
        $destination = $quote->getDestinationRegion();

        $baseline = match ($serviceClass) {
            'ground' => 5,
            'express' => 2,
            'overnight' => 1,
            'white_glove' => 7,
            'economy' => 8,
            default => 5,
        };

        $geographicDelay = match ($destination) {
            'west' => 3,
            'mountain' => 2,
            'midwest' => 1,
            'coastal' => 0,
            'east' => 1,
            default => 0,
        };

        return $baseline + $geographicDelay;
    }
}
