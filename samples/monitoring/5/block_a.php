<?php

declare(strict_types=1);

namespace App\Alerting;

class AlertConditionEvaluator
{
    private AlertRepository $repository;
    private MetricsClient $metrics;
    private NotificationService $notifications;
    private LoggerInterface $logger;

    public function __construct(
        AlertRepository $repository,
        MetricsClient $metrics,
        NotificationService $notifications,
        LoggerInterface $logger
    ) {
        $this->repository = $repository;
        $this->metrics = $metrics;
        $this->notifications = $notifications;
        $this->logger = $logger;
    }

    public function evaluateAllAlertConditions(): array
    {
        $alerts = [];
        $activeConditions = $this->repository->getActiveConditions();

        foreach ($activeConditions as $condition) {
            $result = $this->evaluateCondition($condition);

            if ($result->isTriggered()) {
                $alerts[] = $result;

                $this->handleTriggeredAlert($result, $condition);
            }
        }

        return $alerts;
    }

    public function evaluateCondition(AlertCondition $condition): AlertEvaluation
    {
        $currentValue = $this->metrics->getCurrentValue(
            $condition->getMetric(),
            $condition->getLabels()
        );

        $threshold = $condition->getThreshold();
        $operator = $condition->getOperator();

        $isTriggered = $this->checkThreshold($currentValue, $operator, $threshold);

        $duration = $this->getCurrentDuration($condition);

        if ($isTriggered && $duration >= $condition->getMinDuration()) {
            return new AlertEvaluation(
                triggered: true,
                metric: $condition->getMetric(),
                currentValue: $currentValue,
                threshold: $threshold,
                operator: $operator,
                duration: $duration,
                message: $this->buildAlertMessage($condition, $currentValue, $threshold)
            );
        }

        return new AlertEvaluation(
            triggered: false,
            metric: $condition->getMetric(),
            currentValue: $currentValue,
            threshold: $threshold,
            operator: $operator,
            duration: $duration
        );
    }

    private function checkThreshold($value, string $operator, $threshold): bool
    {
        return match($operator) {
            '>' => $value > $threshold,
            '<' => $value < $threshold,
            '>=' => $value >= $threshold,
            '<=' => $value <= $threshold,
            '==' => $value == $threshold,
            '!=' => $value != $threshold,
            default => false
        };
    }

    private function getCurrentDuration(AlertCondition $condition): int
    {
        $state = $this->repository->getConditionState($condition->getId());

        if ($state === null) {
            return 0;
        }

        if (!$state->isTriggered()) {
            return 0;
        }

        return time() - $state->getLastTriggeredAt();
    }

    private function buildAlertMessage(AlertCondition $condition, $currentValue, $threshold): string
    {
        return sprintf(
            '%s: %s %s %s (current: %s, duration: %ds)',
            $condition->getName(),
            $condition->getMetric(),
            $condition->getOperator(),
            $threshold,
            $currentValue,
            $this->getCurrentDuration($condition)
        );
    }

    private function handleTriggeredAlert(AlertEvaluation $evaluation, AlertCondition $condition): void
    {
        $this->logger->warning('Alert triggered', [
            'condition' => $condition->getName(),
            'value' => $evaluation->getCurrentValue(),
            'threshold' => $evaluation->getThreshold()
        ]);

        $this->repository->recordAlertFired($condition, $evaluation);

        $this->sendAlertNotifications($condition, $evaluation);

        if ($condition->getSeverity() === 'critical') {
            $this->triggerIncidentIfNeeded($condition, $evaluation);
        }
    }

    private function sendAlertNotifications(AlertCondition $condition, AlertEvaluation $evaluation): void
    {
        $channels = $condition->getNotificationChannels();

        foreach ($channels as $channel) {
            $this->notifications->send(
                $channel,
                $condition->getName(),
                $evaluation->getMessage(),
                [
                    'severity' => $condition->getSeverity(),
                    'metric' => $condition->getMetric(),
                    'value' => $evaluation->getCurrentValue()
                ]
            );
        }
    }

    private function triggerIncidentIfNeeded(AlertCondition $condition, AlertEvaluation $evaluation): void
    {
        $recentIncidents = $this->repository->getRecentIncidents(
            $condition->getId(),
            '+1 hour'
        );

        if (count($recentIncidents) >= 3) {
            $this->logger->critical('Alert storm detected', [
                'condition' => $condition->getName(),
                'incidents_count' => count($recentIncidents)
            ]);

            $this->notifications->sendToPagerDuty(
                'alert_storm',
                "Alert storm: {$condition->getName()}",
                $recentIncidents
            );
        }
    }

