<?php

declare(strict_types=1);

namespace App\Monitoring\Core;

interface AlertConditionInterface
{
    public function getName(): string;
    public function getMetric(): string;
    public function getThreshold(): float;
    public function getOperator(): string;
    public function evaluate(MetricsCollector $metrics): AlertEvaluationResult;
}

abstract class AbstractAlertCondition implements AlertConditionInterface
{
    protected string $name;
    protected string $metric;
    protected float $threshold;
    protected string $operator;

    public function evaluate(MetricsCollector $metrics): AlertEvaluationResult
    {
        $value = $metrics->getValue($this->metric, $this->getLabels(), $this->getWindow());

        $isTriggered = $this->checkThreshold($value);

        return new AlertEvaluationResult(
            triggered: $isTriggered,
            value: $value,
            threshold: $this->threshold,
            metric: $this->metric
        );
    }

    protected function checkThreshold(float $value): bool
    {
        return match($this->operator) {
            '>' => $value > $this->threshold,
            '>=' => $value >= $this->threshold,
            '<' => $value < $this->threshold,
            '<=' => $value <= $this->threshold,
            '==' => $value == $this->threshold,
            default => false
        };
    }

    abstract protected function getLabels(): array;
    abstract protected function getWindow(): string;
}

class HighErrorRateCondition extends AbstractAlertCondition
{
    private string $service;

    public function __construct(string $service, float $threshold = 0.05)
    {
        $this->service = $service;
        $this->name = "high_error_rate_{$service}";
        $this->metric = 'error_rate';
        $this->threshold = $threshold;
        $this->operator = '>';
    }

    protected function getLabels(): array
    {
        return ['service' => $this->service];
    }

    protected function getWindow(): string
    {
        return '-5minutes';
    }
}

class SlowResponseCondition extends AbstractAlertCondition
{
    private string $endpoint;
    private int $p99ThresholdMs;

    public function __construct(string $endpoint, int $p99ThresholdMs = 1000)
    {
        $this->endpoint = $endpoint;
        $this->p99ThresholdMs = $p99ThresholdMs;
        $this->name = "slow_response_{$endpoint}";
        $this->metric = 'latency_p99';
        $this->threshold = $p99ThresholdMs;
        $this->operator = '>';
    }

    protected function getLabels(): array
    {
        return ['endpoint' => $this->endpoint];
    }

    protected function getWindow(): string
    {
        return '-5minutes';
    }
}

class QuotaExceededCondition extends AbstractAlertCondition
{
    private string $userId;
    private string $quotaType;
    private float $warningThreshold;

    public function __construct(string $userId, string $quotaType, float $warningThreshold = 80)
    {
        $this->userId = $userId;
        $this->quotaType = $quotaType;
        $this->warningThreshold = $warningThreshold;
        $this->name = "quota_{$quotaType}_{$userId}";
        $this->metric = "quota_{$quotaType}_usage_percent";
        $this->threshold = $warningThreshold;
        $this->operator = '>=';
    }

    protected function getLabels(): array
    {
        return ['user_id' => $this->userId, 'quota_type' => $this->quotaType];
    }

    protected function getWindow(): string
    {
        return '-1minute';
    }
}

class UnifiedAlertManager
{
    private array $conditions = [];
    private MetricsCollector $metrics;
    private AlertNotifier $notifier;

    public function registerCondition(AlertConditionInterface $condition): void
    {
        $this->conditions[$condition->getName()] = $condition;
    }

    public function evaluateAll(): array
    {
        $triggered = [];

        foreach ($this->conditions as $condition) {
            $result = $condition->evaluate($this->metrics);

            if ($result->isTriggered()) {
                $triggered[] = $this->createAlert($condition, $result);
            }
        }

        return $triggered;
    }

    private function createAlert(AlertConditionInterface $condition, AlertEvaluationResult $result): Alert
    {
        return new Alert(
            name: $condition->getName(),
            metric: $condition->getMetric(),
            value: $result->getValue(),
            threshold: $result->getThreshold()
        );
    }
}
