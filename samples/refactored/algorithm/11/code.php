<?php
declare(strict_types=1);

namespace PricingEngine\Shared;

final class SeasonalConstants
{
    public const SPRING_START = '03-01';
    public const SPRING_END = '05-31';
    public const SUMMER_START = '06-01';
    public const SUMMER_END = '08-31';
    public const FALL_START = '09-01';
    public const FALL_END = '11-30';
    public const WINTER_START = '12-01';
    public const WINTER_END = '02-28';
}

interface SeasonalCalculationStrategy
{
    public function calculate(array $items, \DateTimeInterface $date, array $context): CalculationResult;
    public function getSeasonalValue(\DateTimeInterface $date): float;
    public function getSeasonalName(): string;
}

abstract class BaseSeasonalCalculator
{
    protected LoggerInterface $logger;

    protected function calculateSubtotal(array $orderItems): float
    {
        $subtotal = 0.0;
        foreach ($orderItems as $item) {
            $subtotal += ($item['unit_price'] ?? 0.0) * ($item['quantity'] ?? 1);
        }
        return $subtotal;
    }

    protected function isBlackFriday(\DateTimeInterface $date): bool
    {
        return in_array($date->format('m-d'), ['11-25', '11-26']);
    }

    protected function isCyberMonday(\DateTimeInterface $date): bool
    {
        return $date->format('m-d') === '11-28';
    }

    protected function getSeasonalMultiplier(\DateTimeInterface $date): string
    {
        $monthDay = $date->format('m-d');

        if ($monthDay >= SeasonalConstants::SPRING_START && $monthDay <= SeasonalConstants::SPRING_END) {
            return 'spring';
        }
        if ($monthDay >= SeasonalConstants::SUMMER_START && $monthDay <= SeasonalConstants::SUMMER_END) {
            return 'summer';
        }
        if ($monthDay >= SeasonalConstants::FALL_START && $monthDay <= SeasonalConstants::FALL_END) {
            return 'fall';
        }
        return 'winter';
    }

    abstract protected function buildResult(
        float $baseValue,
        float $subtotal,
        array $appliedRules,
        array $context
    ): CalculationResult;
}

final class DiscountSeasonalCalculator extends BaseSeasonalCalculator implements SeasonalCalculationStrategy
{
    private array $seasonalDiscounts = [
        'spring' => 0.15,
        'summer' => 0.25,
        'fall' => 0.20,
        'winter' => 0.30,
        'black_friday' => 0.40,
        'cyber_monday' => 0.35,
    ];

    public function calculate(array $items, \DateTimeInterface $date, array $context): CalculationResult
    {
        $subtotal = $this->calculateSubtotal($items);
        $seasonalValue = $this->getSeasonalValue($date);
        $baseValue = $subtotal * $seasonalValue;

        $appliedRules = [$this->getSeasonalName()];
        if ($this->isBlackFriday($date)) {
            $appliedRules = ['black_friday'];
        } elseif ($this->isCyberMonday($date)) {
            $appliedRules = ['cyber_monday'];
        }

        return $this->buildResult($baseValue, $subtotal, $appliedRules, $context);
    }

    public function getSeasonalValue(\DateTimeInterface $date): float
    {
        if ($this->isBlackFriday($date)) {
            return $this->seasonalDiscounts['black_friday'];
        }
        if ($this->isCyberMonday($date)) {
            return $this->seasonalDiscounts['cyber_monday'];
        }
        return $this->seasonalDiscounts[$this->getSeasonalMultiplier($date)];
    }

    public function getSeasonalName(): string
    {
        return 'seasonal_discount';
    }

    protected function buildResult(float $baseValue, float $subtotal, array $appliedRules, array $context): CalculationResult
    {
        return new DiscountResult($baseValue, ($baseValue / $subtotal) * 100, $subtotal - $baseValue, $appliedRules);
    }
}
