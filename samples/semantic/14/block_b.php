<?php
declare(strict_types=1);

namespace Compliance\Rules;

final class FraudDetectionRule
{
    private const ALERT_THRESHOLD_AMOUNT = 10000;
    private const INVESTIGATION_THRESHOLD_AMOUNT = 25000;
    private const BLOCK_THRESHOLD_AMOUNT = 50000;

    private const HIGH_RISK_AMOUNT = 5000;
    private const ELEVATED_RISK_AMOUNT = 2000;

    private const BURST_TRANSACTION_COUNT = 5;
    private const TIME_WINDOW_MINUTES = 60;

    public function assessTransaction(FraudCheckRequest $request): FraudCheckResult
{
        $transactionAmount = $request->getAmount();
        $accountIdentifier = $request->getAccountId();

        $ruleViolations = $this->evaluateRuleViolations($transactionAmount, $accountIdentifier);
        $anomalyIndicators = $this->detectAnomalies($request);
        $threatSignals = $this->identifyThreatSignals($request);

        $compositeScore = $this->computeCompositeScore(
            $ruleViolations,
            $anomalyIndicators,
            $threatSignals
        );

        $reviewAction = $this->determineReviewAction($compositeScore, $transactionAmount);

        return new FraudCheckResult(
            recommendedAction: $reviewAction,
            fraudScore: $compositeScore,
            triggeredRules: $ruleViolations,
            anomalies: $anomalyIndicators,
            threatIndicators: $threatSignals,
        );
    }

    private function evaluateRuleViolations(float $amount, string $accountId): array
    {
        $violations = [];

        if ($amount > self::BLOCK_THRESHOLD_AMOUNT) {
            $violations[] = 'amount_exceeds_block_threshold';
        } elseif ($amount > self::INVESTIGATION_THRESHOLD_AMOUNT) {
            $violations[] = 'amount_requires_investigation';
        }

        if ($amount >= self::HIGH_RISK_AMOUNT) {
            $violations[] = 'high_risk_amount_flag';
        } elseif ($amount >= self::ELEVATED_RISK_AMOUNT) {
            $violations[] = 'elevated_risk_amount_flag';
        }

        $transactionCount = $this->countRecentTransactions($accountId);
        if ($transactionCount >= self::BURST_TRANSACTION_COUNT) {
            $violations[] = 'rapid_fire_transactions';
        }

        return $violations;
    }

    private function detectAnomalies(FraudCheckRequest $request): array
    {
        $anomalies = [];

        $isOffHours = $this->isOutsideBusinessHours($request->getTimestamp());
        if ($isOffHours) {
            $anomalies[] = 'off_hours_transaction';
        }

        $deviceFingerprintChanged = $request->hasDeviceFingerprintChanged();
        if ($deviceFingerprintChanged) {
            $anomalies[] = 'new_device_detected';
        }

        $locationChanged = $request->hasLocationChanged();
        if ($locationChanged) {
            $anomalies[] = 'impossible_travel_detected';
        }

        $isInternational = $request->isInternationalDestination();
        if ($isInternational) {
            $anomalies[] = 'international_transaction';
        }

        return $anomalies;
    }

    private function identifyThreatSignals(FraudCheckRequest $request): array
    {
        $signals = [];

        $isFromHighRiskCountry = $this->isHighRiskJurisdiction($request->getCountryCode());
        if ($isFromHighRiskCountry) {
            $signals[] = 'high_risk_jurisdiction';
        }

        $isAnonymousProxy = $request->isUsingAnonymousProxy();
        if ($isAnonymousProxy) {
            $signals[] = 'anonymous_proxy_detected';
        }

        $isTorExitNode = $request->isTorExitNode();
        if ($isTorExitNode) {
            $signals[] = 'tor_exit_node_detected';
        }

        return $signals;
    }

    private function computeCompositeScore(
        array $ruleViolations,
        array $anomalyIndicators,
        array $threatSignals
    ): float {
        $score = 0.0;

        $score += count($ruleViolations) * 0.25;
        $score += count($anomalyIndicators) * 0.20;
        $score += count($threatSignals) * 0.35;

        return min(1.0, $score);
    }

    private function determineReviewAction(float $compositeScore, float $amount): string
    {
        if ($compositeScore >= 0.75) {
            return 'block_and_hold';
        }

        if ($compositeScore >= 0.50) {
            return 'require_manual_review';
        }

        if ($compositeScore >= 0.30) {
            return 'flag_for_monitoring';
        }

        return 'approve';
    }

    private function countRecentTransactions(string $accountId): int
    {
        return 0;
    }

    private function isOutsideBusinessHours(\DateTimeImmutable $timestamp): bool
    {
        $hour = (int) $timestamp->format('G');
        return $hour < 7 || $hour > 21;
    }

    private function isHighRiskJurisdiction(string $countryCode): bool
    {
        $highRiskJurisdictions = ['XX', 'YY', 'ZZ'];
        return in_array($countryCode, $highRiskJurisdictions);
    }
}
