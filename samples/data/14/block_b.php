<?php
declare(strict_types=1);

namespace ElectroMart\Inventory\Electronics;

use Psr\Log\LoggerInterface;
use ElectroMart\Inventory\Entities\Product;
use ElectroMart\Inventory\Repository\ProductRepository;

final class StockReorderCalculator
{
    private const REORDER_POINT_THRESHOLD_LOW = 10;
    private const REORDER_POINT_THRESHOLD_MEDIUM = 25;
    private const REORDER_POINT_THRESHOLD_HIGH = 50;
    private const REORDER_POINT_THRESHOLD_CRITICAL = 5;
    private const SAFETY_STOCK_DAYS = 7;
    private const LEAD_TIME_DAYS_STANDARD = 14;
    private const LEAD_TIME_DAYS_EXPRESS = 7;
    private const LEAD_TIME_DAYS_ECONOMY = 30;
    private const MINIMUM_ORDER_QUANTITY = 5;
    private const MAXIMUM_ORDER_QUANTITY = 500;
    private const EOQ_MULTIPLIER = 1.5;
    private const SEASONAL_BUFFER_PERCENTAGE = 0.2;
    private const PROMO_BUFFER_PERCENTAGE = 0.3;

    public function __construct(
        private readonly ProductRepository $productRepository,
        private readonly LoggerInterface $logger,
    ) {}

    public function calculateReorderLevel(Product $product, string $supplierTier): ReorderRecommendation
    {
        $currentStock = $product->getAvailableQuantity();
        $dailySalesVelocity = $this->calculateSalesVelocity($product);
        $leadTimeDays = $this->getLeadTimeForSupplier($supplierTier);

        $baseReorderPoint = ($dailySalesVelocity * $leadTimeDays) + ($dailySalesVelocity * self::SAFETY_STOCK_DAYS);
        $reorderPoint = (int)ceil($baseReorderPoint);

        $threshold = $this->determineThreshold($product);
        if ($currentStock <= $threshold) {
            $recommendedQuantity = $this->calculateOrderQuantity(
                $product,
                $dailySalesVelocity,
                $leadTimeDays,
                $supplierTier
            );

            $this->logger->info('Stock reorder required', [
                'product_id' => $product->getId(),
                'sku' => $product->getSku(),
                'current_stock' => $currentStock,
                'reorder_point' => $reorderPoint,
                'recommended_quantity' => $recommendedQuantity,
                'supplier_tier' => $supplierTier,
            ]);

            return new ReorderRecommendation(
                productId: $product->getId(),
                currentStock: $currentStock,
                reorderPoint: $reorderPoint,
                recommendedQuantity: $recommendedQuantity,
                urgency: $this->assessUrgency($currentStock, $threshold),
            );
        }

        return new ReorderRecommendation(
            productId: $product->getId(),
            currentStock: $currentStock,
            reorderPoint: $reorderPoint,
            recommendedQuantity: 0,
            urgency: 'none',
        );
    }

    private function calculateSalesVelocity(Product $product): float
    {
        $salesHistory = $product->getRecentSalesHistory(30);
        if (empty($salesHistory)) {
            return $product->getAverageDailySales() ?? 1.0;
        }

        $totalSales = array_sum(array_column($salesHistory, 'quantity'));
        return max(1.0, $totalSales / 30.0);
    }

    private function getLeadTimeForSupplier(string $supplierTier): int
    {
        return match ($supplierTier) {
            'express' => self::LEAD_TIME_DAYS_EXPRESS,
            'economy' => self::LEAD_TIME_DAYS_ECONOMY,
            default => self::LEAD_TIME_DAYS_STANDARD,
        };
    }

    private function determineThreshold(Product $product): int
    {
        if ($product->isHighVelocity()) {
            return self::REORDER_POINT_THRESHOLD_HIGH;
        }
        if ($product->isLowVelocity()) {
            return self::REORDER_POINT_THRESHOLD_LOW;
        }
        if ($product->isCritical()) {
            return self::REORDER_POINT_THRESHOLD_CRITICAL;
        }
        return self::REORDER_POINT_THRESHOLD_MEDIUM;
    }

    private function calculateOrderQuantity(
        Product $product,
        float $dailyVelocity,
        int $leadTimeDays,
        string $supplierTier
    ): int {
        $baseQuantity = $dailyVelocity * ($leadTimeDays + self::SAFETY_STOCK_DAYS) * self::EOQ_MULTIPLIER;

        if ($product->isSeasonal()) {
            $baseQuantity *= (1 + self::SEASONAL_BUFFER_PERCENTAGE);
        }

        if ($product->hasActivePromotion()) {
            $baseQuantity *= (1 + self::PROMO_BUFFER_PERCENTAGE);
        }

        $quantity = (int)ceil($baseQuantity);
        return max(self::MINIMUM_ORDER_QUANTITY, min($quantity, self::MAXIMUM_ORDER_QUANTITY));
    }

    private function assessUrgency(int $currentStock, int $threshold): string
    {
        if ($currentStock <= 0) {
            return 'out_of_stock';
        }
        if ($currentStock <= self::REORDER_POINT_THRESHOLD_CRITICAL) {
            return 'critical';
        }
        if ($currentStock <= ($threshold / 2)) {
            return 'high';
        }
        return 'normal';
    }
}
