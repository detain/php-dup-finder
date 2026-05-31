<?php
declare(strict_types=1);

namespace Compliance\Rules;

final class TransactionMonitoringRule
{
    private const SINGLE_TRANSACTION_LIMIT = 10000;
    private const DAILY_AGGREGATE_LIMIT = 25000;
    private const WEEKLY_AGGREGATE_LIMIT = 100000;
    private const MONTHLY_AGGREGATE_LIMIT = 500000;

    private const HIGH_RISK_THRESHOLD = 5000;
    private const MEDIUM_RISK_THRESHOLD = 2000;

    private const VELOCITY_WINDOW_MINUTES = 60;
    private const VELOCITY_BURST_THRESHOLD = 5;

    public function evaluateTransaction(TransactionContext $context): MonitoringDecision
    {
        $amount = $context->getAmount();
        $customerId = $context->getCustomerId();

        $amountFlags = $this->checkAmountThresholds($amount);
        $velocityFlags = $this->checkVelocity($customerId);
        $patternFlags = $this->checkBehavioralPattern($context);
        $geographicFlags = $this->checkGeographicRisk($context);

        $riskScore = $this->calculateRiskScore(
            $amountFlags,
            $velocityFlags,
            $patternFlags,
            $geographicFlags
        );

        $decision = $this->makeDecision($riskScore, $amount);

        return new MonitoringDecision(
            action: $decision,
            riskScore: $riskScore,
            flags: array_merge(
                $amountFlags,
                $velocityFlags,
                $patternFlags,
                $geographicFlags
            ),
        );
    }

    private function checkAmountThresholds(float $amount): array
    {
        $flags = [];

        if ($amount >= self::SINGLE_TRANSACTION_LIMIT) {
            $flags[] = 'single_transaction_limit_exceeded';
        }

        if ($amount >= self::HIGH_RISK_THRESHOLD) {
            $flags[] = 'high_value_transaction';
        } elseif ($amount >= self::MEDIUM_RISK_THRESHOLD) {
            $flags[] = 'medium_value_transaction';
        }

        return $flags;
    }

    private function checkVelocity(string $customerId): array
    {
        $flags = [];

        $recentTransactions = $this->getTransactionCountInWindow(
            $customerId,
            self::VELOCITY_WINDOW_MINUTES
        );

        if ($recentTransactions >= self::VELOCITY_BURST_THRESHOLD) {
            $flags[] = 'velocity_burst_detected';
        }

        $dailyTotal = $this->getDailyTotal($customerId);
        if ($dailyTotal >= self::DAILY_AGGREGATE_LIMIT) {
            $flags[] = 'daily_limit_exceeded';
        }

        return $flags;
    }

    private function checkBehavioralPattern(TransactionContext $context): array
    {
        $flags = [];

        $isNewPayee = $this->isNewPayee($context->getCustomerId(), $context->getPayeeId());
        if ($isNewPayee) {
            $flags[] = 'new_payee_first_transaction';
        }

        $unusualHour = $this->isUnusualHour($context->getTimestamp());
        if ($unusualHour) {
            $flags[] = 'unusual_transaction_hour';
        }

        $deviatesFromPattern = $this->deviatesFromTypicalPattern($context);
        if ($deviatesFromPattern) {
            $flags[] = 'pattern_deviation';
        }

        return $flags;
    }

    private function checkGeographicRisk(TransactionContext $context): array
    {
        $flags = [];

        $isCrossBorder = $context->isCrossBorderTransaction();
        if ($isCrossBorder) {
            $flags[] = 'cross_border_transaction';
        }

        $isHighRiskCountry = $this->isHighRiskCountry($context->getDestinationCountry());
        if ($isHighRiskCountry) {
            $flags[] = 'high_risk_destination';
        }

        return $flags;
    }

    private function calculateRiskScore(
        array $amountFlags,
        array $velocityFlags,
        array $patternFlags,
        array $geographicFlags
    ): float {
        $score = 0.0;

        $score += count($amountFlags) * 0.2;
        $score += count($velocityFlags) * 0.3;
        $score += count($patternFlags) * 0.15;
        $score += count($geographicFlags) * 0.25;

        return min(1.0, $score);
    }

    private function makeDecision(float $riskScore, float $amount): string
    {
        if ($riskScore >= 0.8) {
            return 'block';
        }

        if ($riskScore >= 0.5) {
            return 'review';
        }

        if ($riskScore >= 0.3) {
            return 'flag';
        }

        return 'allow';
    }

    private function getTransactionCountInWindow(string $customerId, int $windowMinutes): int
    {
        return 0;
    }

    private function getDailyTotal(string $customerId): float
    {
        return 0.0;
    }

    private function isNewPayee(string $customerId, string $payeeId): bool
    {
        return false;
    }

    private function isUnusualHour(\DateTimeImmutable $timestamp): bool
    {
        $hour = (int) $timestamp->format('G');
        return $hour < 6 || $hour > 22;
    }

    private function deviatesFromTypicalPattern(TransactionContext $context): bool
    {
        return false;
    }

    private function isHighRiskCountry(string $countryCode): bool
    {
        $highRiskCountries = ['XX', 'YY', 'ZZ'];
        return in_array($countryCode, $highRiskCountries);
    }
}
