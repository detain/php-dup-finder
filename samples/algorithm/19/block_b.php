<?php
declare(strict_types=1);

namespace Portfolio\RiskAnalysis;

use Psr\Log\LoggerInterface;

final class BondRiskCalculator
{
    private const DURATION_WINDOW_YEARS = 10;
    private const DURATION_LOW_THRESHOLD = 3.0;
    private const DURATION_MEDIUM_THRESHOLD = 7.0;
    private const DURATION_WEIGHT = 0.30;

    private const CREDIT_SPREAD_WEIGHT = 0.30;

    private const MATURITY_WEIGHT = 0.20;

    private const CALL_RISK_WEIGHT = 0.20;

    private const RISK_LEVEL_LOW = 0.25;
    private const RISK_LEVEL_MEDIUM = 0.50;
    private const RISK_LEVEL_HIGH = 0.75;

    private const MAX_RISK_SCORE = 1.0;
    private const MIN_RISK_SCORE = 0.0;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function calculateRiskScore(BondPosition $position): RiskAssessment
    {
        $this->logger->debug('Calculating bond risk score', [
            'cusip' => $position->getCusip(),
            'par_value' => $position->getParValue(),
        ]);

        $durationScore = $this->calculateDurationScore($position);
        $spreadScore = $this->calculateCreditSpreadScore($position);
        $maturityScore = $this->calculateMaturityScore($position);
        $callScore = $this->calculateCallRiskScore($position);

        $weightedScore = ($durationScore * self::DURATION_WEIGHT)
            + ($spreadScore * self::CREDIT_SPREAD_WEIGHT)
            + ($maturityScore * self::MATURITY_WEIGHT)
            + ($callScore * self::CALL_RISK_WEIGHT);

        $normalizedScore = max(self::MIN_RISK_SCORE, min(self::MAX_RISK_SCORE, $weightedScore));

        $riskLevel = $this->determineRiskLevel($normalizedScore);
        $recommendedAction = $this->determineAction($riskLevel);

        $this->logger->info('Bond risk assessment completed', [
            'cusip' => $position->getCusip(),
            'risk_score' => $normalizedScore,
            'risk_level' => $riskLevel,
        ]);

        return new RiskAssessment(
            riskScore: $normalizedScore,
            riskLevel: $riskLevel,
            recommendedAction: $recommendedAction,
            factors: [
                'duration' => $durationScore,
                'credit_spread' => $spreadScore,
                'maturity' => $maturityScore,
                'call_risk' => $callScore,
            ],
        );
    }

    private function calculateDurationScore(BondPosition $position): float
    {
        $modifiedDuration = $position->getModifiedDuration();

        if ($modifiedDuration >= self::DURATION_MEDIUM_THRESHOLD) {
            return 1.0;
        }

        if ($modifiedDuration >= self::DURATION_LOW_THRESHOLD) {
            return 0.6;
        }

        return $modifiedDuration / self::DURATION_MEDIUM_THRESHOLD;
    }

    private function calculateCreditSpreadScore(BondPosition $position): float
    {
        $creditSpread = $position->getCreditSpread();

        if ($creditSpread >= 300) {
            return 1.0;
        }

        if ($creditSpread >= 200) {
            return 0.8;
        }

        if ($creditSpread >= 100) {
            return 0.5;
        }

        if ($creditSpread >= 50) {
            return 0.2;
        }

        return 0.0;
    }

    private function calculateMaturityScore(BondPosition $position): float
    {
        $yearsToMaturity = $position->getYearsToMaturity();

        if ($yearsToMaturity >= 20) {
            return 1.0;
        }

        if ($yearsToMaturity >= 10) {
            return 0.7;
        }

        if ($yearsToMaturity >= 5) {
            return 0.4;
        }

        return 0.1;
    }

    private function calculateCallRiskScore(BondPosition $position): float
    {
        if (!$position->isCallable()) {
            return 0.0;
        }

        $callProbability = $position->getProbabilityOfCall();

        if ($callProbability >= 0.5) {
            return 1.0;
        }

        if ($callProbability >= 0.25) {
            return 0.6;
        }

        return 0.3;
    }

    private function determineRiskLevel(float $score): string
    {
        if ($score >= self::RISK_LEVEL_HIGH) {
            return 'high';
        }

        if ($score >= self::RISK_LEVEL_MEDIUM) {
            return 'medium';
        }

        if ($score >= self::RISK_LEVEL_LOW) {
            return 'low';
        }

        return 'minimal';
    }

    private function determineAction(string $riskLevel): string
    {
        return match ($riskLevel) {
            'high' => 'reduce_position',
            'medium' => 'review',
            'low' => 'hold',
            default => 'buy_more',
        };
    }
}
