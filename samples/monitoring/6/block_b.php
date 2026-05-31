<?php

declare(strict_types=1);

namespace App\Grpc;

class GrpcMetricsInterceptor
{
    private MetricsCollector $metrics;
    private LoggerInterface $logger;

    public function __construct(MetricsCollector $metrics, LoggerInterface $logger)
    {
        $this->metrics = $metrics;
        $this->logger = $logger;
    }

    public function intercept(
        string $method,
        string $service,
        $request,
        array $metadata
    ) {
        $startTime = hrtime(true);
        $callId = uniqid('grpc_', true);

        $this->recordCallStarted($method, $service, $callId, $metadata);

        try {
            $response = yield;

            $duration = (hrtime(true) - $startTime) / 1e6;

            $this->recordCallCompleted($method, $service, $callId, $duration, $response);

            return $response;
        } catch (\Exception $e) {
            $duration = (hrtime(true) - $startTime) / 1e6;

            $this->recordCallError($method, $service, $e, $callId, $duration);

            throw $e;
        }
    }

    private function recordCallStarted(
        string $method,
        string $service,
        string $callId,
        array $metadata
    ): void {
        $labels = [
            'service' => $service,
            'method' => $method,
            'call_id' => $callId
        ];

        $this->metrics->incrementCounter(
            'grpc_calls_started_total',
            'Total gRPC calls started',
            1,
            $labels
        );

        $this->metrics->gauge(
            'grpc_calls_in_progress',
            'gRPC calls currently in progress',
            1,
            $labels
        );

        $this->logger->debug('gRPC call started', [
            'call_id' => $callId,
            'service' => $service,
            'method' => $method
        ]);
    }

    private function recordCallCompleted(
        string $method,
        string $service,
        string $callId,
        float $durationMs,
        $response
    ): void {
        $statusCode = $response->getStatusCode() ?? 0;

        $labels = [
            'service' => $service,
            'method' => $method,
            'status_code' => (string)$statusCode,
            'call_id' => $callId
        ];

        $this->metrics->incrementCounter(
            'grpc_calls_completed_total',
            'Total gRPC calls completed',
            1,
            $labels
        );

        $this->metrics->histogram(
            'grpc_call_duration_milliseconds',
            'gRPC call duration in milliseconds',
            $durationMs,
            $labels
        );

        $this->metrics->gauge(
            'grpc_calls_in_progress',
            'gRPC calls currently in progress',
            -1,
            ['service' => $service, 'method' => $method]
        );

        $this->logger->info('gRPC call completed', [
            'call_id' => $callId,
            'service' => $service,
            'method' => $method,
            'status_code' => $statusCode,
            'duration_ms' => round($durationMs, 2)
        ]);
    }

    private function recordCallError(
        string $method,
        string $service,
        \Exception $error,
        string $callId,
        float $durationMs
    ): void {
        $labels = [
            'service' => $service,
            'method' => $method,
            'error_type' => $this->classifyError($error),
            'call_id' => $callId
        ];

        $this->metrics->incrementCounter(
            'grpc_calls_errors_total',
            'Total gRPC call errors',
            1,
            $labels
        );

        $this->metrics->histogram(
            'grpc_call_error_duration_milliseconds',
            'gRPC call error duration in milliseconds',
            $durationMs,
            $labels
        );

        $this->metrics->gauge(
            'grpc_calls_in_progress',
            'gRPC calls currently in progress',
            -1,
            ['service' => $service, 'method' => $method]
        );

        $this->logger->error('gRPC call error', [
            'call_id' => $callId,
            'service' => $service,
            'method' => $method,
            'error' => $error->getMessage(),
            'error_type' => get_class($error),
            'duration_ms' => round($durationMs, 2)
        ]);
    }

    private function classifyError(\Exception $error): string
    {
        $message = strtolower($error->getMessage());

        if (str_contains($message, 'timeout')) {
            return 'timeout';
        }

        if (str_contains($message, 'unauthenticated') || str_contains($message, 'unauthorized')) {
            return 'auth_error';
        }

        if (str_contains($message, 'not found')) {
            return 'not_found';
        }

        if (str_contains($message, 'already exists')) {
            return 'conflict';
        }

        if (str_contains($message, 'invalid')) {
            return 'validation_error';
        }

        return 'unknown_error';
    }
}
