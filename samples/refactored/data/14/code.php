<?php
declare(strict_types=1);

namespace Inventory\Shared;

final class ReorderConstants
{
    public const THRESHOLD_LOW = 10;
    public const THRESHOLD_MEDIUM = 25;
    public const THRESHOLD_HIGH = 50;
    public const THRESHOLD_CRITICAL = 5;

    public const SAFETY_STOCK_DAYS = 7;
    public const LEAD_TIME_STANDARD = 14;
    public const LEAD_TIME_EXPRESS = 7;
    public const LEAD_TIME_ECONOMY = 30;

    public const MIN_ORDER_QTY = 5;
    public const MAX_ORDER_QTY = 500;
    public const EOQ_MULTIPLIER = 1.5;

    public const SEASONAL_BUFFER = 0.2;
    public const PROMO_BUFFER = 0.3;

    public static function getLeadTimeForTier(string $tier): int
    {
        return match ($tier) {
            'express' => self::LEAD_TIME_EXPRESS,
            'economy' => self::LEAD_TIME_ECONOMY,
            default => self::LEAD_TIME_STANDARD,
        };
    }

    public static function getThresholdForItem(ItemInterface $item): int
    {
        if ($item->isCritical()) {
            return self::THRESHOLD_CRITICAL;
        }
        if ($item->isHighVelocity()) {
            return self::THRESHOLD_HIGH;
        }
        if ($item->isLowVelocity()) {
            return self::THRESHOLD_LOW;
        }
        return self::THRESHOLD_MEDIUM;
    }
}

interface ReorderCalculatorInterface
{
    public function calculateReorderLevel(ItemInterface $item, string $supplierTier): ReorderRecommendation;
}

trait ReorderCalculationLogic
{
    private ReorderConstants $constants;

    protected function computeReorderPoint(ItemInterface $item, string $supplierTier): int
    {
        $velocity = $this->calculateVelocity($item);
        $leadTime = $this->constants::getLeadTimeForTier($supplierTier);
        $safetyStock = $velocity * $this->constants::SAFETY_STOCK_DAYS;

        return (int)ceil(($velocity * $leadTime) + $safetyStock);
    }

    protected function computeOrderQuantity(ItemInterface $item, float $velocity, int $leadTime): int
    {
        $baseQty = $velocity * ($leadTime + $this->constants::SAFETY_STOCK_DAYS)
            * $this->constants::EOQ_MULTIPLIER;

        if ($item->isSeasonal()) {
            $baseQty *= (1 + $this->constants::SEASONAL_BUFFER);
        }

        if ($item->hasActivePromotion()) {
            $baseQty *= (1 + $this->constants::PROMO_BUFFER);
        }

        return max(
            $this->constants::MIN_ORDER_QTY,
            min((int)ceil($baseQty), $this->constants::MAX_ORDER_QTY)
        );
    }

    protected function determineUrgency(int $currentStock, int $threshold): string
    {
        if ($currentStock <= 0) {
            return 'out_of_stock';
        }
        if ($currentStock <= ReorderConstants::THRESHOLD_CRITICAL) {
            return 'critical';
        }
        if ($currentStock <= ($threshold / 2)) {
            return 'high';
        }
        return 'normal';
    }
}
