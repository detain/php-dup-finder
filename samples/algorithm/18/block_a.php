<?php
declare(strict_types=1);

namespace FraudDetection\Scoring;

use Psr\Log\LoggerInterface;

final class TransactionFraudScorer
{
    private const VELOCITY_WINDOW_SECONDS = 300;
    private const VELOCITY_THRESHOLD_HIGH = 5;
    private const VELOCITY_THRESHOLD_MEDIUM = 3;
    private const VELOCITY_WEIGHT = 0.25;

    private const AMOUNT_ANOMALY_THRESHOLD = 3.0;
    private const AMOUNT_WEIGHT = 0.20;

    private const GEO_VELOCITY_THRESHOLD_KM = 500;
    private const GEO_VELOCITY_WEIGHT = 0.15;

    private const NEW_DEVICE_WEIGHT = 0.10;
    private const NEW_ACCOUNT_WEIGHT = 0.15;

    private const RISK_SCORE_LOW = 0.30;
    private const RISK_SCORE_MEDIUM = 0.60;
    private const RISK_SCORE_HIGH = 0.80;

    private const MAX_RISK_SCORE = 1.0;
    private const MIN_RISK_SCORE = 0.0;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function calculateRiskScore(TransactionContext $context): FraudScoreResult
    {
        $this->logger->debug('Calculating fraud risk score', [
            'transaction_id' => $context->getTransactionId(),
            'account_id' => $context->getAccountId(),
        ]);

        $velocityScore = $this->calculateVelocityScore($context);
        $amountScore = $this->calculateAmountAnomalyScore($context);
        $geoScore = $this->calculateGeoVelocityScore($context);
        $deviceScore = $this->calculateNewDeviceScore($context);
        $accountScore = $this->calculateNewAccountScore($context);

        $weightedScore = ($velocityScore * self::VELOCITY_WEIGHT)
            + ($amountScore * self::AMOUNT_WEIGHT)
            + ($geoScore * self::GEO_VELOCITY_WEIGHT)
            + ($deviceScore * self::NEW_DEVICE_WEIGHT)
            + ($accountScore * self::NEW_ACCOUNT_WEIGHT);

        $normalizedScore = max(self::MIN_RISK_SCORE, min(self::MAX_RISK_SCORE, $weightedScore));

        $riskLevel = $this->determineRiskLevel($normalizedScore);
        $recommendedAction = $this->determineAction($riskLevel);

        $this->logger->info('Fraud risk score calculated', [
            'transaction_id' => $context->getTransactionId(),
            'risk_score' => $normalizedScore,
            'risk_level' => $riskLevel,
        ]);

        return new FraudScoreResult(
            riskScore: $normalizedScore,
            riskLevel: $riskLevel,
            recommendedAction: $recommendedAction,
            factors: [
                'velocity' => $velocityScore,
                'amount_anomaly' => $amountScore,
                'geo_velocity' => $geoScore,
                'new_device' => $deviceScore,
                'new_account' => $accountScore,
            ],
        );
    }

    private function calculateVelocityScore(TransactionContext $context): float
    {
        $recentTransactionCount = $context->getTransactionCountInWindow(self::VELOCITY_WINDOW_SECONDS);

        if ($recentTransactionCount >= self::VELOCITY_THRESHOLD_HIGH) {
            return 1.0;
        }

        if ($recentTransactionCount >= self::VELOCITY_THRESHOLD_MEDIUM) {
            return 0.7;
        }

        return $recentTransactionCount / self::VELOCITY_THRESHOLD_HIGH;
    }

    private function calculateAmountAnomalyScore(TransactionContext $context): float
    {
        $transactionAmount = $context->getTransactionAmount();
        $averageAmount = $context->getAccountAverageTransactionAmount();

        if ($averageAmount <= 0) {
            return 0.5;
        }

        $ratio = $transactionAmount / $averageAmount;

        if ($ratio >= self::AMOUNT_ANOMALY_THRESHOLD) {
            return 1.0;
        }

        if ($ratio >= 2.0) {
            return 0.7;
        }

        if ($ratio >= 1.5) {
            return 0.4;
        }

        return 0.1;
    }

    private function calculateGeoVelocityScore(TransactionContext $context): float
    {
        $previousLocation = $context->getPreviousTransactionLocation();
        if ($previousLocation === null) {
            return 0.0;
        }

        $currentLocation = $context->getCurrentLocation();
        $distanceKm = $this->calculateDistance($previousLocation, $currentLocation);
        $timeDifference = $context->getTimeSinceLastTransaction();

        if ($timeDifference <= 0) {
            return 0.0;
        }

        $velocityKmPerHour = $distanceKm / ($timeDifference / 3600);

        if ($velocityKmPerHour > self::GEO_VELOCITY_THRESHOLD_KM) {
            return 1.0;
        }

        return min(1.0, $velocityKmPerHour / self::GEO_VELOCITY_THRESHOLD_KM);
    }

    private function calculateNewDeviceScore(TransactionContext $context): float
    {
        if ($context->isKnownDevice()) {
            return 0.0;
        }

        return 1.0;
    }

    private function calculateNewAccountScore(TransactionContext $context): float
    {
        $accountAgeDays = $context->getAccountAgeDays();

        if ($accountAgeDays >= 90) {
            return 0.0;
        }

        if ($accountAgeDays >= 30) {
            return 0.3;
        }

        if ($accountAgeDays >= 7) {
            return 0.6;
        }

        return 1.0;
    }

    private function determineRiskLevel(float $score): string
    {
        if ($score >= self::RISK_SCORE_HIGH) {
            return 'high';
        }

        if ($score >= self::RISK_SCORE_MEDIUM) {
            return 'medium';
        }

        if ($score >= self::RISK_SCORE_LOW) {
            return 'low';
        }

        return 'minimal';
    }

    private function determineAction(string $riskLevel): string
    {
        return match ($riskLevel) {
            'high' => 'block',
            'medium' => 'review',
            'low' => 'flag',
            default => 'allow',
        };
    }

    private function calculateDistance(array $loc1, array $loc2): float
    {
        $earthRadiusKm = 6371;

        $lat1 = deg2rad($loc1['latitude']);
        $lat2 = deg2rad($loc2['latitude']);
        $lon1 = deg2rad($loc1['longitude']);
        $lon2 = deg2rad($loc2['longitude']);

        $dlat = $lat2 - $lat1;
        $dlon = $lon2 - $lon1;

        $a = sin($dlat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dlon / 2) ** 2;
        $c = 2 * asin(sqrt($a));

        return $earthRadiusKm * $c;
    }
}
