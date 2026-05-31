<?php

declare(strict_types=1);

namespace App\Application;

class ApplicationMetricsService
{
    private MetricsAggregator $aggregator;
    private AlertThresholdChecker $thresholds;
    private LoggerInterface $logger;

    public function __construct(
        MetricsAggregator $aggregator,
        AlertThresholdChecker $thresholds,
        LoggerInterface $logger
    ) {
        $this->aggregator = $aggregator;
        $this->thresholds = $thresholds;
        $this->logger = $logger;
    }

    public function recordHttpRequest(
        string $method,
        string $path,
        int $statusCode,
        float $durationMs,
        int $responseSize,
        ?string $userId = null
    ): void {
        $labels = [
            'method' => $method,
            'path' => $this->normalizePath($path),
            'status_code' => (string)$statusCode
        ];

        $this->aggregator->incrementCounter('http_requests_total', 1, $labels);

        $this->aggregator->recordHistogram('http_request_duration_seconds', $durationMs / 1000, $labels);

        $this->aggregator->recordHistogram('http_response_size_bytes', (float)$responseSize, $labels);

        $this->checkHttpThresholds($method, $path, $statusCode, $durationMs);

        if ($durationMs > 1000) {
            $this->aggregator->incrementCounter('http_slow_requests_total', 1, $labels);
        }

        if ($statusCode >= 500) {
            $this->aggregator->incrementCounter('http_server_errors_total', 1, $labels);
        }

        if ($statusCode >= 400 && $statusCode < 500) {
            $this->aggregator->incrementCounter('http_client_errors_total', 1, $labels);
        }
    }

    private function normalizePath(string $path): string
    {
        $path = preg_replace('/\/\d+/', '/:id', $path);

        $path = preg_replace('/\/[a-f0-9-]{36}/i', '/:uuid', $path);

        return $path;
    }

    private function checkHttpThresholds(
        string $method,
        string $path,
        int $statusCode,
        float $durationMs
    ): void {
        if ($this->thresholds->isExceeded('http_latency_p99', $durationMs / 1000)) {
            $this->aggregator->incrementCounter('http_latency_alerts_total', 1, [
                'type' => 'slow_request',
                'path' => $path
            ]);
        }

        if ($statusCode >= 500) {
            $this->aggregator->incrementCounter('http_error_rate_alerts_total', 1, [
                'type' => 'server_error'
            ]);
        }
    }

    public function recordDatabaseOperation(
        string $operation,
        string $table,
        float $durationMs,
        int $rows,
        bool $success
    ): void {
        $labels = [
            'operation' => $operation,
            'table' => $table,
            'success' => $success ? 'true' : 'false'
        ];

        $this->aggregator->incrementCounter('db_operations_total', 1, $labels);

        $this->aggregator->recordHistogram('db_operation_duration_seconds', $durationMs / 1000, $labels);

        $this->aggregator->recordHistogram('db_operation_rows', (float)$rows, $labels);

        if (!$success) {
            $this->aggregator->incrementCounter('db_errors_total', 1, $labels);
        }

        $this->checkDatabaseThresholds($operation, $table, $durationMs);
    }

    private function checkDatabaseThresholds(
        string $operation,
        string $table,
        float $durationMs
    ): void {
        if ($durationMs > 100) {
            $this->aggregator->incrementCounter('db_slow_query_alerts_total', 1, [
                'operation' => $operation,
                'table' => $table
            ]);
        }
    }

    public function recordCacheAccess(
        string $cacheType,
        string $operation,
        bool $hit,
        float $durationMs
    ): void {
        $labels = [
            'cache_type' => $cacheType,
            'operation' => $operation,
            'hit' => $hit ? 'true' : 'false'
        ];

        $this->aggregator->incrementCounter('cache_access_total', 1, $labels);

        $this->aggregator->recordHistogram('cache_operation_duration_seconds', $durationMs / 1000, $labels);

        $hitLabels = array_merge($labels, ['result' => $hit ? 'hit' : 'miss']);
        $this->aggregator->incrementCounter('cache_hits_total', $hit ? 1 : 0, $hitLabels);
        $this->aggregator->incrementCounter('cache_misses_total', $hit ? 0 : 1, $hitLabels);
    }

