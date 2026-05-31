<?php

declare(strict_types=1);

namespace App\Api\Endpoint;

class OrderEndpointHandler
{
    private DatabaseConnection $db;
    private MetricsCollector $metrics;
    private LoggerInterface $logger;
    private RateLimiter $rateLimiter;

    public function __construct(
        DatabaseConnection $db,
        MetricsCollector $metrics,
        LoggerInterface $logger,
        RateLimiter $rateLimiter
    ) {
        $this->db = $db;
        $this->metrics = $metrics;
        $this->logger = $logger;
        $this->rateLimiter = $rateLimiter;
    }

    public function handleGetOrder(array $request): Response
    {
        $requestId = uniqid('order_get_', true);
        $startTime = microtime(true);
        $clientId = $request['client_id'] ?? 'unknown';

        $this->logRequestStarted('order.get', $requestId, $clientId);

        if (!$this->checkRateLimit('order.get', $clientId, $requestId)) {
            return $this->rateLimitExceededResponse($requestId, $clientId);
        }

        try {
            $orderId = $request['params']['id'] ?? null;

            if ($orderId === null) {
                $this->recordValidationError('order.get', $requestId, 'missing_order_id', $clientId);
                return $this->validationErrorResponse($requestId, 'Order ID is required');
            }

            $order = $this->fetchOrder($orderId);

            if ($order === null) {
                $this->recordNotFound('order.get', $requestId, $orderId, $clientId);
                return $this->notFoundResponse($requestId, 'Order not found');
            }

            $duration = (microtime(true) - $startTime) * 1000;

            $this->recordSuccess('order.get', $requestId, $duration, $clientId, [
                'order_id' => $orderId
            ]);

            return $this->successResponse($requestId, $order);
        } catch (\Exception $e) {
            $duration = (microtime(true) - $startTime) * 1000;

            $this->recordError('order.get', $requestId, $e, $duration, $clientId);

            return $this->errorResponse($requestId, $e->getMessage());
        }
    }

    public function handleCreateOrder(array $request): Response
    {
        $requestId = uniqid('order_create_', true);
        $startTime = microtime(true);
        $clientId = $request['client_id'] ?? 'unknown';

        $this->logRequestStarted('order.create', $requestId, $clientId);

        if (!$this->checkRateLimit('order.create', $clientId, $requestId)) {
            return $this->rateLimitExceededResponse($requestId, $clientId);
        }

        try {
            $userId = $request['params']['user_id'] ?? null;
            $items = $request['params']['items'] ?? [];
            $total = $request['params']['total'] ?? null;

            if ($userId === null) {
                $this->recordValidationError('order.create', $requestId, 'missing_user_id', $clientId);
                return $this->validationErrorResponse($requestId, 'User ID is required');
            }

            if (!is_array($items) || count($items) === 0) {
                $this->recordValidationError('order.create', $requestId, 'invalid_items', $clientId);
                return $this->validationErrorResponse($requestId, 'Order must have at least one item');
            }

            if ($total === null || $total <= 0) {
                $this->recordValidationError('order.create', $requestId, 'invalid_total', $clientId);
                return $this->validationErrorResponse($requestId, 'Order total must be positive');
            }

            $orderId = $this->createOrder($userId, $items, $total);

            $duration = (microtime(true) - $startTime) * 1000;

            $this->recordSuccess('order.create', $requestId, $duration, $clientId, [
                'order_id' => $orderId
            ]);

            return $this->createdResponse($requestId, ['id' => $orderId]);
        } catch (\Exception $e) {
            $duration = (microtime(true) - $startTime) * 1000;

            $this->recordError('order.create', $requestId, $e, $duration, $clientId);

            return $this->errorResponse($requestId, $e->getMessage());
        }
    }

    public function handleUpdateOrderStatus(array $request): Response
    {
        $requestId = uniqid('order_status_', true);
        $startTime = microtime(true);
        $clientId = $request['client_id'] ?? 'unknown';

        $this->logRequestStarted('order.update_status', $requestId, $clientId);

        if (!$this->checkRateLimit('order.update_status', $clientId, $requestId)) {
            return $this->rateLimitExceededResponse($requestId, $clientId);
        }

        try {
            $orderId = $request['params']['id'] ?? null;
            $status = $request['params']['status'] ?? null;

            if ($orderId === null) {
                $this->recordValidationError('order.update_status', $requestId, 'missing_order_id', $clientId);
                return $this->validationErrorResponse($requestId, 'Order ID is required');
            }

            $validStatuses = ['pending', 'processing', 'shipped', 'delivered', 'cancelled'];

            if ($status === null || !in_array($status, $validStatuses, true)) {
                $this->recordValidationError('order.update_status', $requestId, 'invalid_status', $clientId);
                return $this->validationErrorResponse($requestId, 'Invalid status value');
            }

            $updated = $this->updateOrderStatus($orderId, $status);

            if (!$updated) {
                $this->recordNotFound('order.update_status', $requestId, $orderId, $clientId);
                return $this->notFoundResponse($requestId, 'Order not found');
            }

            $duration = (microtime(true) - $startTime) * 1000;

            $this->recordSuccess('order.update_status', $requestId, $duration, $clientId, [
                'order_id' => $orderId,
                'new_status' => $status
            ]);

            return $this->successResponse($requestId, ['id' => $orderId, 'status' => $status]);
        } catch (\Exception $e) {
            $duration = (microtime(true) - $startTime) * 1000;

            $this->recordError('order.update_status', $requestId, $e, $duration, $clientId);

            return $this->errorResponse($requestId, $e->getMessage());
        }
    }

