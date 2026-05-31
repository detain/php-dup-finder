<?php

declare(strict_types=1);

namespace App\Api\Core;

trait ApiEndpointMonitoringTrait
{
    private MetricsCollector $metrics;
    private LoggerInterface $logger;
    private RateLimiter $rateLimiter;

    protected function monitorRequest(
        string $endpoint,
        callable $handler,
        array $request
    ): Response {
        $requestId = uniqid("{$endpoint}_", true);
        $startTime = microtime(true);
        $clientId = $request['client_id'] ?? 'unknown';

        $this->logger->debug('API request started', [
            'endpoint' => $endpoint,
            'request_id' => $requestId,
            'client_id' => $clientId
        ]);

        if (!$this->checkRateLimit($endpoint, $clientId)) {
            return $this->rateLimitExceededResponse($requestId, $clientId);
        }

        try {
            $response = $handler($request);
            $duration = (microtime(true) - $startTime) * 1000;

            $this->recordSuccess($endpoint, $requestId, $duration, $clientId, $response);

            return $response;
        } catch (\Exception $e) {
            $duration = (microtime(true) - $startTime) * 1000;

            $this->recordError($endpoint, $requestId, $e, $duration, $clientId);

            return $this->errorResponse($requestId, $e->getMessage());
        }
    }

    private function checkRateLimit(string $endpoint, string $clientId): bool
    {
        $allowed = $this->rateLimiter->check($clientId, $endpoint);

        if (!$allowed) {
            $this->metrics->incrementCounter('api_rate_limit_hits_total', 1, [
                'endpoint' => $endpoint,
                'client_id' => $clientId
            ]);
        }

        return $allowed;
    }

    private function recordSuccess(
        string $endpoint,
        string $requestId,
        float $durationMs,
        string $clientId,
        Response $response
    ): void {
        $labels = [
            'endpoint' => $endpoint,
            'status' => 'success',
            'client_id' => $clientId
        ];

        $this->metrics->incrementCounter('api_requests_total', 1, $labels);
        $this->metrics->histogram('api_request_duration_ms', $durationMs, ['endpoint' => $endpoint]);
    }

    private function recordError(
        string $endpoint,
        string $requestId,
        \Exception $error,
        float $durationMs,
        string $clientId
    ): void {
        $labels = [
            'endpoint' => $endpoint,
            'status' => 'error',
            'error_type' => get_class($error),
            'client_id' => $clientId
        ];

        $this->metrics->incrementCounter('api_requests_total', 1, $labels);
        $this->metrics->incrementCounter('api_errors_total', 1, $labels);
    }

    protected function recordValidationError(string $endpoint, string $clientId, string $type): void
    {
        $this->metrics->incrementCounter('api_requests_total', 1, [
            'endpoint' => $endpoint,
            'status' => 'validation_error',
            'client_id' => $clientId
        ]);

        $this->metrics->incrementCounter('api_validation_errors_total', 1, [
            'endpoint' => $endpoint,
            'validation_type' => $type,
            'client_id' => $clientId
        ]);
    }

    protected function recordNotFound(string $endpoint, string $clientId): void
    {
        $this->metrics->incrementCounter('api_requests_total', 1, [
            'endpoint' => $endpoint,
            'status' => 'not_found',
            'client_id' => $clientId
        ]);

        $this->metrics->incrementCounter('api_not_found_total', 1, [
            'endpoint' => $endpoint,
            'client_id' => $clientId
        ]);
    }
}

abstract class AbstractApiEndpoint
{
    use ApiEndpointMonitoringTrait;

    protected DatabaseConnection $db;

    abstract protected function getEndpointName(): string;

    protected function handleGet(array $request): Response
    {
        return $this->monitorRequest($this->getEndpointName() . '.get', function ($req) {
            $id = $req['params']['id'] ?? null;

            if ($id === null) {
                return $this->validationErrorResponse(uniqid('req_', true), 'ID is required');
            }

            $entity = $this->fetchEntity($id);

            if ($entity === null) {
                return $this->notFoundResponse(uniqid('req_', true), 'Not found');
            }

            return $this->successResponse(uniqid('req_', true), $entity);
        }, $request);
    }

    abstract protected function fetchEntity(string $id): ?array;
}

class Response
{
    public function __construct(public array $data, public int $statusCode = 200) {}
}
