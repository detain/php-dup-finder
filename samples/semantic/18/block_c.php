<?php
declare(strict_types=1);

namespace Inventory\Rules;

final class ProcurementQuantityPlanner
{
    private const LEAD_TIME_BUFFER_WEEKS = 1;
    private const CRITICAL_BUFFER_WEEKS = 0.5;
    private const MAX_INVENTORY_WEEKS = 12;

    private const UNCERTAINTY_LEAD_MULTIPLIER = 1.5;
    private const UNCERTAINTY_DEMAND_MULTIPLIER = 1.3;

    public function planProcurementQuantity(
        Product $product,
        ConsumptionForecast $consumption
    ): ProcurementPlan {
        $availableStock = $product->getAvailableQuantity();
        $reorderTrigger = $this->determineReorderTrigger($product, $consumption);
        $stockMaximum = $this->determineStockMaximum($product);

        $stockDeficit = $reorderTrigger - $availableStock;

        if ($stockDeficit <= 0) {
            return new ProcurementPlan(
                productSku: $product->getSku(),
                quantityToOrder: 0,
                priority: 'none',
                justification: 'inventory_levels_adequate',
            );
        }

        $economicLotSize = $this->computeEconomicLotSize($product, $consumption);
        $proposedOrderQuantity = min($economicLotSize, $stockMaximum - $availableStock);

        $priorityLevel = $this->evaluatePriority($product, $consumption);

        return new ProcurementPlan(
            productSku: $product->getSku(),
            quantityToOrder: (int)ceil($proposedOrderQuantity),
            priority: $priorityLevel,
            justification: $priorityLevel === 'critical' ? 'impending_stockout' : 'reorder_point_reached',
        );
    }

    private function determineReorderTrigger(Product $product, ConsumptionForecast $consumption): float
    {
        $vendorLeadWeeks = $product->getVendorLeadTimeWeeks();
        $expectedWeeklyConsumption = $consumption->getWeeklyConsumptionRate();

        $safetyStock = $this->computeSafetyStock($product, $consumption);

        $triggerPoint = ($vendorLeadWeeks * $expectedWeeklyConsumption) + $safetyStock;

        return $triggerPoint;
    }

    private function computeSafetyStock(Product $product, ConsumptionForecast $consumption): float
    {
        $leadTimeStdDev = $product->getLeadTimeDeviationWeeks();
        $demandStdDev = $consumption->getWeeklyDemandStdDev();
        $leadWeeks = $product->getVendorLeadTimeWeeks();

        $leadTimeUncertainty = $leadTimeStdDev * self::UNCERTAINTY_LEAD_MULTIPLIER * $consumption->getWeeklyConsumptionRate();
        $demandUncertainty = $demandStdDev * self::UNCERTAINTY_DEMAND_MULTIPLIER * sqrt($leadWeeks);

        $safety = sqrt(pow($leadTimeUncertainty, 2) + pow($demandUncertainty, 2));

        return $safety;
    }

    private function determineStockMaximum(Product $product): float
    {
        $typicalWeeklyUsage = $product->getAverageWeeklyUsage();
        $targetWeeksSupply = self::MAX_INVENTORY_WEEKS;

        return $typicalWeeklyUsage * $targetWeeksSupply;
    }

    private function computeEconomicLotSize(Product $product, ConsumptionForecast $consumption): float
    {
        $annualConsumptionUnits = $consumption->getWeeklyConsumptionRate() * 52;
        $procurementCost = $product->getOrderProcessingCost();
        $holdingCostRate = $product->getAnnualHoldingCostRate();
        $unitValue = $product->getUnitValue();

        if ($annualConsumptionUnits <= 0 || $procurementCost <= 0 || $holdingCostRate <= 0) {
            return 0.0;
        }

        $annualHoldingCostPerUnit = $unitValue * ($holdingCostRate / 100);

        $els = sqrt((2 * $annualConsumptionUnits * $procurementCost) / $annualHoldingCostPerUnit);

        return $els;
    }

    private function evaluatePriority(Product $product, ConsumptionForecast $consumption): string
    {
        $availableInventory = $product->getAvailableQuantity();
        $weeklyUsage = $consumption->getWeeklyConsumptionRate();

        $weeksOfCover = $availableInventory / max(1, $weeklyUsage);

        if ($weeksOfCover <= self::CRITICAL_BUFFER_WEEKS) {
            return 'critical';
        }

        if ($weeksOfCover <= self::LEAD_TIME_BUFFER_WEEKS) {
            return 'high';
        }

        return 'standard';
    }
}