    public function recordExternalServiceCall(
        string $service,
        string $endpoint,
        float $durationMs,
        int $statusCode,
        bool $success
    ): void {
        $labels = [
            'service' => $service,
            'endpoint' => $endpoint,
            'status_code' => (string)$statusCode,
            'success' => $success ? 'true' : 'false'
        ];

        $this->aggregator->incrementCounter('external_calls_total', 1, $labels);

        $this->aggregator->recordHistogram('external_call_duration_seconds', $durationMs / 1000, $labels);

        if (!$success) {
            $this->aggregator->incrementCounter('external_call_errors_total', 1, $labels);
        }

        $this->checkExternalServiceThresholds($service, $durationMs);
    }

    private function checkExternalServiceThresholds(string $service, float $durationMs): void
    {
        if ($durationMs > 5000) {
            $this->aggregator->incrementCounter('external_service_alerts_total', 1, [
                'type' => 'timeout',
                'service' => $service
            ]);
        }
    }

    public function recordMessageProcessing(
        string $queue,
        string $messageType,
        float $processingTimeMs,
        bool $success
    ): void {
        $labels = [
            'queue' => $queue,
            'message_type' => $messageType,
            'success' => $success ? 'true' : 'false'
        ];

        $this->aggregator->incrementCounter('message_processed_total', 1, $labels);

        $this->aggregator->recordHistogram('message_processing_duration_seconds', $processingTimeMs / 1000, $labels);

        if (!$success) {
            $this->aggregator->incrementCounter('message_processing_errors_total', 1, $labels);
        }

        $this->checkMessageQueueThresholds($queue);
    }

    private function checkMessageQueueThresholds(string $queue): void
    {
        $queueDepth = $this->aggregator->getLatestValue('queue_depth', ['queue' => $queue]);

        if ($queueDepth !== null && $queueDepth > 1000) {
            $this->aggregator->incrementCounter('message_queue_alerts_total', 1, [
                'type' => 'backlog',
                'queue' => $queue
            ]);
        }
    }

    public function recordAuthentication(
        string $method,
        string $status,
        bool $success,
        ?string $userId = null
    ): void {
        $labels = [
            'method' => $method,
            'status' => $status,
            'success' => $success ? 'true' : 'false'
        ];

        $this->aggregator->incrementCounter('auth_attempts_total', 1, $labels);

        if ($success) {
            $this->aggregator->incrementCounter('auth_success_total', 1, ['method' => $method]);
        } else {
            $this->aggregator->incrementCounter('auth_failures_total', 1, $labels);
        }
    }

    public function recordFileUpload(
        string $fileType,
        int $fileSizeBytes,
        float $uploadDurationMs,
        bool $success
    ): void {
        $labels = [
            'file_type' => $fileType,
            'success' => $success ? 'true' : 'false'
        ];

        $this->aggregator->incrementCounter('file_uploads_total', 1, $labels);

        $this->aggregator->recordHistogram('file_upload_size_bytes', (float)$fileSizeBytes, $labels);

        $this->aggregator->recordHistogram('file_upload_duration_seconds', $uploadDurationMs / 1000, $labels);

        if ($fileSizeBytes > 10 * 1024 * 1024) {
            $this->aggregator->incrementCounter('file_upload_alerts_total', 1, [
                'type' => 'large_file',
                'file_type' => $fileType
            ]);
        }
    }

    public function recordSearchQuery(
        string $searchType,
        string $query,
        int $resultsCount,
        float $searchDurationMs,
        ?string $userId = null
    ): void {
        $labels = [
            'search_type' => $searchType,
            'has_results' => $resultsCount > 0 ? 'true' : 'false'
        ];

        $this->aggregator->incrementCounter('search_queries_total', 1, $labels);

        $this->aggregator->recordHistogram('search_duration_seconds', $searchDurationMs / 1000, $labels);

        $this->aggregator->recordHistogram('search_results_count', (float)$resultsCount, $labels);

        if ($searchDurationMs > 500) {
            $this->aggregator->incrementCounter('search_alerts_total', 1, [
                'type' => 'slow_search',
                'search_type' => $searchType
            ]);
        }
    }
}
