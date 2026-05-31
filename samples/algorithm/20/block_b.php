<?php
declare(strict_types=1);

namespace Inventory\Reorder;

use Psr\Log\LoggerInterface;

final class RetailReorderAnalyzer
{
    private const SEASONALITY_WINDOW_DAYS = 14;
    private const SEASONALITY_WEIGHT = 0.30;

    private const SALES_VELOCITY_WEIGHT = 0.35;

    private const STOCKOUT_HISTORY_WEIGHT = 0.20;

    private const PROFIT_MARGIN_WEIGHT = 0.10;

    private const SPACE_ALLOCATION_WEIGHT = 0.05;

    private const REORDER_URGENCY_LOW = 0.30;
    private const REORDER_URGENCY_MEDIUM = 0.60;
    private const REORDER_URGENCY_HIGH = 0.80;

    private const MAX_URGENCY_SCORE = 1.0;
    private const MIN_URGENCY_SCORE = 0.0;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function calculateReorderUrgency(RetailProduct $product): ReorderUrgencyResult
    {
        $this->logger->debug('Calculating retail reorder urgency', [
            'upc' => $product->getUpc(),
            'store_id' => $product->getStoreId(),
        ]);

        $seasonalityScore = $this->calculateSeasonalityScore($product);
        $velocityScore = $this->calculateSalesVelocityScore($product);
        $stockoutScore = $this->calculateStockoutHistoryScore($product);
        $marginScore = $this->calculateProfitMarginScore($product);
        $spaceScore = $this->calculateSpaceAllocationScore($product);

        $weightedScore = ($seasonalityScore * self::SEASONALITY_WEIGHT)
            + ($velocityScore * self::SALES_VELOCITY_WEIGHT)
            + ($stockoutScore * self::STOCKOUT_HISTORY_WEIGHT)
            + ($marginScore * self::PROFIT_MARGIN_WEIGHT)
            + ($spaceScore * self::SPACE_ALLOCATION_WEIGHT);

        $normalizedScore = max(self::MIN_URGENCY_SCORE, min(self::MAX_URGENCY_SCORE, $weightedScore));

        $urgencyLevel = $this->determineUrgencyLevel($normalizedScore);
        $recommendedAction = $this->determineAction($urgencyLevel);
        $suggestedQuantity = $this->calculateReorderQuantity($product);

        $this->logger->info('Retail reorder urgency calculated', [
            'upc' => $product->getUpc(),
            'urgency_score' => $normalizedScore,
            'urgency_level' => $urgencyLevel,
        ]);

        return new ReorderUrgencyResult(
            urgencyScore: $normalizedScore,
            urgencyLevel: $urgencyLevel,
            recommendedAction: $recommendedAction,
            suggestedQuantity: $suggestedQuantity,
            factors: [
                'seasonality' => $seasonalityScore,
                'sales_velocity' => $velocityScore,
                'stockout_history' => $stockoutScore,
                'profit_margin' => $marginScore,
                'space_allocation' => $spaceScore,
            ],
        );
    }

    private function calculateSeasonalityScore(RetailProduct $product): float
    {
        $currentDemand = $product->getDemandInWindow(self::SEASONALITY_WINDOW_DAYS);
        $baselineDemand = $product->getBaselineDailyDemand() * self::SEASONALITY_WINDOW_DAYS;

        if ($baselineDemand <= 0) {
            return 0.5;
        }

        $seasonalRatio = $currentDemand / $baselineDemand;

        if ($seasonalRatio >= 3.0) {
            return 1.0;
        }

        if ($seasonalRatio >= 2.0) {
            return 0.8;
        }

        if ($seasonalRatio >= 1.5) {
            return 0.5;
        }

        if ($seasonalRatio >= 1.0) {
            return 0.2;
        }

        return 0.0;
    }

    private function calculateSalesVelocityScore(RetailProduct $product): float
    {
        $unitsPerWeek = $product->getWeeklySalesVelocity();
        $currentStock = $product->getCurrentStock();

        if ($unitsPerWeek <= 0) {
            return 0.0;
        }

        $weeksOfStock = $currentStock / $unitsPerWeek;

        if ($weeksOfStock <= 1.0) {
            return 1.0;
        }

        if ($weeksOfStock <= 2.0) {
            return 0.8;
        }

        if ($weeksOfStock <= 3.0) {
            return 0.5;
        }

        if ($weeksOfStock <= 4.0) {
            return 0.2;
        }

        return 0.0;
    }

    private function calculateStockoutHistoryScore(RetailProduct $product): float
    {
        $stockoutIncidents = $product->getStockoutIncidentsLast90Days();

        if ($stockoutIncidents >= 5) {
            return 1.0;
        }

        if ($stockoutIncidents >= 3) {
            return 0.7;
        }

        if ($stockoutIncidents >= 1) {
            return 0.4;
        }

        return 0.0;
    }

    private function calculateProfitMarginScore(RetailProduct $product): float
    {
        $marginPercent = $product->getGrossMarginPercent();

        if ($marginPercent >= 50) {
            return 0.0;
        }

        if ($marginPercent >= 30) {
            return 0.3;
        }

        if ($marginPercent >= 20) {
            return 0.6;
        }

        return 1.0;
    }

    private function calculateSpaceAllocationScore(RetailProduct $product): float
    {
        $planogramPriority = $product->getPlanogramPriority();

        if ($planogramPriority === 'high') {
            return 1.0;
        }

        if ($planogramPriority === 'medium') {
            return 0.5;
        }

        return 0.1;
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
            'high' => 'expedite_order',
            'medium' => 'standard_reorder',
            'low' => 'monitor',
            default => 'hold',
        };
    }

    private function calculateReorderQuantity(RetailProduct $product): int
    {
        $weeklyVelocity = $product->getWeeklySalesVelocity();
        $targetWeeksOfStock = $product->getTargetWeeksOfStock();
        $currentStock = $product->getCurrentStock();
        $minimumOrderQuantity = $product->getMinimumOrderQuantity();

        $targetStock = $weeklyVelocity * $targetWeeksOfStock;
        $quantityNeeded = max(0, (int)ceil($targetStock - $currentStock));

        return max($quantityNeeded, $minimumOrderQuantity);
    }
}
