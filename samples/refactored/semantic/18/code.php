<?php
declare(strict_types=1);

namespace Inventory\Shared;

interface ReorderCalculationStrategy
{
    public function calculateReorderQuantity(InventoryContext $context): ReorderQuantity;
    public function getItemIdentifier(): string;
}

abstract class BaseReorderCalculator
{
    protected LoggerInterface $logger;

    protected const DEFAULT_LEAD_TIME_BUFFER_DAYS = 7;
    protected const DEFAULT_CRITICAL_BUFFER_DAYS = 3;
    protected const DEFAULT_MAX_STOCK_MULTIPLIER = 3.0;

    protected const UNCERTAINTY_LEAD_FACTOR = 1.5;
    protected const UNCERTAINTY_DEMAND_FACTOR = 1.3;

    public function calculate(InventoryContext $context): ReorderResult
    {
        $currentStock = $context->getCurrentStock();
        $reorderPoint = $this->calculateReorderPoint($context);
        $maximumStock = $this->calculateMaximumStock($context);

        $stockDeficit = $reorderPoint - $currentStock;

        if ($stockDeficit <= 0) {
            return ReorderResult::noReorder($context->getIdentifier());
        }

        $economicQuantity = $this->calculateEOQ($context);
        $suggestedQuantity = min($economicQuantity, $maximumStock - $currentStock);
        $isCritical = $this->isCritical($context);

        return new ReorderResult(
            identifier: $context->getIdentifier(),
            quantity: (int)ceil($suggestedQuantity),
            isEmergency: $isCritical,
            triggerReason: $isCritical ? 'critical_shortfall' : 'standard_reorder',
        );
    }

    protected function calculateReorderPoint(InventoryContext $context): float
    {
        $leadTime = $context->getLeadTimeDays();
        $dailyDemand = $context->getAverageDailyDemand();

        $safetyStock = $this->calculateSafetyStock($context);

        return ($leadTime * $dailyDemand) + $safetyStock;
    }

    protected function calculateSafetyStock(InventoryContext $context): float
    {
        $leadTimeStdDev = $context->getLeadTimeStdDev();
        $demandStdDev = $context->getDemandStdDev();
        $leadTime = $context->getLeadTimeDays();
        $dailyDemand = $context->getAverageDailyDemand();

        $leadComponent = $leadTimeStdDev * self::UNCERTAINTY_LEAD_FACTOR * $dailyDemand;
        $demandComponent = $demandStdDev * self::UNCERTAINTY_DEMAND_FACTOR * sqrt($leadTime);

        return sqrt(pow($leadComponent, 2) + pow($demandComponent, 2));
    }

    protected function calculateMaximumStock(InventoryContext $context): float
    {
        $dailyDemand = $context->getAverageDailyDemand();
        $targetDays = self::DEFAULT_MAX_STOCK_MULTIPLIER * 30;

        return $dailyDemand * $targetDays;
    }

    protected function calculateEOQ(InventoryContext $context): float
    {
        $annualDemand = $context->getAverageDailyDemand() * 365;
        $orderingCost = $context->getOrderingCost();
        $holdingCostRate = $context->getHoldingCostRate();
        $unitCost = $context->getUnitCost();

        if ($annualDemand <= 0 || $orderingCost <= 0 || $holdingCostRate <= 0) {
            return 0.0;
        }

        $holdingCostPerUnit = $unitCost * ($holdingCostRate / 100);

        return sqrt((2 * $annualDemand * $orderingCost) / $holdingCostPerUnit);
    }

    protected function isCritical(InventoryContext $context): bool
    {
        $currentStock = $context->getCurrentStock();
        $dailyDemand = $context->getAverageDailyDemand();

        return ($currentStock / max(1, $dailyDemand)) <= self::DEFAULT_CRITICAL_BUFFER_DAYS;
    }

    abstract protected function getIdentifier(InventoryContext $context): string;
}

final class StandardReorderCalculator extends BaseReorderCalculator
{
    protected function getIdentifier(InventoryContext $context): string
    {
        return $context->getSku();
    }
}
