<?php
declare(strict_types=1);

namespace PricingEngine\Product;

use Psr\Log\LoggerInterface;

final class ElectronicsPricingEngine
{
    private const BASE_MARKUP_PERCENTAGE = 0.30;
    private const PREMIUM_BRAND_MARKUP = 0.40;
    private const STANDARD_BRAND_MARKUP = 0.25;
    private const ECONOMY_BRAND_MARKUP = 0.15;

    private const DEPRECIATION_RATE_MONTHLY = 0.02;
    private const MAX_DEPRECIATION = 0.60;
    private const WARRANTY_PREMIUM_PERCENTAGE = 0.05;

    private const SEASONAL_DISCOUNT_SPRING = 0.10;
    private const SEASONAL_DISCOUNT_SUMMER = 0.05;
    private const SEASONAL_DISCOUNT_BLACK_FRIDAY = 0.30;
    private const CLEARANCE_DISCOUNT = 0.50;

    private const BUNDLE_DISCOUNT_PERCENTAGE = 0.15;
    private const VOLUME_DISCOUNT_THRESHOLD = 5;
    private const VOLUME_DISCOUNT_PERCENTAGE = 0.08;

    private const COST_FLOOR_PERCENTAGE = 0.10;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function calculatePrice(ProductPricingRequest $request): PricingResult
    {
        $this->logger->debug('Calculating electronics price', [
            'product_id' => $request->getProductId(),
            'cost' => $request->getCost(),
        ]);

        $baseCost = $request->getCost();
        $brandTier = $request->getBrandTier();
        $isPremiumBrand = $brandTier === 'premium';
        $warrantyMonths = $request->getWarrantyMonths();

        $baseMarkup = $this->getBaseMarkup($isPremiumBrand);
        $basePrice = $baseCost * (1 + $baseMarkup);

        $ageAdjustment = $this->calculateAgeAdjustment($request->getProductAgeMonths());
        $depreciatedPrice = $basePrice * (1 - $ageAdjustment);

        $warrantyPremium = $this->calculateWarrantyPremium($warrantyMonths, $baseCost);
        $finalPrice = $depreciatedPrice + $warrantyPremium;

        $seasonalAdjustment = $this->calculateSeasonalAdjustment($request->getSeason());
        $finalPrice = $finalPrice * (1 - $seasonalAdjustment);

        if ($request->isBundle()) {
            $finalPrice = $finalPrice * (1 - self::BUNDLE_DISCOUNT_PERCENTAGE);
        }

        if ($request->getQuantity() >= self::VOLUME_DISCOUNT_THRESHOLD) {
            $finalPrice = $finalPrice * (1 - self::VOLUME_DISCOUNT_PERCENTAGE);
        }

        $costFloor = $baseCost * (1 + self::COST_FLOOR_PERCENTAGE);
        if ($finalPrice < $costFloor) {
            $finalPrice = $costFloor;
        }

        $this->logger->info('Electronics price calculated', [
            'base_cost' => $baseCost,
            'markup' => $baseMarkup,
            'depreciation' => $ageAdjustment,
            'warranty' => $warrantyPremium,
            'final_price' => $finalPrice,
        ]);

        return new PricingResult(
            basePrice: $basePrice,
            finalPrice: $finalPrice,
            adjustments: $this->buildAdjustmentBreakdown($baseMarkup, $ageAdjustment, $warrantyPremium, $seasonalAdjustment),
            margin: $finalPrice - $baseCost,
            marginPercentage: $baseCost > 0 ? (($finalPrice - $baseCost) / $baseCost) * 100 : 0,
        );
    }

    private function getBaseMarkup(bool $isPremiumBrand): float
    {
        if ($isPremiumBrand) {
            return self::PREMIUM_BRAND_MARKUP;
        }
        return self::BASE_MARKUP_PERCENTAGE;
    }

    private function calculateAgeAdjustment(int $productAgeMonths): float
    {
        if ($productAgeMonths <= 0) {
            return 0.0;
        }

        $depreciation = $productAgeMonths * self::DEPRECIATION_RATE_MONTHLY;
        return min($depreciation, self::MAX_DEPRECIATION);
    }

    private function calculateWarrantyPremium(int $warrantyMonths, float $baseCost): float
    {
        if ($warrantyMonths <= 12) {
            return 0.0;
        }

        $extraMonths = $warrantyMonths - 12;
        return $baseCost * self::WARRANTY_PREMIUM_PERCENTAGE * ($extraMonths / 12);
    }

    private function calculateSeasonalAdjustment(string $season): float
    {
        return match ($season) {
            'spring' => self::SEASONAL_DISCOUNT_SPRING,
            'summer' => self::SEASONAL_DISCOUNT_SUMMER,
            'black_friday' => self::SEASONAL_DISCOUNT_BLACK_FRIDAY,
            'clearance' => self::CLEARANCE_DISCOUNT,
            default => 0.0,
        };
    }

    private function buildAdjustmentBreakdown(float $markup, float $depreciation, float $warranty, float $seasonal): array
    {
        return [
            'markup' => $markup * 100,
            'depreciation' => $depreciation * 100,
            'warranty_premium' => $warranty,
            'seasonal_discount' => $seasonal * 100,
        ];
    }
}