    private function checkRateLimit(string $endpoint, string $clientId, string $requestId): bool
    {
        $allowed = $this->rateLimiter->check($clientId, $endpoint);

        if (!$allowed) {
            $this->recordRateLimitHit($endpoint, $clientId);
        }

        return $allowed;
    }

    private function recordRateLimitHit(string $endpoint, string $clientId): void
    {
        $labels = [
            'endpoint' => $endpoint,
            'client_id' => $clientId
        ];

        $this->metrics->incrementCounter(
            'api_rate_limit_hits_total',
            'Total rate limit hits',
            1,
            $labels
        );
    }

    private function logRequestStarted(string $endpoint, string $requestId, string $clientId): void
    {
        $this->logger->debug('API request started', [
            'endpoint' => $endpoint,
            'request_id' => $requestId,
            'client_id' => $clientId
        ]);
    }

    private function recordSuccess(
        string $endpoint,
        string $requestId,
        float $durationMs,
        string $clientId,
        array $context
    ): void {
        $labels = [
            'endpoint' => $endpoint,
            'status' => 'success',
            'client_id' => $clientId
        ];

        $this->metrics->incrementCounter('api_requests_total', 1, $labels);
        $this->metrics->histogram('api_request_duration_ms', $durationMs, ['endpoint' => $endpoint]);

        $this->logger->info('API request completed', array_merge([
            'endpoint' => $endpoint,
            'request_id' => $requestId,
            'duration_ms' => round($durationMs, 2)
        ], $context));
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

        $this->logger->error('API request failed', [
            'endpoint' => $endpoint,
            'request_id' => $requestId,
            'error' => $error->getMessage(),
            'duration_ms' => round($durationMs, 2)
        ]);
    }

    private function recordValidationError(
        string $endpoint,
        string $requestId,
        string $validationType,
        string $clientId
    ): void {
        $labels = [
            'endpoint' => $endpoint,
            'status' => 'validation_error',
            'validation_type' => $validationType,
            'client_id' => $clientId
        ];

        $this->metrics->incrementCounter('api_requests_total', 1, $labels);
        $this->metrics->incrementCounter('api_validation_errors_total', 1, $labels);
    }

    private function recordNotFound(
        string $endpoint,
        string $requestId,
        string $resourceId,
        string $clientId
    ): void {
        $labels = [
            'endpoint' => $endpoint,
            'status' => 'not_found',
            'client_id' => $clientId
        ];

        $this->metrics->incrementCounter('api_requests_total', 1, $labels);
        $this->metrics->incrementCounter('api_not_found_total', 1, $labels);
    }

    private function fetchOrder(string $id): ?array
    {
        $result = $this->db->query("SELECT * FROM orders WHERE id = ?", [$id]);
        return $result ?: null;
    }

    private function createOrder(string $userId, array $items, float $total): string
    {
        $id = bin2hex(random_bytes(16));
        $this->db->execute(
            "INSERT INTO orders (id, user_id, items, total, status, created_at) VALUES (?, ?, ?, ?, ?, ?)",
            [$id, $userId, json_encode($items), $total, 'pending', time()]
        );
        return $id;
    }

    private function updateOrderStatus(string $id, string $status): bool
    {
        $affected = $this->db->execute(
            "UPDATE orders SET status = ?, updated_at = ? WHERE id = ?",
            [$status, time(), $id]
        );
        return $affected > 0;
    }

    private function rateLimitExceededResponse(string $requestId, string $clientId): Response
    {
        return new Response([
            'request_id' => $requestId,
            'error' => 'Rate limit exceeded',
            'client_id' => $clientId
        ], 429);
    }

    private function validationErrorResponse(string $requestId, string $message): Response
    {
        return new Response([
            'request_id' => $requestId,
            'error' => $message
        ], 400);
    }

    private function notFoundResponse(string $requestId, string $message): Response
    {
        return new Response([
            'request_id' => $requestId,
            'error' => $message
        ], 404);
    }

    private function errorResponse(string $requestId, string $message): Response
    {
        return new Response([
            'request_id' => $requestId,
            'error' => $message
        ], 500);
    }

    private function successResponse(string $requestId, array $data): Response
    {
        return new Response([
            'request_id' => $requestId,
            'data' => $data
        ], 200);
    }

    private function createdResponse(string $requestId, array $data): Response
    {
        return new Response([
            'request_id' => $requestId,
            'data' => $data
        ], 201);
    }
}
