<?php
declare(strict_types=1);

namespace Inventory\Shared;

interface ReorderFactorStrategy
{
    public function calculateScore(mixed $item): float;
    public function getWeight(): float;
    public function getFactorName(): string;
}

abstract class BaseReorderAnalyzer
{
    protected LoggerInterface $logger;

    protected const URGENCY_THRESHOLDS = [
        'low' => 0.30,
        'medium' => 0.60,
        'high' => 0.80,
    ];

    protected const MAX_SCORE = 1.0;
    protected const MIN_SCORE = 0.0;

    public function calculateReorderUrgency(mixed $item): ReorderUrgencyResult
    {
        $factors = [];
        $totalWeight = 0.0;

        foreach ($this->getFactorStrategies() as $strategy) {
            $score = $strategy->calculateScore($item);
            $weight = $strategy->getWeight();

            $factors[$strategy->getFactorName()] = $score;
            $totalWeight += $weight;

            $this->logger->debug('Reorder factor calculated', [
                'factor' => $strategy->getFactorName(),
                'score' => $score,
                'weight' => $weight,
            ]);
        }

        $weightedScore = 0.0;
        foreach ($factors as $factorName => $score) {
            $weight = $this->findStrategyWeight($factorName);
            if ($totalWeight > 0) {
                $normalizedWeight = $weight / $totalWeight;
                $weightedScore += $score * $normalizedWeight;
            }
        }

        $normalizedScore = max(self::MIN_SCORE, min(self::MAX_SCORE, $weightedScore));
        $urgencyLevel = $this->determineUrgencyLevel($normalizedScore);

        return new ReorderUrgencyResult(
            urgencyScore: $normalizedScore,
            urgencyLevel: $urgencyLevel,
            recommendedAction: $this->determineAction($urgencyLevel),
            suggestedQuantity: $this->calculateReorderQuantity($item),
            factors: $factors,
        );
    }

    abstract protected function getFactorStrategies(): array;
    abstract protected function findStrategyWeight(string $factorName): float;
    abstract protected function calculateReorderQuantity(mixed $item): int;

    protected function determineUrgencyLevel(float $score): string
    {
        if ($score >= self::URGENCY_THRESHOLDS['high']) {
            return 'high';
        }
        if ($score >= self::URGENCY_THRESHOLDS['medium']) {
            return 'medium';
        }
        if ($score >= self::URGENCY_THRESHOLDS['low']) {
            return 'low';
        }
        return 'none';
    }

    protected function determineAction(string $urgencyLevel): string
    {
        return match ($urgencyLevel) {
            'high' => 'reorder_immediately',
            'medium' => 'reorder_within_week',
            'low' => 'schedule_reorder',
            default => 'monitor',
        };
    }
}

final class WarehouseReorderAnalyzer extends BaseReorderAnalyzer
{
    protected function getFactorStrategies(): array
    {
        return [
            new LeadTimeVariabilityStrategy(0.20),
            new DemandForecastStrategy(0.35),
            new SafetyStockStrategy(0.25),
            new CarryingCostStrategy(0.10),
            new SupplierReliabilityStrategy(0.10),
        ];
    }

    protected function findStrategyWeight(string $factorName): float
    {
        return match ($factorName) {
            'lead_time_variability' => 0.20,
            'demand_forecast' => 0.35,
            'safety_stock' => 0.25,
            'carrying_cost' => 0.10,
            'supplier_reliability' => 0.10,
            default => 0.0,
        };
    }

    protected function calculateReorderQuantity(mixed $item): int
    {
        $dailyDemand = $item->getAverageDailyDemand();
        $leadTimeDays = $item->getAverageLeadTimeDays();
        $safetyStock = $item->getSafetyStockLevel();

        $reorderPoint = ($dailyDemand * $leadTimeDays) + $safetyStock;
        return max(0, (int)ceil($reorderPoint - $item->getCurrentQuantity()));
    }
}

final class LeadTimeVariabilityStrategy implements ReorderFactorStrategy
{
    private float $weight;

    public function __construct(float $weight)
    {
        $this->weight = $weight;
    }

    public function getWeight(): float
    {
        return $this->weight;
    }

    public function getFactorName(): string
    {
        return 'lead_time_variability';
    }

    public function calculateScore(mixed $item): float
    {
        $variability = $item->getLeadTimeVariability();
        if ($variability >= 0.5) {
            return 1.0;
        }
        if ($variability >= 0.3) {
            return 0.7;
        }
        return min(1.0, $variability / 0.3);
    }
}
