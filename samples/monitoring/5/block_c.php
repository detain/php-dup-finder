<?php

declare(strict_types=1);

namespace App\Monitoring\SRE;

class SREAlertManager
{
    private SLORepository $sloRepository;
    private ErrorBudgetCalculator $errorBudget;
    private AlertPublisher $publisher;
    private LoggerInterface $logger;

    public function __construct(
        SLORepository $sloRepository,
        ErrorBudgetCalculator $errorBudget,
        AlertPublisher $publisher,
        LoggerInterface $logger
    ) {
        $this->sloRepository = $sloRepository;
        $this->errorBudget = $errorBudget;
        $this->publisher = $publisher;
        $this->logger = $logger;
    }

    public function evaluateAllSLOs(): array
    {
        $slos = $this->sloRepository->getAllSLOs();
        $alerts = [];

        foreach ($slos as $slo) {
            $alert = $this->evaluateSLO($slo);

            if ($alert !== null) {
                $alerts[] = $alert;
            }
        }

        return $alerts;
    }

    public function evaluateSLO(ServiceLevelObjective $slo): ?SLOAlert
    {
        $currentMetrics = $this->collectSLOMetrics($slo);

        $goodEvents = $currentMetrics['good_events'];
        $totalEvents = $currentMetrics['total_events'];

        if ($totalEvents === 0) {
            return null;
        }

        $currentGoodRatio = $goodEvents / $totalEvents;
        $targetGoodRatio = $slo->getTarget();

        $burnRate = $this->errorBudget->calculateBurnRate(
            $currentGoodRatio,
            $targetGoodRatio,
            $slo->getWindow()
        );

        $errorBudgetRemaining = $this->errorBudget->calculateRemainingBudget(
            $slo->getTarget(),
            $currentGoodRatio,
            $totalEvents,
            $slo->getWindow()
        );

        $alert = $this->checkSLOAlerts($slo, $burnRate, $errorBudgetRemaining);

        if ($alert !== null) {
            $this->publisher->publish($alert);
        }

        return $alert;
    }

    private function collectSLOMetrics(ServiceLevelObjective $slo): array
    {
        $window = $slo->getWindow();

        $goodEvents = $this->queryGoodEvents(
            $slo->getMetricName(),
            $slo->getFilterLabels(),
            "-{$window}"
        );

        $totalEvents = $this->queryTotalEvents(
            $slo->getMetricName(),
            $slo->getFilterLabels(),
            "-{$window}"
        );

        return [
            'good_events' => $goodEvents,
            'total_events' => $totalEvents
        ];
    }

    private function queryGoodEvents(string $metric, array $labels, string $window): int
    {
        $query = "SELECT SUM(value) FROM {$metric}_good WHERE " .
            $this->buildLabelFilter($labels) .
            " AND time > now() - interval '{$window}'";

        return (int)$this->database->query($query)->fetchColumn();
    }

    private function queryTotalEvents(string $metric, array $labels, string $window): int
    {
        $query = "SELECT SUM(value) FROM {$metric}_total WHERE " .
            $this->buildLabelFilter($labels) .
            " AND time > now() - interval '{$window}'";

        return (int)$this->database->query($query)->fetchColumn();
    }

    private function buildLabelFilter(array $labels): string
    {
        $conditions = [];

        foreach ($labels as $key => $value) {
            $conditions[] = "labels->>'{$key}' = '{$value}'";
        }

        return implode(' AND ', $conditions);
    }

    private function checkSLOAlerts(
        ServiceLevelObjective $slo,
        float $burnRate,
        float $errorBudgetRemaining
    ): ?SLOAlert {
        if ($burnRate >= 14.4) {
            return $this->createSLOAlert(
                $slo,
                'burn_rate_critical',
                'Error budget burning extremely fast',
                [
                    'burn_rate' => $burnRate,
                    'error_budget_remaining' => $errorBudgetRemaining,
                    'action' => 'open incident immediately'
                ]
            );
        }

        if ($burnRate >= 6) {
            return $this->createSLOAlert(
                $slo,
                'burn_rate_high',
                'Error budget burning fast',
                [
                    'burn_rate' => $burnRate,
                    'error_budget_remaining' => $errorBudgetRemaining,
                    'action' => 'start investigating'
                ]
            );
        }

        if ($errorBudgetRemaining < 0) {
            return $this->createSLOAlert(
                $slo,
                'budget_exhausted',
                'Error budget fully exhausted',
                [
                    'error_budget_remaining' => $errorBudgetRemaining,
                    'action' => 'error budget exhausted - urgent action required'
                ]
            );
        }

        if ($errorBudgetRemaining < 0.1) {
            return $this->createSLOAlert(
                $slo,
                'budget_critical',
                'Error budget critically low',
                [
                    'error_budget_remaining' => $errorBudgetRemaining,
                    'action' => 'error budget nearly exhausted'
                ]
            );
        }

        return null;
    }

