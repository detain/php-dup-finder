<?php
declare(strict_types=1);

namespace PayrollEngine\Salary;

use Psr\Log\LoggerInterface;

final class SalaryIncreaseCalculator
{
    private const MERIT_INCREASE_MIN_PERCENT = 0.02;
    private const MERIT_INCREASE_MAX_PERCENT = 0.08;
    private const MARKET_ADJUSTMENT_MIN_PERCENT = 0.03;
    private const MARKET_ADJUSTMENT_MAX_PERCENT = 0.12;
    private const PROMOTIONAL_INCREASE_MIN_PERCENT = 0.08;
    private const PROMOTIONAL_INCREASE_MAX_PERCENT = 0.20;
    private const COST_OF_LIVING_ADJUSTMENT_PERCENT = 0.04;

    private const PERFORMANCE_RATING_EXCEEDS = 5.0;
    private const PERFORMANCE_RATING_MEETS = 3.5;
    private const PERFORMANCE_RATING_DEVELOPS = 2.5;
    private const PERFORMANCE_RATING_BELOW = 1.0;

    private const BUDGET_CONSTRAINT_LOW = 0.02;
    private const BUDGET_CONSTRAINT_MEDIUM = 0.04;
    private const BUDGET_CONSTRAINT_HIGH = 0.06;

    private const MINIMUM_INCREASE_AMOUNT = 1000.00;
    private const MAXIMUM_INCREASE_AMOUNT = 25000.00;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function calculateIncrease(array $employeeData, array $budgetConstraints): SalaryIncreaseResult
    {
        $this->logger->debug('Calculating salary increase', [
            'employee_id' => $employeeData['employee_id'],
            'current_salary' => $employeeData['current_salary'],
        ]);

        $currentSalary = (float)$employeeData['current_salary'];
        $performanceRating = (float)$employeeData['performance_rating'];
        $yearsOfService = (int)$employeeData['years_of_service'];
        $isPromoted = (bool)$employeeData['is_promoted'];
        $marketAdjustmentFactor = (float)($employeeData['market_adjustment_factor'] ?? 1.0);

        $meritIncrease = $this->calculateMeritIncrease($performanceRating, $currentSalary);
        $marketIncrease = $this->calculateMarketAdjustment($marketAdjustmentFactor, $currentSalary);
        $promotionalIncrease = $this->calculatePromotionalIncrease($isPromoted, $currentSalary);
        $cola = $this->calculateCostOfLivingAdjustment($currentSalary);

        $budgetCap = $this->determineBudgetCap($budgetConstraints);
        $yearsBonus = $this->calculateYearsOfServiceBonus($yearsOfService, $currentSalary);

        $totalIncrease = $meritIncrease + $marketIncrease + $promotionalIncrease + $cola + $yearsBonus;

        if ($totalIncrease < self::MINIMUM_INCREASE_AMOUNT && $currentSalary > 0) {
            $totalIncrease = self::MINIMUM_INCREASE_AMOUNT;
        }

        if ($totalIncrease > self::MAXIMUM_INCREASE_AMOUNT) {
            $totalIncrease = self::MAXIMUM_INCREASE_AMOUNT;
        }

        $totalIncrease = min($totalIncrease, $currentSalary * $budgetCap);

        $newSalary = $currentSalary + $totalIncrease;
        $increasePercentage = $currentSalary > 0 ? ($totalIncrease / $currentSalary) * 100 : 0;

        $this->logger->info('Salary increase calculated', [
            'current_salary' => $currentSalary,
            'total_increase' => $totalIncrease,
            'new_salary' => $newSalary,
            'increase_percentage' => $increasePercentage,
        ]);

        return new SalaryIncreaseResult(
            currentSalary: $currentSalary,
            newSalary: $newSalary,
            totalIncrease: $totalIncrease,
            increasePercentage: $increasePercentage,
            breakdown: [
                'merit' => $meritIncrease,
                'market' => $marketIncrease,
                'promotional' => $promotionalIncrease,
                'cola' => $cola,
                'years_bonus' => $yearsBonus,
            ],
        );
    }

    private function calculateMeritIncrease(float $performanceRating, float $currentSalary): float
    {
        $meritPercent = 0.0;

        if ($performanceRating >= self::PERFORMANCE_RATING_EXCEEDS) {
            $meritPercent = self::MERIT_INCREASE_MAX_PERCENT;
        } elseif ($performanceRating >= self::PERFORMANCE_RATING_MEETS) {
            $meritPercent = (self::MERIT_INCREASE_MIN_PERCENT + self::MERIT_INCREASE_MAX_PERCENT) / 2;
        } elseif ($performanceRating >= self::PERFORMANCE_RATING_DEVELOPS) {
            $meritPercent = self::MERIT_INCREASE_MIN_PERCENT;
        }

        return $currentSalary * $meritPercent;
    }

    private function calculateMarketAdjustment(float $marketFactor, float $currentSalary): float
    {
        $adjustmentPercent = ($marketFactor - 1.0);
        if ($adjustmentPercent > 0) {
            $adjustmentPercent = min($adjustmentPercent, self::MARKET_ADJUSTMENT_MAX_PERCENT);
        } else {
            $adjustmentPercent = max($adjustmentPercent, -self::MARKET_ADJUSTMENT_MIN_PERCENT);
        }

        return $currentSalary * $adjustmentPercent;
    }

    private function calculatePromotionalIncrease(bool $isPromoted, float $currentSalary): float
    {
        if (!$isPromoted) {
            return 0.0;
        }

        return $currentSalary * self::PROMOTIONAL_INCREASE_MIN_PERCENT;
    }

    private function calculateCostOfLivingAdjustment(float $currentSalary): float
    {
        return $currentSalary * self::COST_OF_LIVING_ADJUSTMENT_PERCENT;
    }

    private function calculateYearsOfServiceBonus(int $yearsOfService, float $currentSalary): float
    {
        if ($yearsOfService < 3) {
            return 0.0;
        }

        $bonusPercent = min($yearsOfService * 0.005, 0.05);
        return $currentSalary * $bonusPercent;
    }

    private function determineBudgetCap(array $budgetConstraints): float
    {
        $budgetLevel = $budgetConstraints['budget_level'] ?? 'medium';

        return match ($budgetLevel) {
            'low' => self::BUDGET_CONSTRAINT_LOW,
            'medium' => self::BUDGET_CONSTRAINT_MEDIUM,
            'high' => self::BUDGET_CONSTRAINT_HIGH,
            default => self::BUDGET_CONSTRAINT_MEDIUM,
        };
    }
}
