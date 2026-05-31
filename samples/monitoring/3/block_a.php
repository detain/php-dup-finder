<?php

declare(strict_types=1);

namespace App\Performance;

class PerformanceMonitor
{
    private PrometheusClient $prometheus;
    private LoggerInterface $logger;
    private array $activeTimers = [];

    public function __construct(PrometheusClient $prometheus, LoggerInterface $logger)
    {
        $this->prometheus = $prometheus;
        $this->logger = $logger;
    }

    public function recordCacheOperation(
        string $operation,
        string $key,
        bool $hit,
        float $durationMs,
        string $cacheTier = 'memory'
    ): void {
        $labels = [
            'operation' => $operation,
            'tier' => $cacheTier,
            'hit' => $hit ? 'true' : 'false'
        ];

        $this->prometheus->incrementCounter(
            'cache_operations_total',
            'Total cache operations',
            1,
            $labels
        );

        $this->prometheus->recordHistogram(
            'cache_operation_duration_seconds',
            'Cache operation duration',
            $durationMs / 1000,
            $labels
        );

        if ($hit) {
            $this->prometheus->incrementCounter(
                'cache_hits_total',
                'Total cache hits',
                1,
                ['operation' => $operation, 'tier' => $cacheTier]
            );
        } else {
            $this->prometheus->incrementCounter(
                'cache_misses_total',
                'Total cache misses',
                1,
                ['operation' => $operation, 'tier' => $cacheTier]
            );
        }

        $this->recordCacheHitRate($operation, $cacheTier);

        if ($durationMs > 100) {
            $this->logger->warning('Slow cache operation detected', [
                'operation' => $operation,
                'duration_ms' => $durationMs,
                'hit' => $hit
            ]);
        }
    }

    private function recordCacheHitRate(string $operation, string $cacheTier): void
    {
        $hits = $this->prometheus->getCounterValue(
            'cache_hits_total',
            ['operation' => $operation, 'tier' => $cacheTier]
        );

        $misses = $this->prometheus->getCounterValue(
            'cache_misses_total',
            ['operation' => $operation, 'tier' => $cacheTier]
        );

        $total = $hits + $misses;

        if ($total > 0) {
            $hitRate = ($hits / $total) * 100;

            $this->prometheus->recordGauge(
                'cache_hit_rate_percent',
                'Cache hit rate percentage',
                (int)$hitRate,
                ['operation' => $operation, 'tier' => $cacheTier]
            );
        }
    }

    public function recordDatabaseQuery(
        string $queryType,
        string $table,
        float $durationMs,
        int $rowsAffected,
        bool $success,
        ?string $error = null
    ): void {
        $labels = [
            'query_type' => $queryType,
            'table' => $table,
            'success' => $success ? 'true' : 'false'
        ];

        $this->prometheus->incrementCounter(
            'database_queries_total',
            'Total database queries',
            1,
            $labels
        );

        $this->prometheus->recordHistogram(
            'database_query_duration_seconds',
            'Database query duration',
            $durationMs / 1000,
            $labels
        );

        if ($rowsAffected > 0) {
            $this->prometheus->recordHistogram(
                'database_rows_affected',
                'Rows affected by query',
                (float)$rowsAffected,
                $labels
            );
        }

        if (!$success && $error !== null) {
            $this->recordDatabaseError($queryType, $table, $error);
        }

        $this->recordQueryThroughput($queryType, $table);

        if ($durationMs > 1000) {
            $this->logger->warning('Slow query detected', [
                'query_type' => $queryType,
                'table' => $table,
                'duration_ms' => $durationMs
            ]);
        }
    }

    private function recordDatabaseError(string $queryType, string $table, string $error): void
    {
        $labels = [
            'query_type' => $queryType,
            'table' => $table,
            'error_type' => $this->classifyDatabaseError($error)
        ];

        $this->prometheus->incrementCounter(
            'database_errors_total',
            'Total database errors',
            1,
            $labels
        );
    }

    private function classifyDatabaseError(string $error): string
    {
        $error = strtolower($error);

        if (str_contains($error, 'timeout')) {
            return 'timeout';
        }

        if (str_contains($error, 'deadlock')) {
            return 'deadlock';
        }

        if (str_contains($error, 'connection')) {
            return 'connection_error';
        }

        if (str_contains($error, 'constraint')) {
            return 'constraint_violation';
        }

        if (str_contains($error, 'syntax')) {
            return 'syntax_error';
        }

        return 'unknown_error';
    }

