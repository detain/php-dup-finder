<?php
declare(strict_types=1);

namespace Shipping\Rules;

final class DeliveryRateEngine
{
    private const FREE_DELIVERY_MINIMUM = 75;
    private const FREE_DELIVERY_WEIGHT_KG = 5;
    private const STANDARD_HANDLING = 5.99;
    private const PRIORITY_HANDLING = 15.00;
    private const FREIGHT_HANDLING = 75.00;
    private const OVERSIZED_PACKAGE = 25;
    private const DELICATE_ITEM_FEE = 10;
    private const RESTRICTED_MATERIAL_FEE = 30;

    private const REGION_A_FACTOR = 1.0;
    private const REGION_B_FACTOR = 1.5;
    private const REGION_C_FACTOR = 2.0;
    private const REGION_D_FACTOR = 2.5;

    public function computeDeliveryRate(DeliveryQuoteRequest $request): DeliveryQuoteResult
    {
        $handlingFee = $this->computeHandlingFee($request->getServiceType());
        $weightFee = $this->computeWeightFee($request->getPackageWeight());
        $sizeFee = $this->computeSizeFee($request->getDimensions());
        $specialHandlingFee = $this->computeSpecialHandlingFee($request);
        $regionalFactor = $this->getRegionalFactor($request->getDeliveryRegion());

        $subtotal = $handlingFee + $weightFee + $sizeFee + $specialHandlingFee;
        $finalRate = $subtotal * $regionalFactor;

        $freeDeliveryStatus = $this->determineFreeDelivery($request, $finalRate);

        return new DeliveryQuoteResult(
            handlingFee: $handlingFee,
            additionalFees: [
                'weight' => $weightFee,
                'size' => $sizeFee,
                'special_handling' => $specialHandlingFee,
            ],
            regionalFactor: $regionalFactor,
            finalRate: $finalRate,
            freeDeliveryGranted: $freeDeliveryStatus,
            estimatedTransitDays: $this->calculateTransitDays($request),
        );
    }

    private function computeHandlingFee(string $serviceType): float
    {
        return match ($serviceType) {
            'standard' => self::STANDARD_HANDLING,
            'priority' => self::PRIORITY_HANDLING,
            'freight' => self::FREIGHT_HANDLING,
            'economy' => 3.99,
            default => self::STANDARD_HANDLING,
        };
    }

    private function computeWeightFee(float $weightKg): float
    {
        if ($weightKg <= 1) {
            return 0.0;
        }

        if ($weightKg <= 3) {
            return 1.50;
        }

        if ($weightKg <= 7) {
            return 3.50;
        }

        if ($weightKg <= 15) {
            return 7.00;
        }

        if ($weightKg <= 30) {
            return 12.00;
        }

        if ($weightKg <= 70) {
            return 20.00;
        }

        return 30.00 + (($weightKg - 70) * 0.40);
    }

    private function computeSizeFee(array $dimensions): float
    {
        $volumetricKg = $this->calculateVolumetricWeight($dimensions);

        if ($volumetricKg > 50) {
            return self::OVERSIZED_PACKAGE;
        }

        if ($volumetricKg > 20) {
            return 15.00;
        }

        if ($volumetricKg > 10) {
            return 7.50;
        }

        return 0.0;
    }

    private function computeSpecialHandlingFee(DeliveryQuoteRequest $request): float
    {
        $fee = 0.0;

        if ($request->isHighValue()) {
            $fee += 5.00;
        }

        if ($request->requiresTemperatureControl()) {
            $fee += 15.00;
        }

        if ($request->isBiological()) {
            $fee += self::RESTRICTED_MATERIAL_FEE;
        }

        if ($request->isPerishable()) {
            $fee += 12.00;
        }

        return $fee;
    }

    private function getRegionalFactor(string $region): float
    {
        return match ($region) {
            'region_a' => self::REGION_A_FACTOR,
            'region_b' => self::REGION_B_FACTOR,
            'region_c' => self::REGION_C_FACTOR,
            'region_d' => self::REGION_D_FACTOR,
            default => self::REGION_A_FACTOR,
        };
    }

    private function calculateVolumetricWeight(array $dimensions): float
    {
        $length = $dimensions['length'] ?? 0;
        $width = $dimensions['width'] ?? 0;
        $height = $dimensions['height'] ?? 0;

        return ($length * $width * $height) / 5000;
    }

    private function determineFreeDelivery(DeliveryQuoteRequest $request, float $calculatedRate): bool
    {
        $orderTotal = $request->getOrderTotal();

        if ($orderTotal >= self::FREE_DELIVERY_MINIMUM) {
            if ($request->getPackageWeight() <= self::FREE_DELIVERY_WEIGHT_KG) {
                return true;
            }
        }

        return false;
    }

    private function calculateTransitDays(DeliveryQuoteRequest $request): int
    {
        $serviceType = $request->getServiceType();
        $region = $request->getDeliveryRegion();

        $baseTransit = match ($serviceType) {
            'standard' => 5,
            'priority' => 2,
            'economy' => 7,
            'freight' => 10,
            default => 5,
        };

        $regionDelay = match ($region) {
            'region_a' => 0,
            'region_b' => 1,
            'region_c' => 2,
            'region_d' => 3,
            default => 0,
        };

        return $baseTransit + $regionDelay;
    }
}
