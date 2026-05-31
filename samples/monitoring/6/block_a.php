<?php

declare(strict_types=1);

namespace App\Http\Middleware;

class MetricsMiddleware
{
    private MetricsCollector $metrics;
    private LoggerInterface $logger;

    public function __construct(MetricsCollector $metrics, LoggerInterface $logger)
    {
        $this->metrics = $metrics;
        $this->logger = $logger;
    }

    public function handle(Request $request, callable $next): Response
    {
        $startTime = hrtime(true);
        $requestId = $this->generateRequestId($request);

        $this->recordRequestStarted($request, $requestId);

        try {
            $response = $next($request);

            $duration = (hrtime(true) - $startTime) / 1e6;

            $this->recordRequestCompleted($request, $response, $requestId, $duration);

            return $response;
        } catch (\Exception $e) {
            $duration = (hrtime(true) - $startTime) / 1e6;

            $this->recordRequestError($request, $e, $requestId, $duration);

            throw $e;
        }
    }

    private function recordRequestStarted(Request $request, string $requestId): void
    {
        $labels = [
            'method' => $request->getMethod(),
            'path' => $this->normalizePath($request->getPath()),
            'request_id' => $requestId
        ];

        $this->metrics->incrementCounter(
            'http_requests_started_total',
            'Total HTTP requests started',
            1,
            $labels
        );

        $this->metrics->gauge(
            'http_requests_in_progress',
            'Requests currently being processed',
            1,
            $labels
        );

        $this->logger->debug('Request started', [
            'request_id' => $requestId,
            'method' => $request->getMethod(),
            'path' => $request->getPath()
        ]);
    }

    private function recordRequestCompleted(
        Request $request,
        Response $response,
        string $requestId,
        float $durationMs
    ): void {
        $labels = [
            'method' => $request->getMethod(),
            'path' => $this->normalizePath($request->getPath()),
            'status_code' => (string)$response->getStatusCode(),
            'request_id' => $requestId
        ];

        $this->metrics->incrementCounter(
            'http_requests_completed_total',
            'Total HTTP requests completed',
            1,
            $labels
        );

        $this->metrics->histogram(
            'http_request_duration_milliseconds',
            'HTTP request duration in milliseconds',
            $durationMs,
            $labels
        );

        $this->metrics->histogram(
            'http_response_size_bytes',
            'HTTP response size in bytes',
            (float)$response->getContentLength(),
            $labels
        );

        $this->metrics->gauge(
            'http_requests_in_progress',
            'Requests currently being processed',
            -1,
            ['method' => $request->getMethod(), 'path' => $this->normalizePath($request->getPath())]
        );

        $this->logger->info('Request completed', [
            'request_id' => $requestId,
            'method' => $request->getMethod(),
            'path' => $request->getPath(),
            'status_code' => $response->getStatusCode(),
            'duration_ms' => round($durationMs, 2)
        ]);
    }

    private function recordRequestError(
        Request $request,
        \Exception $error,
        string $requestId,
        float $durationMs
    ): void {
        $labels = [
            'method' => $request->getMethod(),
            'path' => $this->normalizePath($request->getPath()),
            'error_type' => get_class($error),
            'request_id' => $requestId
        ];

        $this->metrics->incrementCounter(
            'http_requests_errors_total',
            'Total HTTP request errors',
            1,
            $labels
        );

        $this->metrics->histogram(
            'http_request_error_duration_milliseconds',
            'HTTP request error duration in milliseconds',
            $durationMs,
            $labels
        );

        $this->metrics->gauge(
            'http_requests_in_progress',
            'Requests currently being processed',
            -1,
            ['method' => $request->getMethod(), 'path' => $this->normalizePath($request->getPath())]
        );

        $this->logger->error('Request error', [
            'request_id' => $requestId,
            'method' => $request->getMethod(),
            'path' => $request->getPath(),
            'error' => $error->getMessage(),
            'error_type' => get_class($error),
            'duration_ms' => round($durationMs, 2)
        ]);
    }

    private function generateRequestId(Request $request): string
    {
        return $request->headers->get('X-Request-ID', uniqid('req_', true));
    }

    private function normalizePath(string $path): string
    {
        $path = preg_replace('/\/\d+/', '/:id', $path);
        $path = preg_replace('/\/[a-f0-9-]{36}/i', '/:uuid', $path);

        return $path;
    }
}
