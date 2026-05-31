<?php
declare(strict_types=1);

namespace PricingEngine\Shared;

interface PricingStrategy
{
    public function calculatePrice(ProductPricingRequest $request): PricingResult;
    public function getBaseMarkup(bool $isPremium): float;
    public function getDepreciationRate(): float;
    public function getMaxDepreciation(): float;
    public function getAdditionalFees(ProductPricingRequest $request, float $basePrice): float;
}

abstract class BasePricingEngine implements PricingStrategy
{
    protected LoggerInterface $logger;

    protected const BASE_MARKUP = 0.30;
    protected const PREMIUM_MARKUP = 0.40;
    protected const DEPRECIATION_RATE = 0.02;
    protected const MAX_DEPRECIATION = 0.60;

    protected const SEASONAL_DISCOUNTS = [
        'spring' => 0.10,
        'summer' => 0.05,
        'black_friday' => 0.30,
        'clearance' => 0.50,
    ];

    protected const BUNDLE_DISCOUNT = 0.15;
    protected const VOLUME_THRESHOLD = 5;
    protected const VOLUME_DISCOUNT = 0.08;

    protected const COST_FLOOR = 0.10;

    public function calculatePrice(ProductPricingRequest $request): PricingResult
    {
        $baseCost = $request->getCost();
        $isPremium = $request->getBrandTier() === 'premium';

        $baseMarkup = $this->getBaseMarkup($isPremium);
        $basePrice = $baseCost * (1 + $baseMarkup);

        $ageAdjustment = $this->calculateAgeAdjustment($request->getProductAgeMonths());
        $finalPrice = $basePrice * (1 - $ageAdjustment);

        $additionalFees = $this->getAdditionalFees($request, $baseCost);
        $finalPrice += $additionalFees;

        $seasonalAdjustment = self::SEASONAL_DISCOUNTS[$request->getSeason()] ?? 0.0;
        $finalPrice = $finalPrice * (1 - $seasonalAdjustment);

        if ($request->isBundle()) {
            $finalPrice = $finalPrice * (1 - self::BUNDLE_DISCOUNT);
        }

        if ($request->getQuantity() >= self::VOLUME_THRESHOLD) {
            $finalPrice = $finalPrice * (1 - self::VOLUME_DISCOUNT);
        }

        $costFloor = $baseCost * (1 + self::COST_FLOOR);
        $finalPrice = max($finalPrice, $costFloor);

        return new PricingResult(
            basePrice: $basePrice,
            finalPrice: $finalPrice,
            adjustments: ['markup' => $baseMarkup * 100, 'depreciation' => $ageAdjustment * 100],
            margin: $finalPrice - $baseCost,
            marginPercentage: $baseCost > 0 ? (($finalPrice - $baseCost) / $baseCost) * 100 : 0,
        );
    }

    protected function calculateAgeAdjustment(int $productAgeMonths): float
    {
        if ($productAgeMonths <= 0) {
            return 0.0;
        }
        $depreciation = $productAgeMonths * $this->getDepreciationRate();
        return min($depreciation, $this->getMaxDepreciation());
    }
}

final class ElectronicsPricingEngine extends BasePricingEngine
{
    protected const BASE_MARKUP = 0.30;
    protected const PREMIUM_MARKUP = 0.40;
    protected const DEPRECIATION_RATE = 0.02;
    protected const MAX_DEPRECIATION = 0.60;

    public function getBaseMarkup(bool $isPremium): float
    {
        return $isPremium ? self::PREMIUM_MARKUP : self::BASE_MARKUP;
    }

    public function getDepreciationRate(): float
    {
        return self::DEPRECIATION_RATE;
    }

    public function getMaxDepreciation(): float
    {
        return self::MAX_DEPRECIATION;
    }

    public function getAdditionalFees(ProductPricingRequest $request, float $basePrice): float
    {
        $warrantyMonths = $request->getWarrantyMonths();
        if ($warrantyMonths <= 12) {
            return 0.0;
        }
        $extraMonths = $warrantyMonths - 12;
        return $basePrice * 0.05 * ($extraMonths / 12);
    }
}
