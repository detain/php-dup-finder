<?php
declare(strict_types=1);

namespace Inventory\Reorder;

use Psr\Log\LoggerInterface;

final class WarehouseReorderAnalyzer
{
    private const LEAD_TIME_DAYS = 7;
    private const LEAD_TIME_VARIABILITY_WEIGHT = 0.20;

    private const DEMAND_FORECAST_WEIGHT = 0.35;

    private const SAFETY_STOCK_WEIGHT = 0.25;

    private const CARRYING_COST_WEIGHT = 0.10;

    private const SUPPLIER_RELIABILITY_WEIGHT = 0.10;

    private const REORDER_URGENCY_LOW = 0.30;
    private const REORDER_URGENCY_MEDIUM = 0.60;
    private const REORDER_URGENCY_HIGH = 0.80;

    private const MAX_URGENCY_SCORE = 1.0;
    private const MIN_URGENCY_SCORE = 0.0;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function calculateReorderUrgency(WarehouseInventoryItem $item): ReorderUrgencyResult
    {
        $this->logger->debug('Calculating warehouse reorder urgency', [
            'sku' => $item->getSku(),
            'current_quantity' => $item->getCurrentQuantity(),
        ]);

        $leadTimeScore = $this->calculateLeadTimeScore($item);
        $demandScore = $this->calculateDemandForecastScore($item);
        $safetyStockScore = $this->calculateSafetyStockScore($item);
        $carryingCostScore = $this->calculateCarryingCostScore($item);
        $supplierScore = $this->calculateSupplierReliabilityScore($item);

        $weightedScore = ($leadTimeScore * self::LEAD_TIME_VARIABILITY_WEIGHT)
            + ($demandScore * self::DEMAND_FORECAST_WEIGHT)
            + ($safetyStockScore * self::SAFETY_STOCK_WEIGHT)
            + ($carryingCostScore * self::CARRYING_COST_WEIGHT)
            + ($supplierScore * self::SUPPLIER_RELIABILITY_WEIGHT);

        $normalizedScore = max(self::MIN_URGENCY_SCORE, min(self::MAX_URGENCY_SCORE, $weightedScore));

        $urgencyLevel = $this->determineUrgencyLevel($normalizedScore);
        $recommendedAction = $this->determineAction($urgencyLevel);
        $suggestedQuantity = $this->calculateReorderQuantity($item);

        $this->logger->info('Warehouse reorder urgency calculated', [
            'sku' => $item->getSku(),
            'urgency_score' => $normalizedScore,
            'urgency_level' => $urgencyLevel,
        ]);

        return new ReorderUrgencyResult(
            urgencyScore: $normalizedScore,
            urgencyLevel: $urgencyLevel,
            recommendedAction: $recommendedAction,
            suggestedQuantity: $suggestedQuantity,
            factors: [
                'lead_time_variability' => $leadTimeScore,
                'demand_forecast' => $demandScore,
                'safety_stock' => $safetyStockScore,
                'carrying_cost' => $carryingCostScore,
                'supplier_reliability' => $supplierScore,
            ],
        );
    }

    private function calculateLeadTimeScore(WarehouseInventoryItem $item): float
    {
        $leadTimeVariability = $item->getLeadTimeVariability();

        if ($leadTimeVariability >= 0.5) {
            return 1.0;
        }

        if ($leadTimeVariability >= 0.3) {
            return 0.7;
        }

        if ($leadTimeVariability >= 0.1) {
            return 0.4;
        }

        return 0.1;
    }

    private function calculateDemandForecastScore(WarehouseInventoryItem $item): float
    {
        $currentStock = $item->getCurrentQuantity();
        $dailyDemand = $item->getAverageDailyDemand();
        $forecastDays = self::LEAD_TIME_DAYS * 2;

        if ($dailyDemand <= 0) {
            return 0.5;
        }

        $projectedDemand = $dailyDemand * $forecastDays;
        $stockRatio = $currentStock / $projectedDemand;

        if ($stockRatio <= 1.0) {
            return 1.0;
        }

        if ($stockRatio <= 2.0) {
            return 0.8;
        }

        if ($stockRatio <= 3.0) {
            return 0.5;
        }

        if ($stockRatio <= 5.0) {
            return 0.2;
        }

        return 0.0;
    }

    private function calculateSafetyStockScore(WarehouseInventoryItem $item): float
    {
        $currentStock = $item->getCurrentQuantity();
        $safetyStock = $item->getSafetyStockLevel();

        if ($safetyStock <= 0) {
            return 0.5;
        }

        $coverageRatio = $currentStock / $safetyStock;

        if ($coverageRatio <= 1.0) {
            return 1.0;
        }

        if ($coverageRatio <= 2.0) {
            return 0.6;
        }

        return max(0.0, 1.0 - ($coverageRatio / 10.0));
    }

    private function calculateCarryingCostScore(WarehouseInventoryItem $item): float
    {
        $unitCost = $item->getUnitCost();
        $currentStock = $item->getCurrentQuantity();
        $annualCarryingCostRate = $item->getAnnualCarryingCostRate();

        $annualCarryingCost = $currentStock * $unitCost * $annualCarryingCostRate;
        $carryingCostThreshold = 10000.0;

        if ($annualCarryingCost >= $carryingCostThreshold) {
            return 1.0;
        }

        if ($annualCarryingCost >= 5000.0) {
            return 0.7;
        }

        if ($annualCarryingCost >= 1000.0) {
            return 0.4;
        }

        return 0.1;
    }

    private function calculateSupplierReliabilityScore(WarehouseInventoryItem $item): float
    {
        $onTimeDeliveryRate = $item->getSupplierOnTimeDeliveryRate();

        if ($onTimeDeliveryRate >= 0.95) {
            return 0.0;
        }

        if ($onTimeDeliveryRate >= 0.90) {
            return 0.2;
        }

        if ($onTimeDeliveryRate >= 0.80) {
            return 0.5;
        }

        if ($onTimeDeliveryRate >= 0.70) {
            return 0.8;
        }

        return 1.0;
    }

    private function determineUrgencyLevel(float $score): string
    {
        if ($score >= self::REORDER_URGENCY_HIGH) {
            return 'high';
        }

        if ($score >= self::REORDER_URGENCY_MEDIUM) {
            return 'medium';
        }

        if ($score >= self::REORDER_URGENCY_LOW) {
            return 'low';
        }

        return 'none';
    }

    private function determineAction(string $urgencyLevel): string
    {
        return match ($urgencyLevel) {
            'high' => 'reorder_immediately',
            'medium' => 'reorder_within_week',
            'low' => 'schedule_reorder',
            default => 'monitor',
        };
    }

    private function calculateReorderQuantity(WarehouseInventoryItem $item): int
    {
        $dailyDemand = $item->getAverageDailyDemand();
        $leadTimeDays = $item->getAverageLeadTimeDays();
        $safetyStock = $item->getSafetyStockLevel();
        $economicOrderQuantity = $item->getEconomicOrderQuantity();

        $reorderPoint = ($dailyDemand * $leadTimeDays) + $safetyStock;
        $currentQuantity = $item->getCurrentQuantity();

        $quantityNeeded = max(0, (int)ceil($reorderPoint - $currentQuantity));

        return max($quantityNeeded, $economicOrderQuantity);
    }
}
