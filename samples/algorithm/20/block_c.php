<?php
declare(strict_types=1);

namespace Inventory\Reorder;

use Psr\Log\LoggerInterface;

final class ManufacturingReorderAnalyzer
{
    private const PRODUCTION_CYCLE_TIME = 14;
    private const CYCLE_TIME_WEIGHT = 0.25;

    private const BOM_AVAILABILITY_WEIGHT = 0.35;

    private const QUALITY_YIELD_WEIGHT = 0.20;

    private const PRODUCTION_COST_WEIGHT = 0.15;

    private const CUSTOMER_PRIORITY_WEIGHT = 0.05;

    private const REORDER_URGENCY_LOW = 0.30;
    private const REORDER_URGENCY_MEDIUM = 0.60;
    private const REORDER_URGENCY_HIGH = 0.80;

    private const MAX_URGENCY_SCORE = 1.0;
    private const MIN_URGENCY_SCORE = 0.0;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function calculateReorderUrgency(ManufacturingComponent $component): ReorderUrgencyResult
    {
        $this->logger->debug('Calculating manufacturing reorder urgency', [
            'component_id' => $component->getComponentId(),
            'product_line' => $component->getProductLine(),
        ]);

        $cycleTimeScore = $this->calculateCycleTimeScore($component);
        $bomScore = $this->calculateBomAvailabilityScore($component);
        $yieldScore = $this->calculateQualityYieldScore($component);
        $costScore = $this->calculateProductionCostScore($component);
        $priorityScore = $this->calculateCustomerPriorityScore($component);

        $weightedScore = ($cycleTimeScore * self::CYCLE_TIME_WEIGHT)
            + ($bomScore * self::BOM_AVAILABILITY_WEIGHT)
            + ($yieldScore * self::QUALITY_YIELD_WEIGHT)
            + ($costScore * self::PRODUCTION_COST_WEIGHT)
            + ($priorityScore * self::CUSTOMER_PRIORITY_WEIGHT);

        $normalizedScore = max(self::MIN_URGENCY_SCORE, min(self::MAX_URGENCY_SCORE, $weightedScore));

        $urgencyLevel = $this->determineUrgencyLevel($normalizedScore);
        $recommendedAction = $this->determineAction($urgencyLevel);
        $suggestedQuantity = $this->calculateReorderQuantity($component);

        $this->logger->info('Manufacturing reorder urgency calculated', [
            'component_id' => $component->getComponentId(),
            'urgency_score' => $normalizedScore,
            'urgency_level' => $urgencyLevel,
        ]);

        return new ReorderUrgencyResult(
            urgencyScore: $normalizedScore,
            urgencyLevel: $urgencyLevel,
            recommendedAction: $recommendedAction,
            suggestedQuantity: $suggestedQuantity,
            factors: [
                'cycle_time' => $cycleTimeScore,
                'bom_availability' => $bomScore,
                'quality_yield' => $yieldScore,
                'production_cost' => $costScore,
                'customer_priority' => $priorityScore,
            ],
        );
    }

    private function calculateCycleTimeScore(ManufacturingComponent $component): float
    {
        $componentLeadTime = $component->getManufacturingLeadTimeDays();
        $standardCycleTime = self::PRODUCTION_CYCLE_TIME;

        if ($componentLeadTime <= 0) {
            return 0.5;
        }

        $ratio = $componentLeadTime / $standardCycleTime;

        if ($ratio >= 2.0) {
            return 1.0;
        }

        if ($ratio >= 1.5) {
            return 0.7;
        }

        if ($ratio >= 1.0) {
            return 0.4;
        }

        return 0.1;
    }

    private function calculateBomAvailabilityScore(ManufacturingComponent $component): float
    {
        $availableQuantity = $component->getWarehouseQuantity();
        $requiredForProduction = $component->getQuantityRequiredForBillOfMaterials();

        if ($requiredForProduction <= 0) {
            return 0.5;
        }

        $coverageRatio = $availableQuantity / $requiredForProduction;

        if ($coverageRatio <= 1.0) {
            return 1.0;
        }

        if ($coverageRatio <= 2.0) {
            return 0.7;
        }

        if ($coverageRatio <= 4.0) {
            return 0.4;
        }

        return 0.0;
    }

    private function calculateQualityYieldScore(ManufacturingComponent $component): float
    {
        $historicalYield = $component->getHistoricalYieldPercent();

        if ($historicalYield >= 95) {
            return 0.0;
        }

        if ($historicalYield >= 90) {
            return 0.2;
        }

        if ($historicalYield >= 85) {
            return 0.5;
        }

        if ($historicalYield >= 80) {
            return 0.8;
        }

        return 1.0;
    }

    private function calculateProductionCostScore(ManufacturingComponent $component): float
    {
        $unitCost = $component->getUnitCost();
        $standardCost = $component->getStandardCost();

        if ($standardCost <= 0) {
            return 0.5;
        }

        $costVariance = abs($unitCost - $standardCost) / $standardCost;

        if ($costVariance >= 0.20) {
            return 1.0;
        }

        if ($costVariance >= 0.10) {
            return 0.7;
        }

        if ($costVariance >= 0.05) {
            return 0.4;
        }

        return 0.1;
    }

    private function calculateCustomerPriorityScore(ManufacturingComponent $component): float
    {
        $priorityCustomerCount = $component->getPriorityCustomerOrdersPending();

        if ($priorityCustomerCount >= 10) {
            return 1.0;
        }

        if ($priorityCustomerCount >= 5) {
            return 0.7;
        }

        if ($priorityCustomerCount >= 1) {
            return 0.4;
        }

        return 0.0;
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
            'high' => 'rush_procurement',
            'medium' => 'schedule_production',
            'low' => 'include_in_forecast',
            default => 'standard_lead_time',
        };
    }

    private function calculateReorderQuantity(ManufacturingComponent $component): int
    {
        $averageUsage = $component->getAverageWeeklyUsage();
        $leadTimeWeeks = $component->getManufacturingLeadTimeDays() / 7;
        $safetyWeeks = 2;
        $economicLotSize = $component->getEconomicLotSize();

        $reorderPoint = (int)ceil(($averageUsage * $leadTimeWeeks) + ($averageUsage * $safetyWeeks));

        return max($reorderPoint, $economicLotSize);
    }
}