    private function recordQueryThroughput(string $queryType, string $table): void
    {
        $this->prometheus->incrementCounter(
            'database_query_throughput',
            'Query throughput',
            1,
            ['query_type' => $queryType, 'table' => $table]
        );
    }

    public function recordExternalApiCall(
        string $service,
        string $endpoint,
        string $method,
        float $durationMs,
        int $statusCode,
        bool $success,
        ?string $error = null
    ): void {
        $labels = [
            'service' => $service,
            'endpoint' => $endpoint,
            'method' => $method,
            'status_code' => (string)$statusCode,
            'success' => $success ? 'true' : 'false'
        ];

        $this->prometheus->incrementCounter(
            'external_api_calls_total',
            'Total external API calls',
            1,
            $labels
        );

        $this->prometheus->recordHistogram(
            'external_api_call_duration_seconds',
            'External API call duration',
            $durationMs / 1000,
            $labels
        );

        if (!$success && $error !== null) {
            $this->recordApiError($service, $endpoint, $error);
        }

        $this->recordApiAvailability($service);

        if ($durationMs > 5000) {
            $this->logger->warning('Slow API call detected', [
                'service' => $service,
                'endpoint' => $endpoint,
                'duration_ms' => $durationMs
            ]);
        }
    }

    private function recordApiError(string $service, string $endpoint, string $error): void
    {
        $labels = [
            'service' => $service,
            'endpoint' => $endpoint,
            'error_type' => $this->classifyApiError($error)
        ];

        $this->prometheus->incrementCounter(
            'external_api_errors_total',
            'Total external API errors',
            1,
            $labels
        );
    }

    private function classifyApiError(string $error): string
    {
        $error = strtolower($error);

        if (str_contains($error, 'timeout')) {
            return 'timeout';
        }

        if (str_contains($error, '401') || str_contains($error, 'unauthorized')) {
            return 'authentication_error';
        }

        if (str_contains($error, '403') || str_contains($error, 'forbidden')) {
            return 'authorization_error';
        }

        if (str_contains($error, '429') || str_contains($error, 'rate limit')) {
            return 'rate_limit';
        }

        if (str_contains($error, '500') || str_contains($error, 'server error')) {
            return 'server_error';
        }

        if (str_contains($error, 'connection')) {
            return 'connection_error';
        }

        return 'unknown_error';
    }

    private function recordApiAvailability(string $service): void
    {
        $this->prometheus->recordGauge(
            'api_availability_percent',
            'API availability percentage',
            1,
            ['service' => $service]
        );
    }

    public function startTimer(string $timerId, array $labels = []): void
    {
        $this->activeTimers[$timerId] = [
            'start_time' => microtime(true),
            'labels' => $labels
        ];
    }

    public function endTimer(string $timerId): float
    {
        if (!isset($this->activeTimers[$timerId])) {
            return 0.0;
        }

        $startTime = $this->activeTimers[$timerId]['start_time'];
        $labels = $this->activeTimers[$timerId]['labels'];

        $duration = (microtime(true) - $startTime) * 1000;

        if (isset($labels['metric_name'])) {
            $this->prometheus->recordHistogram(
                $labels['metric_name'],
                'Timer duration',
                $duration / 1000,
                $labels
            );
        }

        unset($this->activeTimers[$timerId]);

        return $duration;
    }

    public function recordQueueDepth(string $queueName, int $depth): void
    {
        $this->prometheus->recordGauge(
            'queue_depth',
            'Number of messages in queue',
            $depth,
            ['queue' => $queueName]
        );

        if ($depth > 1000) {
            $this->prometheus->incrementCounter(
                'queue_backlog_alerts_total',
                'Queue backlog alerts',
                1,
                ['queue' => $queueName]
            );
        }
    }

    public function recordWorkerUtilization(string $workerPool, float $utilizationPercent): void
    {
        $this->prometheus->recordGauge(
            'worker_utilization_percent',
            'Worker utilization percentage',
            (int)$utilizationPercent,
            ['pool' => $workerPool]
        );

        if ($utilizationPercent > 90) {
            $this->prometheus->incrementCounter(
                'worker_capacity_alerts_total',
                'Worker capacity alerts',
                1,
                ['pool' => $workerPool]
            );
        }
    }
}
