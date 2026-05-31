<?php
declare(strict_types=1);

namespace Portfolio\Shared;

interface RiskFactorStrategy
{
    public function calculateScore(mixed $position): float;
    public function getWeight(): float;
    public function getFactorName(): string;
}

abstract class BaseRiskCalculator
{
    protected LoggerInterface $logger;

    protected const RISK_THRESHOLDS = [
        'low' => 0.25,
        'medium' => 0.50,
        'high' => 0.75,
    ];

    protected const MAX_SCORE = 1.0;
    protected const MIN_SCORE = 0.0;

    public function calculateRiskScore(mixed $position): RiskAssessment
    {
        $factors = [];
        $totalWeight = 0.0;

        foreach ($this->getRiskStrategies() as $strategy) {
            $score = $strategy->calculateScore($position);
            $weight = $strategy->getWeight();

            $factors[$strategy->getFactorName()] = $score;
            $totalWeight += $weight;

            $this->logger->debug('Risk factor calculated', [
                'factor' => $strategy->getFactorName(),
                'score' => $score,
                'weight' => $weight,
            ]);
        }

        $weightedScore = 0.0;
        foreach ($factors as $factorName => $score) {
            $weight = $this->findStrategyWeight($factorName);
            $normalizedWeight = $weight / $totalWeight;
            $weightedScore += $score * $normalizedWeight;
        }

        $normalizedScore = max(self::MIN_SCORE, min(self::MAX_SCORE, $weightedScore));
        $riskLevel = $this->determineRiskLevel($normalizedScore);

        return new RiskAssessment(
            riskScore: $normalizedScore,
            riskLevel: $riskLevel,
            recommendedAction: $this->determineAction($riskLevel),
            factors: $factors,
        );
    }

    abstract protected function getRiskStrategies(): array;
    abstract protected function findStrategyWeight(string $factorName): float;

    protected function determineRiskLevel(float $score): string
    {
        if ($score >= self::RISK_THRESHOLDS['high']) {
            return 'high';
        }
        if ($score >= self::RISK_THRESHOLDS['medium']) {
            return 'medium';
        }
        if ($score >= self::RISK_THRESHOLDS['low']) {
            return 'low';
        }
        return 'minimal';
    }

    protected function determineAction(string $riskLevel): string
    {
        return match ($riskLevel) {
            'high' => 'reduce_position',
            'medium' => 'monitor',
            'low' => 'hold',
            default => 'buy_more',
        };
    }
}

final class StockRiskCalculator extends BaseRiskCalculator
{
    protected function getRiskStrategies(): array
    {
        return [
            new VolatilityRiskStrategy(0.35),
            new BetaRiskStrategy(0.25),
            new DrawdownRiskStrategy(0.25),
            new LiquidityRiskStrategy(0.15),
        ];
    }

    protected function findStrategyWeight(string $factorName): float
    {
        return match ($factorName) {
            'volatility' => 0.35,
            'beta' => 0.25,
            'drawdown' => 0.25,
            'liquidity' => 0.15,
            default => 0.0,
        };
    }
}

final class VolatilityRiskStrategy implements RiskFactorStrategy
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
        return 'volatility';
    }

    public function calculateScore(mixed $position): float
    {
        $volatility = $position->getHistoricalVolatility(30);
        if ($volatility >= 0.30) {
            return 1.0;
        }
        if ($volatility >= 0.15) {
            return 0.6;
        }
        return min(1.0, $volatility / 0.15);
    }
}
