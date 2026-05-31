<?php
declare(strict_types=1);

namespace Inventory\Rules;

final class InventoryReorderEngine
{
    private const SAFETY_LEAD_TIME_DAYS = 7;
    private const CRITICAL_LEAD_TIME_DAYS = 3;
    private const MAX_INVENTORY_MULTIPLIER = 3.0;

    private const VARIABILITY_LEAD_FACTOR = 1.5;
    private const VARIABILITY_DEMAND_FACTOR = 1.3;

    public function determineReorderQuantity(StockItem $stock, SalesProjection $sales): ReorderRecommendation
    {
        $onHand = $stock->getCurrentOnHand();
        $reorderThreshold = $this->computeReorderThreshold($stock, $sales);
        $stockCeiling = $this->computeStockCeiling($stock);

        $shortfall = $reorderThreshold - $onHand;

        if ($shortfall <= 0) {
            return new ReorderRecommendation(
                sku: $stock->getSku(),
                reorderQuantity: 0,
                urgencyLevel: 'none',
                reason: 'inventory_sufficient',
            );
        }

        $optimalOrderSize = $this->computeOptimalOrderSize($stock, $sales);
        $suggestedOrderQuantity = min($optimalOrderSize, $stockCeiling - $onHand);

        $urgency = $this->assessUrgency($stock, $sales);

        return new ReorderRecommendation(
            sku: $stock->getSku(),
            reorderQuantity: (int)ceil($suggestedOrderQuantity),
            urgencyLevel: $urgency,
            reason: $urgency === 'critical' ? 'critical_shortfall' : 'reorder_triggered',
        );
    }

    private function computeReorderThreshold(StockItem $stock, SalesProjection $sales): float
    {
        $supplierLeadDays = $stock->getVendorLeadTimeDays();
        $projectedDailySales = $sales->getExpectedDailyUnits();

        $bufferStock = $this->computeBufferStock($stock, $sales);

        $threshold = ($supplierLeadDays * $projectedDailySales) + bufferStock;

        return $threshold;
    }

    private function computeBufferStock(StockItem $stock, SalesProjection $sales): float
    {
        $leadTimeVariation = $stock->getLeadTimeStandardDeviation();
        $demandVariation = $sales->getDemandStandardDeviation();
        $leadDays = $stock->getVendorLeadTimeDays();

        $leadTimeBuffer = $leadTimeVariation * self::VARIABILITY_LEAD_FACTOR * $sales->getExpectedDailyUnits();
        $demandBuffer = $demandVariation * self::VARIABILITY_DEMAND_FACTOR * sqrt($leadDays);

        $buffer = sqrt(pow($leadTimeBuffer, 2) + pow($demandBuffer, 2));

        return $buffer;
    }

    private function computeStockCeiling(StockItem $stock): float
    {
        $typicalDailySales = $stock->getAverageDailyUnitsSold();
        $targetStockDays = self::MAX_INVENTORY_MULTIPLIER * 30;

        return $typicalDailySales * $targetStockDays;
    }

    private function computeOptimalOrderSize(StockItem $stock, SalesProjection $sales): float
    {
        $yearlyDemand = $sales->getExpectedDailyUnits() * 365;
        $orderCost = $stock->getCostToPlaceOrder();
        $carryingCostPercent = $stock->getAnnualCarryingCostPercent();
        $unitValue = $stock->getUnitCost();

        if ($yearlyDemand <= 0 || $orderCost <= 0 || $carryingCostPercent <= 0) {
            return 0.0;
        }

        $annualCarryingCostPerUnit = $unitValue * ($carryingCostPercent / 100);

        $eoo = sqrt((2 * $yearlyDemand * $orderCost) / $annualCarryingCostPerUnit);

        return $eoo;
    }

    private function assessUrgency(StockItem $stock, SalesProjection $sales): string
    {
        $currentInventory = $stock->getCurrentOnHand();
        $dailyConsumption = $sales->getExpectedDailyUnits();

        $daysUntilStockout = $currentInventory / max(1, $dailyConsumption);

        if ($daysUntilStockout <= self::CRITICAL_LEAD_TIME_DAYS) {
            return 'critical';
        }

        if ($daysUntilStockout <= self::SAFETY_LEAD_TIME_DAYS) {
            return 'high';
        }

        return 'standard';
    }
}
