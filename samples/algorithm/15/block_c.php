<?php
declare(strict_types=1);

namespace PricingEngine\Product;

use Psr\Log\LoggerInterface;

final class FurniturePricingEngine
{
    private const BASE_MARKUP_PERCENTAGE = 0.50;
    private const PREMIUM_BRAND_MARKUP = 0.65;
    private const STANDARD_BRAND_MARKUP = 0.45;
    private const ECONOMY_BRAND_MARKUP = 0.35;

    private const DEPRECIATION_RATE_MONTHLY = 0.01;
    private const MAX_DEPRECIATION = 0.40;
    private const ASSEMBLY_FEE_FLAT = 99.00;
    private const DELIVERY_FEE_PER_MILE = 1.50;
    private const WHITE_GLOVE_FEE_PERCENTAGE = 0.15;

    private const SEASONAL_DISCOUNT_SPRING = 0.15;
    private const SEASONAL_DISCOUNT_SUMMER = 0.10;
    private const SEASONAL_DISCOUNT_BLACK_FRIDAY = 0.25;
    private const CLEARANCE_DISCOUNT = 0.60;

    private const BUNDLE_DISCOUNT_PERCENTAGE = 0.18;
    private const VOLUME_DISCOUNT_THRESHOLD = 2;
    private const VOLUME_DISCOUNT_PERCENTAGE = 0.05;

    private const COST_FLOOR_PERCENTAGE = 0.20;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function calculatePrice(ProductPricingRequest $request): PricingResult
    {
        $this->logger->debug('Calculating furniture price', [
            'product_id' => $request->getProductId(),
            'cost' => $request->getCost(),
        ]);

        $baseCost = $request->getCost();
        $brandTier = $request->getBrandTier();
        $isPremiumBrand = $brandTier === 'premium';
        $requiresAssembly = $request->requiresAssembly();
        $deliveryMiles = $request->getDeliveryMiles();
        $isWhiteGloveDelivery = $request->isWhiteGloveDelivery();

        $baseMarkup = $this->getBaseMarkup($isPremiumBrand);
        $basePrice = $baseCost * (1 + $baseMarkup);

        $ageAdjustment = $this->calculateAgeAdjustment($request->getProductAgeMonths());
        $depreciatedPrice = $basePrice * (1 - $ageAdjustment);

        $additionalFees = $this->calculateAdditionalFees($requiresAssembly, $deliveryMiles, $isWhiteGloveDelivery, $depreciatedPrice);
        $finalPrice = $depreciatedPrice + $additionalFees;

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

        $this->logger->info('Furniture price calculated', [
            'base_cost' => $baseCost,
            'markup' => $baseMarkup,
            'depreciation' => $ageAdjustment,
            'fees' => $additionalFees,
            'final_price' => $finalPrice,
        ]);

        return new PricingResult(
            basePrice: $basePrice,
            finalPrice: $finalPrice,
            adjustments: $this->buildAdjustmentBreakdown($baseMarkup, $ageAdjustment, $additionalFees, $seasonalAdjustment),
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

    private function calculateAdditionalFees(bool $requiresAssembly, int $deliveryMiles, bool $whiteGlove, float $basePrice): float
    {
        $fees = 0.0;

        if ($requiresAssembly) {
            $fees += self::ASSEMBLY_FEE_FLAT;
        }

        if ($deliveryMiles > 0) {
            $fees += $deliveryMiles * self::DELIVERY_FEE_PER_MILE;
        }

        if ($whiteGlove) {
            $fees += $basePrice * self::WHITE_GLOVE_FEE_PERCENTAGE;
        }

        return $fees;
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

    private function buildAdjustmentBreakdown(float $markup, float $depreciation, float $fees, float $seasonal): array
    {
        return [
            'markup' => $markup * 100,
            'depreciation' => $depreciation * 100,
            'additional_fees' => $fees,
            'seasonal_discount' => $seasonal * 100,
        ];
    }
}
