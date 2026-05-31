<?php
declare(strict_types=1);

namespace PayrollEngine\Shared;

final class PerformanceThresholds
{
    public const EXCEEDS = 5.0;
    public const MEETS = 3.5;
    public const DEVELOPS = 2.5;
    public const BELOW = 1.0;
}

final class IncreasePercentages
{
    public const MERIT_MIN = 0.02;
    public const MERIT_MAX = 0.08;
    public const MARKET_MIN = 0.03;
    public const MARKET_MAX = 0.12;
    public const PROMOTIONAL_MIN = 0.08;
    public const PROMOTIONAL_MAX = 0.20;
    public const COLA = 0.04;

    public static function getMeritForRating(float $rating): float
    {
        if ($rating >= PerformanceThresholds::EXCEEDS) {
            return self::MERIT_MAX;
        }
        if ($rating >= PerformanceThresholds::MEETS) {
            return (self::MERIT_MIN + self::MERIT_MAX) / 2;
        }
        if ($rating >= PerformanceThresholds::DEVELOPS) {
            return self::MERIT_MIN;
        }
        return 0.0;
    }
}

interface CalculationStrategy
{
    public function calculate(float $baseAmount, array $factors): float;
}

trait BaseCalculationLogic
{
    protected function sumComponents(array $components): float
    {
        return array_sum(array_filter($components, fn($v) => $v > 0));
    }

    protected function applyBounds(float $amount, float $min, float $max): float
    {
        if ($amount < $min) {
            return $min;
        }
        if ($amount > $max) {
            return $max;
        }
        return $amount;
    }
}

abstract class BaseCalculator
{
    use BaseCalculationLogic;

    protected LoggerInterface $logger;

    protected function calculatePercentOf(float $base, float $percent): float
    {
        return $base * $percent;
    }

    protected function calculateBonus(float $base, float $rating, float $minRate, float $maxRate): float
    {
        $percent = IncreasePercentages::getMeritForRating($rating);
        return $this->calculatePercentOf($base, $percent);
    }
}

final class SalaryIncreaseCalculator extends BaseCalculator
{
    public function calculate(array $employeeData, array $budgetConstraints): SalaryIncreaseResult
    {
        $currentSalary = (float)$employeeData['current_salary'];
        $performance = (float)$employeeData['performance_rating'];
        $years = (int)$employeeData['years_of_service'];

        $merit = $this->calculateBonus($currentSalary, $performance, IncreasePercentages::MERIT_MIN, IncreasePercentages::MERIT_MAX);
        $yearsBonus = $years >= 3 ? $currentSalary * min($years * 0.005, 0.05) : 0;
        $cola = $this->calculatePercentOf($currentSalary, IncreasePercentages::COLA);

        $components = [$merit, $yearsBonus, $cola];
        $totalIncrease = $this->sumComponents($components);

        $budgetCap = match ($budgetConstraints['budget_level'] ?? 'medium') {
            'low' => 0.02, 'medium' => 0.04, 'high' => 0.06, default => 0.04
        };
        $totalIncrease = min($totalIncrease, $currentSalary * $budgetCap);
        $totalIncrease = $this->applyBounds($totalIncrease, 1000, 25000);

        return new SalaryIncreaseResult(
            currentSalary: $currentSalary,
            newSalary: $currentSalary + $totalIncrease,
            totalIncrease: $totalIncrease,
            increasePercentage: ($totalIncrease / $currentSalary) * 100,
            breakdown: ['merit' => $merit, 'years_bonus' => $yearsBonus, 'cola' => $cola],
        );
    }
}
