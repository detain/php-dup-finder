<?php
declare(strict_types=1);

namespace PayrollEngine\Retention;

use Psr\Log\LoggerInterface;

final class RetentionBonusCalculator
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

    private const TENURE_YEARS_LOW = 2;
    private const TENURE_YEARS_MEDIUM = 5;
    private const TENURE_YEARS_HIGH = 10;

    private const RETENTION_BONUS_MULTIPLIER_LOW = 0.10;
    private const RETENTION_BONUS_MULTIPLIER_MEDIUM = 0.15;
    private const RETENTION_BONUS_MULTIPLIER_HIGH = 0.25;

    private const MINIMUM_RETENTION_AMOUNT = 2000.00;
    private const MAXIMUM_RETENTION_AMOUNT = 75000.00;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function calculateRetentionBonus(array $employeeData, array $retentionFactors): RetentionResult
    {
        $this->logger->debug('Calculating retention bonus', [
            'employee_id' => $employeeData['employee_id'],
            'current_compensation' => $employeeData['current_compensation'],
        ]);

        $currentCompensation = (float)$employeeData['current_compensation'];
        $performanceRating = (float)$employeeData['performance_rating'];
        $yearsOfService = (int)$employeeData['years_of_service'];
        $isFlightRisk = (bool)($retentionFactors['is_flight_risk'] ?? false);
        $hasCriticalSkills = (bool)($retentionFactors['has_critical_skills'] ?? false);
        $marketCompetitiveness = (float)($retentionFactors['market_competitiveness'] ?? 1.0);

        $retentionBasePercent = $this->calculateRetentionBasePercent($yearsOfService, $currentCompensation);
        $performanceBonus = $this->calculatePerformanceBonus($performanceRating, $currentCompensation);
        $flightRiskBonus = $this->calculateFlightRiskBonus($isFlightRisk, $currentCompensation);
        $skillsBonus = $this->calculateSkillsBonus($hasCriticalSkills, $currentCompensation);
        $marketAdjustment = $this->calculateMarketAdjustment($marketCompetitiveness, $currentCompensation);

        $totalRetentionBonus = $retentionBasePercent * $currentCompensation
            + $performanceBonus + $flightRiskBonus + $skillsBonus + $marketAdjustment;

        if ($totalRetentionBonus < self::MINIMUM_RETENTION_AMOUNT) {
            $totalRetentionBonus = self::MINIMUM_RETENTION_AMOUNT;
        }

        if ($totalRetentionBonus > self::MAXIMUM_RETENTION_AMOUNT) {
            $totalRetentionBonus = self::MAXIMUM_RETENTION_AMOUNT;
        }

        $this->logger->info('Retention bonus calculated', [
            'base_percent' => $retentionBasePercent,
            'performance_bonus' => $performanceBonus,
            'flight_risk_bonus' => $flightRiskBonus,
            'skills_bonus' => $skillsBonus,
            'total_bonus' => $totalRetentionBonus,
        ]);

        return new RetentionResult(
            baseCompensation: $currentCompensation,
            totalRetentionBonus: $totalRetentionBonus,
            effectiveRetentionPercent: $currentCompensation > 0 ? ($totalRetentionBonus / $currentCompensation) : 0,
            breakdown: [
                'base_percent' => $retentionBasePercent,
                'performance' => $performanceBonus,
                'flight_risk' => $flightRiskBonus,
                'critical_skills' => $skillsBonus,
                'market_adjustment' => $marketAdjustment,
            ],
        );
    }

    private function calculateRetentionBasePercent(int $yearsOfService, float $compensation): float
    {
        if ($yearsOfService >= self::TENURE_YEARS_HIGH) {
            return self::RETENTION_BONUS_MULTIPLIER_HIGH;
        }
        if ($yearsOfService >= self::TENURE_YEARS_MEDIUM) {
            return self::RETENTION_BONUS_MULTIPLIER_MEDIUM;
        }
        if ($yearsOfService >= self::TENURE_YEARS_LOW) {
            return self::RETENTION_BONUS_MULTIPLIER_LOW;
        }
        return 0.0;
    }

    private function calculatePerformanceBonus(float $performanceRating, float $compensation): float
    {
        if ($performanceRating >= self::PERFORMANCE_RATING_EXCEEDS) {
            return $compensation * self::MERIT_INCREASE_MAX_PERCENT;
        }
        if ($performanceRating >= self::PERFORMANCE_RATING_MEETS) {
            return $compensation * ((self::MERIT_INCREASE_MIN_PERCENT + self::MERIT_INCREASE_MAX_PERCENT) / 2);
        }
        if ($performanceRating >= self::PERFORMANCE_RATING_DEVELOPS) {
            return $compensation * self::MERIT_INCREASE_MIN_PERCENT;
        }
        return 0.0;
    }

    private function calculateFlightRiskBonus(bool $isFlightRisk, float $compensation): float
    {
        if (!$isFlightRisk) {
            return 0.0;
        }
        return $compensation * self::MARKET_ADJUSTMENT_MAX_PERCENT;
    }

    private function calculateSkillsBonus(bool $hasCriticalSkills, float $compensation): float
    {
        if (!$hasCriticalSkills) {
            return 0.0;
        }
        return $compensation * self::MARKET_ADJUSTMENT_MIN_PERCENT;
    }

    private function calculateMarketAdjustment(float $marketFactor, float $compensation): float
    {
        $adjustmentPercent = ($marketFactor - 1.0);
        if ($adjustmentPercent > 0) {
            return $compensation * min($adjustmentPercent, self::MARKET_ADJUSTMENT_MAX_PERCENT);
        }
        return 0.0;
    }
}
