<?php

declare(strict_types=1);

namespace App\Monitoring\Alerting;

class AlertOrchestrator
{
    private AlertRuleEngine $ruleEngine;
    private AlertNotifier $notifier;
    private AlertRepository $repository;
    private IncidentManager $incidents;
    private LoggerInterface $logger;

    public function __construct(
        AlertRuleEngine $ruleEngine,
        AlertNotifier $notifier,
        AlertRepository $repository,
        IncidentManager $incidents,
        LoggerInterface $logger
    ) {
        $this->ruleEngine = $ruleEngine;
        $this->notifier = $notifier;
        $this->repository = $repository;
        $this->incidents = $incidents;
        $this->logger = $logger;
    }

    public function processAlertCycle(): AlertCycleResult
    {
        $startTime = microtime(true);
        $firedAlerts = [];
        $resolvedAlerts = [];

        $activeAlerts = $this->repository->getActiveAlerts();

        foreach ($activeAlerts as $alert) {
            $evaluation = $this->evaluateAlertRule($alert->getRule());

            if (!$evaluation->isHealthy()) {
                if (!$alert->isFiring()) {
                    $this->fireAlert($alert, $evaluation);
                    $firedAlerts[] = $alert;
                }
            } else {
                if ($alert->isFiring()) {
                    $this->resolveAlert($alert);
                    $resolvedAlerts[] = $alert;
                }
            }
        }

        $this->processPendingAlerts();

        $duration = round((microtime(true) - $startTime) * 1000, 2);

        return new AlertCycleResult(
            firedAlerts: $firedAlerts,
            resolvedAlerts: $resolvedAlerts,
            processingTimeMs: $duration
        );
    }

    private function evaluateAlertRule(AlertRule $rule): AlertEvaluationResult
    {
        $metrics = $this->ruleEngine->collectMetrics(
            $rule->getMetric(),
            $rule->getLabels(),
            $rule->getEvaluationWindow()
        );

        $currentValue = $this->calculateMetricValue($metrics, $rule->getAggregation());

        $threshold = $rule->getThreshold();
        $operator = $rule->getOperator();

        $isHealthy = $this->isWithinThreshold($currentValue, $operator, $threshold);

        return new AlertEvaluationResult(
            isHealthy: $isHealthy,
            currentValue: $currentValue,
            threshold: $threshold,
            samplesCount: count($metrics)
        );
    }

    private function calculateMetricValue(array $metrics, string $aggregation): float
    {
        if (empty($metrics)) {
            return 0.0;
        }

        $values = array_column($metrics, 'value');

        return match($aggregation) {
            'avg' => array_sum($values) / count($values),
            'sum' => array_sum($values),
            'min' => min($values),
            'max' => max($values),
            'count' => count($values),
            'last' => end($values),
            'p95' => $this->percentile($values, 95),
            'p99' => $this->percentile($values, 99),
            default => array_sum($values) / count($values)
        };
    }

    private function percentile(array $values, int $percentile): float
    {
        sort($values);
        $index = ceil(($percentile / 100) * count($values)) - 1;

        return $values[max(0, $index)];
    }

    private function isWithinThreshold(float $value, string $operator, float $threshold): bool
    {
        return match($operator) {
            'gt', '>' => $value <= $threshold,
            'gte', '>=' => $value < $threshold,
            'lt', '<' => $value >= $threshold,
            'lte', '<=' => $value > $threshold,
            'eq', '==' => $value != $threshold,
            'neq', '!=' => $value == $threshold,
            default => true
        };
    }

    private function fireAlert(Alert $alert, AlertEvaluationResult $evaluation): void
    {
        $alert->setFiring(true);
        $alert->setFiredAt(time());
        $alert->setCurrentValue($evaluation->getCurrentValue());

        $this->repository->save($alert);

        $this->notifier->notify(
            $alert->getRule()->getNotificationChannels(),
            'alert firing',
            [
                'alert' => $alert->getName(),
                'value' => $evaluation->getCurrentValue(),
                'threshold' => $evaluation->getThreshold(),
                'message' => $alert->getRule()->getDescription()
            ]
        );

        $this->checkForAlertStorm($alert);

        $this->logger->warning('Alert fired', [
            'alert' => $alert->getName(),
            'value' => $evaluation->getCurrentValue(),
            'threshold' => $evaluation->getThreshold()
        ]);
    }

    private function resolveAlert(Alert $alert): void
    {
        $alert->setFiring(false);
        $alert->setResolvedAt(time());
        $alert->setLastFiredAt($alert->getFiredAt());

        $this->repository->save($alert);

        $this->notifier->notify(
            $alert->getRule()->getNotificationChannels(),
            'alert resolved',
            [
                'alert' => $alert->getName(),
                'duration_seconds' => $alert->getFiredAt() - time()
            ]
        );

        $this->logger->info('Alert resolved', [
            'alert' => $alert->getName()
        ]);
    }

    private function processPendingAlerts(): void
    {
        $pendingAlerts = $this->repository->getPendingAlerts();

        foreach ($pendingAlerts as $alert) {
            $this->evaluatePendingAlert($alert);
        }
    }