    public function evaluateHighErrorRate(string $service, int $windowMinutes = 5): AlertEvaluation
    {
        $condition = $this->repository->getConditionByName("high_error_rate_{$service}");

        if ($condition === null) {
            return new AlertEvaluation(triggered: false, metric: 'error_rate', currentValue: 0);
        }

        $errorCount = $this->metrics->getCounterValue(
            'errors_total',
            ['service' => $service],
            "-{$windowMinutes}minutes"
        );

        $totalCount = $this->metrics->getCounterValue(
            'requests_total',
            ['service' => $service],
            "-{$windowMinutes}minutes"
        );

        if ($totalCount === 0) {
            return new AlertEvaluation(triggered: false, metric: 'error_rate', currentValue: 0);
        }

        $errorRate = $errorCount / $totalCount;

        return new AlertEvaluation(
            triggered: $errorRate > $condition->getThreshold(),
            metric: 'error_rate',
            currentValue: $errorRate,
            threshold: $condition->getThreshold()
        );
    }

    public function evaluateSlowResponse(string $endpoint, int $p95ThresholdMs = 1000): AlertEvaluation
    {
        $recentLatencies = $this->metrics->getPercentileValues(
            'request_duration_ms',
            ['endpoint' => $endpoint],
            95,
            '-5minutes'
        );

        $p95Latency = $recentLatencies['p95'] ?? 0;

        return new AlertEvaluation(
            triggered: $p95Latency > $p95ThresholdMs,
            metric: 'request_duration_p95',
            currentValue: $p95Latency,
            threshold: $p95ThresholdMs,
            operator: '>'
        );
    }

    public function evaluateQuotaExceeded(string $userId, string $quotaType): AlertEvaluation
    {
        $currentUsage = $this->metrics->getGaugeValue(
            "quota_{$quotaType}_usage",
            ['user_id' => $userId]
        );

        $limit = $this->metrics->getGaugeValue(
            "quota_{$quotaType}_limit",
            ['user_id' => $userId]
        );

        if ($limit === 0) {
            return new AlertEvaluation(triggered: false, metric: 'quota_usage', currentValue: 0);
        }

        $usagePercent = ($currentUsage / $limit) * 100;

        $threshold = 80;

        return new AlertEvaluation(
            triggered: $usagePercent >= $threshold,
            metric: 'quota_usage_percent',
            currentValue: $usagePercent,
            threshold: $threshold
        );
    }

    public function evaluateHighLatency(string $service, int $thresholdMs = 500): AlertEvaluation
    {
        $recentLatencies = $this->metrics->getPercentileValues(
            'latency_ms',
            ['service' => $service],
            99,
            '-5minutes'
        );

        $p99Latency = $recentLatencies['p99'] ?? 0;

        return new AlertEvaluation(
            triggered: $p99Latency > $thresholdMs,
            metric: 'latency_p99',
            currentValue: $p99Latency,
            threshold: $thresholdMs,
            operator: '>'
        );
    }

    public function evaluateLowThroughput(string $service, int $minRpm = 100): AlertEvaluation
    {
        $throughput = $this->metrics->getCounterValue(
            'requests_total',
            ['service' => $service],
            '-1minute'
        );

        return new AlertEvaluation(
            triggered: $throughput < $minRpm,
            metric: 'throughput_rpm',
            currentValue: $throughput,
            threshold: $minRpm,
            operator: '<'
        );
    }

    public function evaluateDiskSpaceLow(string $path, int $thresholdPercent = 90): AlertEvaluation
    {
        $freeSpace = disk_free_space($path);
        $totalSpace = disk_total_space($path);

        if ($totalSpace === 0) {
            return new AlertEvaluation(triggered: false, metric: 'disk_space', currentValue: 0);
        }

        $usedPercent = (($totalSpace - $freeSpace) / $totalSpace) * 100;

        return new AlertEvaluation(
            triggered: $usedPercent >= $thresholdPercent,
            metric: 'disk_space_used_percent',
            currentValue: $usedPercent,
            threshold: $thresholdPercent,
            operator: '>='
        );
    }

    public function evaluateMemoryHigh(string $containerId, int $thresholdPercent = 85): AlertEvaluation
    {
        $memoryUsage = $this->metrics->getGaugeValue(
            'container_memory_usage_bytes',
            ['container_id' => $containerId]
        );

        $memoryLimit = $this->metrics->getGaugeValue(
            'container_memory_limit_bytes',
            ['container_id' => $containerId]
        );

        if ($memoryLimit === 0) {
            return new AlertEvaluation(triggered: false, metric: 'memory_usage', currentValue: 0);
        }

        $usagePercent = ($memoryUsage / $memoryLimit) * 100;

        return new AlertEvaluation(
            triggered: $usagePercent >= $thresholdPercent,
            metric: 'memory_usage_percent',
            currentValue: $usagePercent,
            threshold: $thresholdPercent,
            operator: '>='
        );
    }
}
