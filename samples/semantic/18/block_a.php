<?php
declare(strict_types=1);

namespace Inventory\Rules;

final class StockLevelReplenishment
{
    private const REORDER_POINT_BUFFER_DAYS = 7;
    private const EMERGENCY_REORDER_POINT_DAYS = 3;
    private const MAX_STOCK_MULTIPLIER = 3.0;

    private const LEAD_TIME_VARIABILITY_FACTOR = 1.5;
    private const DEMAND_VARIABILITY_FACTOR = 1.3;

    public function calculateReplenishment(InventoryItem $item, DemandForecast $forecast): ReplenishmentOrder
    {
        $currentStock = $item->getOnHandQuantity();
        $reorderPoint = $this->calculateReorderPoint($item, $forecast);
        $maximumStock = $this->calculateMaximumStock($item);

        $quantityBelowReorder = $reorderPoint - $currentStock;

        if ($quantityBelowReorder <= 0) {
            return new ReplenishmentOrder(
                itemSku: $item->getSku(),
                orderQuantity: 0,
                isEmergency: false,
                reason: 'stock_above_reorder_point',
            );
        }

        $economicOrderQuantity = $this->calculateEOQ($item, $forecast);
        $recommendedQuantity = min($economicOrderQuantity, $maximumStock - $currentStock);

        $isEmergency = $this->isEmergencyReorder($item, $forecast);

        return new ReplenishmentOrder(
            itemSku: $item->getSku(),
            orderQuantity: (int)ceil($recommendedQuantity),
            isEmergency: $isEmergency,
            reason: $isEmergency ? 'emergency_reorder' : 'standard_reorder',
        );
    }

    private function calculateReorderPoint(InventoryItem $item, DemandForecast $forecast): float
    {
        $leadTimeDays = $item->getSupplierLeadTimeDays();
        $averageDailyDemand = $forecast->getAverageDailyDemand();

        $safetyStock = $this->calculateSafetyStock($item, $forecast);

        $baseReorderPoint = ($leadTimeDays * $averageDailyDemand) + safetyStock;

        return $baseReorderPoint;
    }

    private function calculateSafetyStock(InventoryItem $item, DemandForecast $forecast): float
    {
        $leadTimeStdDev = $item->getLeadTimeStandardDeviation();
        $demandStdDev = $forecast->getDemandStandardDeviation();

        $leadTimeComponent = $leadTimeStdDev * self::LEAD_TIME_VARIABILITY_FACTOR * $forecast->getAverageDailyDemand();
        $demandComponent = $demandStdDev * self::DEMAND_VARIABILITY_FACTOR * sqrt($item->getSupplierLeadTimeDays());

        $safetyStock = sqrt(pow($leadTimeComponent, 2) + pow($demandComponent, 2));

        return $safetyStock;
    }

    private function calculateMaximumStock(InventoryItem $item): float
    {
        $averageDailyDemand = $item->getAverageDailySales();
        $maximumDaysOfStock = self::MAX_STOCK_MULTIPLIER * 30;

        return $averageDailyDemand * $maximumDaysOfStock;
    }

    private function calculateEOQ(InventoryItem $item, DemandForecast $forecast): float
    {
        $annualDemand = $forecast->getAverageDailyDemand() * 365;
        $orderingCost = $item->getOrderPlacementCost();
        $holdingCostRate = $item->getAnnualHoldingCostRate();
        $unitCost = $item->getUnitCost();

        if ($annualDemand <= 0 || $orderingCost <= 0 || $holdingCostRate <= 0) {
            return 0.0;
        }

        $holdingCostPerUnit = $unitCost * $holdingCostRate;

        $eoq = sqrt((2 * $annualDemand * $orderingCost) / $holdingCostPerUnit);

        return $eoq;
    }

    private function isEmergencyReorder(InventoryItem $item, DemandForecast $forecast): bool
    {
        $currentStock = $item->getOnHandQuantity();
        $averageDailyDemand = $forecast->getAverageDailyDemand();

        $daysOfStockRemaining = $currentStock / max(1, $averageDailyDemand);

        return $daysOfStockRemaining <= self::EMERGENCY_REORDER_POINT_DAYS;
    }
}
