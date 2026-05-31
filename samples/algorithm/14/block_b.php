<?php
declare(strict_types=1);

namespace PayrollEngine\Bonus;

use Psr\Log\LoggerInterface;

final class PerformanceBonusCalculator
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

    private const TEAM_SIZE_SMALL = 5;
    private const TEAM_SIZE_MEDIUM = 15;
    private const TEAM_SIZE_LARGE = 30;

    private const MINIMUM_BONUS_AMOUNT = 500.00;
    private const MAXIMUM_BONUS_AMOUNT = 50000.00;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function calculateBonus(array $employeeData, array $companyPerformance): BonusResult
    {
        $this->logger->debug('Calculating performance bonus', [
            'employee_id' => $employeeData['employee_id'],
            'base_salary' => $employeeData['base_salary'],
        ]);

        $baseSalary = (float)$employeeData['base_salary'];
        $individualPerformance = (float)$employeeData['individual_performance_rating'];
        $teamPerformance = (float)($employeeData['team_performance_rating'] ?? 3.5);
        $companyPerformanceFactor = (float)($companyPerformance['performance_factor'] ?? 1.0);
        $teamSize = (int)($employeeData['team_size'] ?? 0);

        $individualBonusPercent = $this->calculateIndividualBonusPercent($individualPerformance);
        $teamBonusPercent = $this->calculateTeamBonusPercent($teamPerformance);
        $companyMultiplier = $this->calculateCompanyMultiplier($companyPerformanceFactor);
        $teamSizeFactor = $this->calculateTeamSizeFactor($teamSize);

        $targetBonus = $baseSalary * ($individualBonusPercent + $teamBonusPercent);
        $preliminaryBonus = $targetBonus * $companyMultiplier;

        $finalBonus = $preliminaryBonus * $teamSizeFactor;

        if ($finalBonus < self::MINIMUM_BONUS_AMOUNT) {
            $finalBonus = self::MINIMUM_BONUS_AMOUNT;
        }

        if ($finalBonus > self::MAXIMUM_BONUS_AMOUNT) {
            $finalBonus = self::MAXIMUM_BONUS_AMOUNT;
        }

        $this->logger->info('Performance bonus calculated', [
            'target_bonus' => $targetBonus,
            'company_multiplier' => $companyMultiplier,
            'team_size_factor' => $teamSizeFactor,
            'final_bonus' => $finalBonus,
        ]);

        return new BonusResult(
            targetBonus: $targetBonus,
            preliminaryBonus: $preliminaryBonus,
            finalBonus: $finalBonus,
            breakdown: [
                'individual_percent' => $individualBonusPercent,
                'team_percent' => $teamBonusPercent,
                'company_multiplier' => $companyMultiplier,
                'team_size_factor' => $teamSizeFactor,
            ],
        );
    }

    private function calculateIndividualBonusPercent(float $performanceRating): float
    {
        if ($performanceRating >= self::PERFORMANCE_RATING_EXCEEDS) {
            return self::MERIT_INCREASE_MAX_PERCENT;
        }
        if ($performanceRating >= self::PERFORMANCE_RATING_MEETS) {
            return (self::MERIT_INCREASE_MIN_PERCENT + self::MERIT_INCREASE_MAX_PERCENT) / 2;
        }
        if ($performanceRating >= self::PERFORMANCE_RATING_DEVELOPS) {
            return self::MERIT_INCREASE_MIN_PERCENT;
        }
        return 0.0;
    }

    private function calculateTeamBonusPercent(float $teamPerformance): float
    {
        if ($teamPerformance >= self::PERFORMANCE_RATING_EXCEEDS) {
            return self::MARKET_ADJUSTMENT_MAX_PERCENT;
        }
        if ($teamPerformance >= self::PERFORMANCE_RATING_MEETS) {
            return (self::MARKET_ADJUSTMENT_MIN_PERCENT + self::MARKET_ADJUSTMENT_MAX_PERCENT) / 2;
        }
        if ($teamPerformance >= self::PERFORMANCE_RATING_DEVELOPS) {
            return self::MARKET_ADJUSTMENT_MIN_PERCENT;
        }
        return 0.0;
    }

    private function calculateCompanyMultiplier(float $performanceFactor): float
    {
        if ($performanceFactor >= 1.2) {
            return self::PROMOTIONAL_INCREASE_MAX_PERCENT;
        }
        if ($performanceFactor >= 1.0) {
            return self::PROMOTIONAL_INCREASE_MIN_PERCENT + 0.05;
        }
        if ($performanceFactor >= 0.8) {
            return self::PROMOTIONAL_INCREASE_MIN_PERCENT;
        }
        return self::COST_OF_LIVING_ADJUSTMENT_PERCENT;
    }

    private function calculateTeamSizeFactor(int $teamSize): float
    {
        if ($teamSize <= 0) {
            return 1.0;
        }
        if ($teamSize <= self::TEAM_SIZE_SMALL) {
            return 1.0;
        }
        if ($teamSize <= self::TEAM_SIZE_MEDIUM) {
            return 0.95;
        }
        if ($teamSize <= self::TEAM_SIZE_LARGE) {
            return 0.90;
        }
        return 0.85;
    }
}
