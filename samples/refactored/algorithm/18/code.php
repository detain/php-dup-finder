<?php
declare(strict_types=1);

namespace FraudDetection\Shared;

interface FraudScoringStrategy
{
    public function calculateScore(mixed $context): float;
    public function getWeight(): float;
}

abstract class BaseFraudScorer
{
    protected LoggerInterface $logger;

    protected const RISK_THRESHOLDS = [
        'low' => 0.30,
        'medium' => 0.60,
        'high' => 0.80,
    ];

    protected const MAX_SCORE = 1.0;
    protected const MIN_SCORE = 0.0;

    public function calculateRiskScore(mixed $context): FraudScoreResult
    {
        $factors = [];
        $totalWeight = 0.0;

        foreach ($this->getScoringStrategies() as $strategy) {
            $score = $strategy->calculateScore($context);
            $weight = $strategy->getWeight();

            $factors[get_class($strategy)] = $score;
            $totalWeight += $weight;

            $this->logger->debug('Factor scored', [
                'strategy' => get_class($strategy),
                'score' => $score,
                'weight' => $weight,
            ]);
        }

        $weightedScore = 0.0;
        foreach ($factors as $strategyClass => $score) {
            $weight = $this->getScoringStrategies()[$strategyClass]->getWeight() ?? 0;
            $normalizedWeight = $weight / $totalWeight;
            $weightedScore += $score * $normalizedWeight;
        }

        $normalizedScore = max(self::MIN_SCORE, min(self::MAX_SCORE, $weightedScore));
        $riskLevel = $this->determineRiskLevel($normalizedScore);

        return new FraudScoreResult(
            riskScore: $normalizedScore,
            riskLevel: $riskLevel,
            recommendedAction: $this->determineAction($riskLevel),
            factors: $factors,
        );
    }

    abstract protected function getScoringStrategies(): array;

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
            'high' => 'block',
            'medium' => 'review',
            'low' => 'flag',
            default => 'allow',
        };
    }
}

final class TransactionFraudScorer extends BaseFraudScorer
{
    protected function getScoringStrategies(): array
    {
        return [
            VelocityScoringStrategy::class => new VelocityScoringStrategy(0.25),
            AmountAnomalyScoringStrategy::class => new AmountAnomalyScoringStrategy(0.20),
        ];
    }
}

final class VelocityScoringStrategy implements FraudScoringStrategy
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

    public function calculateScore(mixed $context): float
    {
        $count = $context->getTransactionCountInWindow(300);
        if ($count >= 5) {
            return 1.0;
        }
        return min(1.0, $count / 5);
    }
}