    private function evaluatePendingAlert(Alert $alert): void
    {
        $rule = $alert->getRule();
        $evaluation = $this->evaluateAlertRule($rule);

        if ($evaluation->isHealthy()) {
            $alert->setState('ok');
            $this->repository->save($alert);
        } else {
            $pendingDuration = time() - $alert->getCreatedAt();

            if ($pendingDuration >= $rule->getEvaluationWindow()) {
                $this->fireAlert($alert, $evaluation);
            }
        }
    }

    private function checkForAlertStorm(Alert $alert): void
    {
        $recentAlerts = $this->repository->getRecentAlerts(
            $alert->getRuleId(),
            '-15minutes'
        );

        if (count($recentAlerts) >= 5) {
            $this->logger->critical('Alert storm detected', [
                'alert' => $alert->getName(),
                'recent_count' => count($recentAlerts)
            ]);

            $this->incidents->createIncident(
                'alert_storm',
                $alert->getName(),
                [
                    'type' => 'alert_storm',
                    'alert' => $alert->getName(),
                    'recent_count' => count($recentAlerts)
                ]
            );

            $this->notifier->notifyUrgent(
                ['pagerduty', 'slack_critical'],
                'ALERT STORM',
                "Alert storm detected: {$alert->getName()} firing {count($recentAlerts)} times in 15 minutes"
            );
        }
    }

    public function evaluateHighErrorRateAlert(
        string $service,
        int $windowMinutes,
        float $threshold
    ): AlertEvaluationResult {
        $errors = $this->repository->getMetricSum(
            'errors_total',
            ['service' => $service],
            "-{$windowMinutes}minutes"
        );

        $total = $this->repository->getMetricSum(
            'requests_total',
            ['service' => $service],
            "-{$windowMinutes}minutes"
        );

        if ($total === 0) {
            return new AlertEvaluationResult(isHealthy: true, currentValue: 0, threshold: $threshold);
        }

        $errorRate = $errors / $total;

        return new AlertEvaluationResult(
            isHealthy: $errorRate <= $threshold,
            currentValue: $errorRate,
            threshold: $threshold
        );
    }

    public function evaluateSlowResponseAlert(
        string $endpoint,
        int $p99ThresholdMs
    ): AlertEvaluationResult {
        $latencies = $this->repository->getMetricPercentile(
            'request_duration_ms',
            ['endpoint' => $endpoint],
            99,
            '-5minutes'
        );

        $p99Latency = $latencies['p99'] ?? 0;

        return new AlertEvaluationResult(
            isHealthy: $p99Latency <= $p99ThresholdMs,
            currentValue: $p99Latency,
            threshold: $p99ThresholdMs
        );
    }

    public function evaluateQuotaAlert(
        string $userId,
        string $quotaType,
        float $threshold
    ): AlertEvaluationResult {
        $usage = $this->repository->getMetricGauge(
            "quota_{$quotaType}_usage",
            ['user_id' => $userId]
        );

        $limit = $this->repository->getMetricGauge(
            "quota_{$quotaType}_limit",
            ['user_id' => $userId]
        );

        if ($limit === 0) {
            return new AlertEvaluationResult(isHealthy: true, currentValue: 0, threshold: $threshold);
        }

        $usagePercent = ($usage / $limit) * 100;

        return new AlertEvaluationResult(
            isHealthy: $usagePercent < $threshold,
            currentValue: $usagePercent,
            threshold: $threshold
        );
    }

    public function evaluateCapacityAlert(
        string $resource,
        int $thresholdPercent
    ): AlertEvaluationResult {
        $used = $this->repository->getMetricGauge(
            "capacity_{$resource}_used",
            []
        );

        $total = $this->repository->getMetricGauge(
            "capacity_{$resource}_total",
            []
        );

        if ($total === 0) {
            return new AlertEvaluationResult(isHealthy: true, currentValue: 0, threshold: $thresholdPercent);
        }

        $utilization = ($used / $total) * 100;

        return new AlertEvaluationResult(
            isHealthy: $utilization < $thresholdPercent,
            currentValue: $utilization,
            threshold: $thresholdPercent
        );
    }
}

class AlertRuleEngine
{
    public function collectMetrics(
        string $metric,
        array $labels,
        string $window
    ): array {
        return [];
    }
}

class AlertNotifier
{
    public function notify(array $channels, string $subject, array $data): void
    {
    }

    public function notifyUrgent(array $channels, string $subject, string $message): void
    {
    }
}

class IncidentManager
{
    public function createIncident(string $type, string $name, array $data): void
    {
    }
}

class AlertRepository
{
    public function getActiveAlerts(): array
    {
        return [];
    }

    public function getPendingAlerts(): array
    {
        return [];
    }

    public function getRecentAlerts(string $ruleId, string $window): array
    {
        return [];
    }

    public function save(Alert $alert): void
    {
    }

    public function getMetricSum(string $metric, array $labels, string $window): float
    {
        return 0.0;
    }

    public function getMetricGauge(string $metric, array $labels): float
    {
        return 0.0;
    }

    public function getMetricPercentile(string $metric, array $labels, int $percentile, string $window): array
    {
        return [];
    }
}
