<?php

declare(strict_types=1);

namespace App\Api\Controllers;

class ApiMetricsCollector
{
    private MetricsClient $metricsClient;
    private LoggerInterface $logger;
    private array $requestLabels = [];

    public function __construct(MetricsClient $metricsClient, LoggerInterface $logger)
    {
        $this->metricsClient = $metricsClient;
        $this->logger = $logger;
    }

    public function recordRequestStarted(string $endpoint, string $method, string $requestId): void
    {
        $this->requestLabels = [
            'endpoint' => $endpoint,
            'method' => $method,
            'request_id' => $requestId
        ];

        $this->metricsClient->incrementCounter(
            'api_requests_total',
            'API request started',
            1,
            $this->requestLabels
        );

        $this->metricsClient->recordGauge(
            'api_requests_in_flight',
            'Requests currently being processed',
            1,
            $this->requestLabels
        );

        $this->logger->info('API request started', [
            'endpoint' => $endpoint,
            'method' => $method,
            'request_id' => $requestId
        ]);
    }

    public function recordRequestCompleted(
        string $endpoint,
        string $method,
        string $requestId,
        int $statusCode,
        float $durationMs,
        int $responseSize
    ): void {
        $labels = array_merge($this->requestLabels, [
            'status_code' => (string)$statusCode
        ]);

        $this->metricsClient->incrementCounter(
            'api_requests_completed_total',
            'API requests completed',
            1,
            $labels
        );

        $this->metricsClient->recordHistogram(
            'api_request_duration_seconds',
            'API request duration in seconds',
            $durationMs / 1000,
            $labels
        );

        $this->metricsClient->recordHistogram(
            'api_response_size_bytes',
            'API response size in bytes',
            (float)$responseSize,
            $labels
        );

        $this->metricsClient->recordGauge(
            'api_requests_in_flight',
            'Requests currently being processed',
            -1,
            $this->requestLabels
        );

        $this->logger->info('API request completed', [
            'endpoint' => $endpoint,
            'method' => $method,
            'request_id' => $requestId,
            'status_code' => $statusCode,
            'duration_ms' => $durationMs
        ]);
    }

    public function recordRequestError(
        string $endpoint,
        string $method,
        string $requestId,
        string $errorType,
        string $errorMessage
    ): void {
        $labels = array_merge($this->requestLabels, [
            'error_type' => $errorType
        ]);

        $this->metricsClient->incrementCounter(
            'api_requests_errors_total',
            'API request errors',
            1,
            $labels
        );

        $this->metricsClient->recordGauge(
            'api_requests_in_flight',
            'Requests currently being processed',
            -1,
            $this->requestLabels
        );

        $this->logger->error('API request error', [
            'endpoint' => $endpoint,
            'method' => $method,
            'request_id' => $requestId,
            'error_type' => $errorType,
            'error_message' => $errorMessage
        ]);

        $this->sendErrorAlert($endpoint, $errorType, $errorMessage);
    }

    private function sendErrorAlert(string $endpoint, string $errorType, string $errorMessage): void
    {
        if ($this->isHighErrorRate($endpoint)) {
            $this->metricsClient->incrementCounter(
                'api_alerts_triggered_total',
                'API alerts triggered',
                1,
                ['alert_type' => 'high_error_rate']
            );

            $this->logger->warning('High error rate detected', [
                'endpoint' => $endpoint,
                'error_type' => $errorType
            ]);
        }
    }

    private function isHighErrorRate(string $endpoint): bool
    {
        $errorCount = $this->metricsClient->getCounterValue(
            'api_requests_errors_total',
            ['endpoint' => $endpoint]
        );

        $totalCount = $this->metricsClient->getCounterValue(
            'api_requests_completed_total',
            ['endpoint' => $endpoint]
        );

        if ($totalCount === 0) {
            return false;
        }

        return ($errorCount / $totalCount) > 0.05;
    }

    public function recordDatabaseQuery(string $query, float $durationMs, bool $success): void
    {
        $labels = [
            'query_type' => $this->classifyQuery($query),
            'success' => $success ? 'true' : 'false'
        ];

        $this->metricsClient->incrementCounter(
            'database_queries_total',
            'Database queries executed',
            1,
            $labels
        );

        $this->metricsClient->recordHistogram(
            'database_query_duration_seconds',
            'Database query duration',
            $durationMs / 1000,
            $labels
        );
    }

    private function classifyQuery(string $query): string
    {
        $query = strtoupper(trim($query));

        if (str_starts_with($query, 'SELECT')) {
            return 'select';
        }

        if (str_starts_with($query, 'INSERT')) {
            return 'insert';
        }

        if (str_starts_with($query, 'UPDATE')) {
            return 'update';
        }

        if (str_starts_with($query, 'DELETE')) {
            return 'delete';
        }

        return 'other';
    }

    public function recordCacheOperation(string $operation, string $key, bool $hit): void
    {
        $labels = [
            'operation' => $operation,
            'hit' => $hit ? 'true' : 'false'
        ];

        $this->metricsClient->incrementCounter(
            'cache_operations_total',
            'Cache operations',
            1,
            $labels
        );

        $this->metricsClient->recordHistogram(
            'cache_operation_duration_seconds',
            'Cache operation duration',
            0.001,
            $labels
        );
    }

    public function recordExternalApiCall(
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

        $this->metricsClient->incrementCounter(
            'external_api_calls_total',
            'External API calls',
            1,
            $labels
        );

        $this->metricsClient->recordHistogram(
            'external_api_call_duration_seconds',
            'External API call duration',
            $durationMs / 1000,
            $labels
        );
    }
}
