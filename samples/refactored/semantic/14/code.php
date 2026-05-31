<?php
declare(strict_types=1);

namespace Compliance\Shared;

interface ComplianceRule
{
    public function evaluate(mixed $context): ComplianceResult;
    public function getRuleName(): string;
    public function getSeverity(): string;
}

abstract class BaseComplianceRule implements ComplianceRule
{
    protected const ALERT_THRESHOLD = 0.5;
    protected const BLOCK_THRESHOLD = 0.8;

    protected function createResult(
        string $action,
        float $score,
        array $flags = []
    ): ComplianceResult {
        return new ComplianceResult(
            action: $action,
            score: $score,
            flags: $flags,
            ruleName: $this->getRuleName(),
        );
    }

    protected function calculateScore(array $factorCounts, array $weights): float
    {
        $score = 0.0;

        foreach ($factorCounts as $factor => $count) {
            $weight = $weights[$factor] ?? 0.2;
            $score += $count * $weight;
        }

        return min(1.0, $score);
    }

    protected function determineAction(float $score): string
    {
        if ($score >= self::BLOCK_THRESHOLD) {
            return 'block';
        }

        if ($score >= self::ALERT_THRESHOLD) {
            return 'review';
        }

        return 'allow';
    }
}

final class AmountThresholdRule extends BaseComplianceRule
{
    private const HIGH_RISK_AMOUNT = 5000;
    private const BLOCK_AMOUNT = 10000;

    public function getRuleName(): string
    {
        return 'amount_threshold';
    }

    public function getSeverity(): string
    {
        return 'high';
    }

    public function evaluate(mixed $context): ComplianceResult
    {
        $amount = $context->getAmount();
        $flags = [];

        if ($amount >= self::BLOCK_AMOUNT) {
            $flags[] = 'block_threshold_exceeded';
        }

        if ($amount >= self::HIGH_RISK_AMOUNT) {
            $flags[] = 'high_risk_amount';
        }

        $score = $this->calculateScore(['flag' => count($flags)], ['flag' => 0.3]);

        return $this->createResult($this->determineAction($score), $score, $flags);
    }
}

final class VelocityRule extends BaseComplianceRule
{
    private const BURST_COUNT = 5;

    public function getRuleName(): string
    {
        return 'velocity';
    }

    public function getSeverity(): string
    {
        return 'medium';
    }

    public function evaluate(mixed $context): ComplianceResult
    {
        $count = $context->getRecentTransactionCount();
        $flags = [];

        if ($count >= self::BURST_COUNT) {
            $flags[] = 'velocity_burst';
        }

        $score = $this->calculateScore(['burst' => count($flags)], ['burst' => 0.25]);

        return $this->createResult($this->determineAction($score), $score, $flags);
    }
}

final class UnifiedComplianceEngine
{
    private array $rules = [];

    public function addRule(ComplianceRule $rule): void
    {
        $this->rules[] = $rule;
    }

    public function evaluate(mixed $context): array
    {
        $results = [];

        foreach ($this->rules as $rule) {
            $results[] = $rule->evaluate($context);
        }

        return $results;
    }
}
