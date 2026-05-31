<?php
declare(strict_types=1);

namespace Portfolio\RiskAnalysis;

use Psr\Log\LoggerInterface;

final class StockRiskCalculator
{
    private const VOLATILITY_WINDOW_DAYS = 30;
    private const VOLATILITY_LOW_THRESHOLD = 0.15;
    private const VOLATILITY_MEDIUM_THRESHOLD = 0.30;
    private const VOLATILITY_WEIGHT = 0.35;

    private const BETA_WEIGHT = 0.25;

    private const DRAWDOWN_THRESHOLD_10PCT = 0.10;
    private const DRAWDOWN_THRESHOLD_20PCT = 0.20;
    private const DRAWDOWN_WEIGHT = 0.25;

    private const LIQUIDITY_WEIGHT = 0.15;

    private const RISK_LEVEL_LOW = 0.25;
    private const RISK_LEVEL_MEDIUM = 0.50;
    private const RISK_LEVEL_HIGH = 0.75;

    private const MAX_RISK_SCORE = 1.0;
    private const MIN_RISK_SCORE = 0.0;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function calculateRiskScore(StockPosition $position): RiskAssessment
    {
        $this->logger->debug('Calculating stock risk score', [
            'symbol' => $position->getSymbol(),
            'shares' => $position->getShares(),
        ]);

        $volatilityScore = $this->calculateVolatilityScore($position);
        $betaScore = $this->calculateBetaScore($position);
        $drawdownScore = $this->calculateDrawdownScore($position);
        $liquidityScore = $this->calculateLiquidityScore($position);

        $weightedScore = ($volatilityScore * self::VOLATILITY_WEIGHT)
            + ($betaScore * self::BETA_WEIGHT)
            + ($drawdownScore * self::DRAWDOWN_WEIGHT)
            + ($liquidityScore * self::LIQUIDITY_WEIGHT);

        $normalizedScore = max(self::MIN_RISK_SCORE, min(self::MAX_RISK_SCORE, $weightedScore));

        $riskLevel = $this->determineRiskLevel($normalizedScore);
        $recommendedAction = $this->determineAction($riskLevel);

        $this->logger->info('Stock risk assessment completed', [
            'symbol' => $position->getSymbol(),
            'risk_score' => $normalizedScore,
            'risk_level' => $riskLevel,
        ]);

        return new RiskAssessment(
            riskScore: $normalizedScore,
            riskLevel: $riskLevel,
            recommendedAction: $recommendedAction,
            factors: [
                'volatility' => $volatilityScore,
                'beta' => $betaScore,
                'drawdown' => $drawdownScore,
                'liquidity' => $liquidityScore,
            ],
        );
    }

    private function calculateVolatilityScore(StockPosition $position): float
    {
        $historicalVolatility = $position->getHistoricalVolatility(self::VOLATILITY_WINDOW_DAYS);

        if ($historicalVolatility >= self::VOLATILITY_MEDIUM_THRESHOLD) {
            return 1.0;
        }

        if ($historicalVolatility >= self::VOLATILITY_LOW_THRESHOLD) {
            return 0.6;
        }

        return $historicalVolatility / self::VOLATILITY_LOW_THRESHOLD;
    }

    private function calculateBetaScore(StockPosition $position): float
    {
        $beta = $position->getBeta();

        if ($beta >= 1.5) {
            return 1.0;
        }

        if ($beta >= 1.2) {
            return 0.7;
        }

        if ($beta >= 1.0) {
            return 0.5;
        }

        if ($beta >= 0.8) {
            return 0.3;
        }

        return 0.1;
    }

    private function calculateDrawdownScore(StockPosition $position): float
    {
        $currentDrawdown = $position->getCurrentDrawdown();

        if ($currentDrawdown >= self::DRAWDOWN_THRESHOLD_20PCT) {
            return 1.0;
        }

        if ($currentDrawdown >= self::DRAWDOWN_THRESHOLD_10PCT) {
            return 0.7;
        }

        return $currentDrawdown / self::DRAWDOWN_THRESHOLD_10PCT;
    }

    private function calculateLiquidityScore(StockPosition $position): float
    {
        $averageDailyVolume = $position->getAverageDailyVolume();
        $sharesToTrade = $position->getShares();

        if ($averageDailyVolume <= 0) {
            return 1.0;
        }

        $volumeRatio = $sharesToTrade / $averageDailyVolume;

        if ($volumeRatio >= 0.5) {
            return 1.0;
        }

        if ($volumeRatio >= 0.2) {
            return 0.6;
        }

        if ($volumeRatio >= 0.1) {
            return 0.3;
        }

        return 0.0;
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
            'medium' => 'monitor',
            'low' => 'hold',
            default => 'buy_more',
        };
    }
}