    private function createSLOAlert(
        ServiceLevelObjective $slo,
        string $alertName,
        string $message,
        array $metadata
    ): SLOAlert {
        return new SLOAlert(
            slo: $slo->getName(),
            alertName: $alertName,
            message: $message,
            metadata: $metadata,
            severity: $this->determineSeverity($alertName),
            firedAt: time()
        );
    }

    private function determineSeverity(string $alertName): string
    {
        return match($alertName) {
            'burn_rate_critical', 'budget_exhausted' => 'critical',
            'burn_rate_high', 'budget_critical' => 'warning',
            default => 'info'
        };
    }

    public function evaluateHighErrorRateAlert(string $serviceName): ?SLOAlert
    {
        $slo = $this->sloRepository->getByName("{$serviceName}_availability");

        if ($slo === null) {
            return null;
        }

        $metrics = $this->collectSLOMetrics($slo);

        $errorRate = 1 - ($metrics['good_events'] / max(1, $metrics['total_events']));

        if ($errorRate > 0.01) {
            return $this->createSLOAlert(
                $slo,
                'high_error_rate',
                "High error rate detected: " . ($errorRate * 100) . "%",
                [
                    'error_rate' => $errorRate,
                    'threshold' => 0.01
                ]
            );
        }

        return null;
    }

    public function evaluateSlowLatencyAlert(string $serviceName): ?SLOAlert
    {
        $latencySlo = $this->sloRepository->getByName("{$serviceName}_latency");

        if ($latencySlo === null) {
            return null;
        }

        $p99Latency = $this->queryLatency($latencySlo, 99);
        $threshold = $latencySlo->getMetadata()['threshold_ms'] ?? 1000;

        if ($p99Latency > $threshold) {
            return $this->createSLOAlert(
                $latencySlo,
                'slow_latency',
                "P99 latency above threshold: {$p99Latency}ms > {$threshold}ms",
                [
                    'p99_latency' => $p99Latency,
                    'threshold' => $threshold
                ]
            );
        }

        return null;
    }

    private function queryLatency(ServiceLevelObjective $slo, int $percentile): float
    {
        $query = "SELECT percentile_cont({$percentile}/100) " .
            "WITHIN GROUP (ORDER BY value) " .
            "FROM {$slo->getMetricName()} " .
            "WHERE time > now() - interval '5 minutes'";

        return (float)$this->database->query($query)->fetchColumn();
    }

    public function evaluateQuotaExceededAlert(string $userId, string $quotaType): ?SLOAlert
    {
        $usage = $this->getQuotaUsage($userId, $quotaType);
        $limit = $this->getQuotaLimit($userId, $quotaType);

        if ($limit === 0) {
            return null;
        }

        $utilization = ($usage / $limit) * 100;

        if ($utilization >= 100) {
            return new SLOAlert(
                slo: "{$quotaType}_quota",
                alertName: 'quota_exceeded',
                message: "Quota exceeded for user {$userId}",
                metadata: [
                    'user_id' => $userId,
                    'quota_type' => $quotaType,
                    'usage' => $usage,
                    'limit' => $limit,
                    'utilization_percent' => $utilization
                ],
                severity: 'critical',
                firedAt: time()
            );
        }

        if ($utilization >= 80) {
            return new SLOAlert(
                slo: "{$quotaType}_quota",
                alertName: 'quota_warning',
                message: "Quota nearing limit for user {$userId}",
                metadata: [
                    'user_id' => $userId,
                    'quota_type' => $quotaType,
                    'usage' => $usage,
                    'limit' => $limit,
                    'utilization_percent' => $utilization
                ],
                severity: 'warning',
                firedAt: time()
            );
        }

        return null;
    }

    private function getQuotaUsage(string $userId, string $quotaType): int
    {
        return 0;
    }

    private function getQuotaLimit(string $userId, string $quotaType): int
    {
        return 1000;
    }
}
