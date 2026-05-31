<?php
declare(strict_types=1);

namespace Portfolio\RiskAnalysis;

use Psr\Log\LoggerInterface;

final class CryptoRiskCalculator
{
    private const VOLATILITY_WINDOW_DAYS = 30;
    private const VOLATILITY_LOW_THRESHOLD = 0.50;
    private const VOLATILITY_MEDIUM_THRESHOLD = 1.00;
    private const VOLATILITY_WEIGHT = 0.35;

    private const LIQUIDITY_WEIGHT = 0.30;

    private const CORRELATION_WEIGHT = 0.20;

    private const NETWORK_CONCENTRATION_WEIGHT = 0.15;

    private const RISK_LEVEL_LOW = 0.30;
    private const RISK_LEVEL_MEDIUM = 0.60;
    private const RISK_LEVEL_HIGH = 0.85;

    private const MAX_RISK_SCORE = 1.0;
    private const MIN_RISK_SCORE = 0.0;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function calculateRiskScore(CryptoPosition $position): RiskAssessment
    {
        $this->logger->debug('Calculating crypto risk score', [
            'asset' => $position->getAssetSymbol(),
            'holdings' => $position->getHoldings(),
        ]);

        $volatilityScore = $this->calculateVolatilityScore($position);
        $liquidityScore = $this->calculateLiquidityScore($position);
        $correlationScore = $this->calculateCorrelationScore($position);
        $concentrationScore = $this->calculateNetworkConcentrationScore($position);

        $weightedScore = ($volatilityScore * self::VOLATILITY_WEIGHT)
            + ($liquidityScore * self::LIQUIDITY_WEIGHT)
            + ($correlationScore * self::CORRELATION_WEIGHT)
            + ($concentrationScore * self::NETWORK_CONCENTRATION_WEIGHT);

        $normalizedScore = max(self::MIN_RISK_SCORE, min(self::MAX_RISK_SCORE, $weightedScore));

        $riskLevel = $this->determineRiskLevel($normalizedScore);
        $recommendedAction = $this->determineAction($riskLevel);

        $this->logger->info('Crypto risk assessment completed', [
            'asset' => $position->getAssetSymbol(),
            'risk_score' => $normalizedScore,
            'risk_level' => $riskLevel,
        ]);

        return new RiskAssessment(
            riskScore: $normalizedScore,
            riskLevel: $riskLevel,
            recommendedAction: $recommendedAction,
            factors: [
                'volatility' => $volatilityScore,
                'liquidity' => $liquidityScore,
                'correlation' => $correlationScore,
                'network_concentration' => $concentrationScore,
            ],
        );
    }

    private function calculateVolatilityScore(CryptoPosition $position): float
    {
        $annualizedVolatility = $position->getAnnualizedVolatility(self::VOLATILITY_WINDOW_DAYS);

        if ($annualizedVolatility >= self::VOLATILITY_MEDIUM_THRESHOLD) {
            return 1.0;
        }

        if ($annualizedVolatility >= self::VOLATILITY_LOW_THRESHOLD) {
            return 0.7;
        }

        return min(1.0, $annualizedVolatility / self::VOLATILITY_LOW_THRESHOLD);
    }

    private function calculateLiquidityScore(CryptoPosition $position): float
    {
        $dailyVolumeUsd = $position->get24hVolumeUsd();
        $holdingsUsd = $position->getHoldingsUsd();

        if ($dailyVolumeUsd <= 0) {
            return 1.0;
        }

        $liquidityRatio = $holdingsUsd / $dailyVolumeUsd;

        if ($liquidityRatio >= 0.1) {
            return 1.0;
        }

        if ($liquidityRatio >= 0.05) {
            return 0.7;
        }

        if ($liquidityRatio >= 0.01) {
            return 0.4;
        }

        return 0.1;
    }

    private function calculateCorrelationScore(CryptoPosition $position): float
    {
        $btcCorrelation = $position->getCorrelationToBitcoin();

        if ($btcCorrelation >= 0.9) {
            return 1.0;
        }

        if ($btcCorrelation >= 0.7) {
            return 0.7;
        }

        if ($btcCorrelation >= 0.5) {
            return 0.4;
        }

        return 0.1;
    }

    private function calculateNetworkConcentrationScore(CryptoPosition $position): float
    {
        $top10HolderPercent = $position->getTop10HolderPercentage();

        if ($top10HolderPercent >= 80) {
            return 1.0;
        }

        if ($top10HolderPercent >= 60) {
            return 0.8;
        }

        if ($top10HolderPercent >= 40) {
            return 0.5;
        }

        if ($top10HolderPercent >= 20) {
            return 0.2;
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
