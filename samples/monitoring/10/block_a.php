<?php

declare(strict_types=1);

namespace App\Api\Endpoint;

class UserEndpointHandler
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

    public function handleGetUser(array $request): Response
    {
        $requestId = uniqid('user_get_', true);
        $startTime = microtime(true);
        $clientId = $request['client_id'] ?? 'unknown';

        $this->logRequestStarted('user.get', $requestId, $clientId);

        if (!$this->checkRateLimit('user.get', $clientId, $requestId)) {
            return $this->rateLimitExceededResponse($requestId, $clientId);
        }

        try {
            $userId = $request['params']['id'] ?? null;

            if ($userId === null) {
                $this->recordValidationError('user.get', $requestId, 'missing_user_id', $clientId);
                return $this->validationErrorResponse($requestId, 'User ID is required');
            }

            $user = $this->fetchUser($userId);

            if ($user === null) {
                $this->recordNotFound('user.get', $requestId, $userId, $clientId);
                return $this->notFoundResponse($requestId, 'User not found');
            }

            $duration = (microtime(true) - $startTime) * 1000;

            $this->recordSuccess('user.get', $requestId, $duration, $clientId, [
                'user_id' => $userId
            ]);

            return $this->successResponse($requestId, $user);
        } catch (\Exception $e) {
            $duration = (microtime(true) - $startTime) * 1000;

            $this->recordError('user.get', $requestId, $e, $duration, $clientId);

            return $this->errorResponse($requestId, $e->getMessage());
        }
    }

    public function handleCreateUser(array $request): Response
    {
        $requestId = uniqid('user_create_', true);
        $startTime = microtime(true);
        $clientId = $request['client_id'] ?? 'unknown';

        $this->logRequestStarted('user.create', $requestId, $clientId);

        if (!$this->checkRateLimit('user.create', $clientId, $requestId)) {
            return $this->rateLimitExceededResponse($requestId, $clientId);
        }

        try {
            $email = $request['params']['email'] ?? null;
            $name = $request['params']['name'] ?? null;

            if ($email === null || !$this->isValidEmail($email)) {
                $this->recordValidationError('user.create', $requestId, 'invalid_email', $clientId);
                return $this->validationErrorResponse($requestId, 'Valid email is required');
            }

            if ($name === null || strlen($name) < 2) {
                $this->recordValidationError('user.create', $requestId, 'invalid_name', $clientId);
                return $this->validationErrorResponse($requestId, 'Name must be at least 2 characters');
            }

            $userId = $this->createUser($email, $name);

            $duration = (microtime(true) - $startTime) * 1000;

            $this->recordSuccess('user.create', $requestId, $duration, $clientId, [
                'user_id' => $userId
            ]);

            return $this->createdResponse($requestId, ['id' => $userId]);
        } catch (\Exception $e) {
            $duration = (microtime(true) - $startTime) * 1000;

            $this->recordError('user.create', $requestId, $e, $duration, $clientId);

            return $this->errorResponse($requestId, $e->getMessage());
        }
    }

    public function handleUpdateUser(array $request): Response
    {
        $requestId = uniqid('user_update_', true);
        $startTime = microtime(true);
        $clientId = $request['client_id'] ?? 'unknown';

        $this->logRequestStarted('user.update', $requestId, $clientId);

        if (!$this->checkRateLimit('user.update', $clientId, $requestId)) {
            return $this->rateLimitExceededResponse($requestId, $clientId);
        }

        try {
            $userId = $request['params']['id'] ?? null;
            $email = $request['params']['email'] ?? null;
            $name = $request['params']['name'] ?? null;

            if ($userId === null) {
                $this->recordValidationError('user.update', $requestId, 'missing_user_id', $clientId);
                return $this->validationErrorResponse($requestId, 'User ID is required');
            }

            if ($email !== null && !$this->isValidEmail($email)) {
                $this->recordValidationError('user.update', $requestId, 'invalid_email', $clientId);
                return $this->validationErrorResponse($requestId, 'Invalid email format');
            }

            if ($name !== null && strlen($name) < 2) {
                $this->recordValidationError('user.update', $requestId, 'invalid_name', $clientId);
                return $this->validationErrorResponse($requestId, 'Name must be at least 2 characters');
            }

            $updated = $this->updateUser($userId, $email, $name);

            if (!$updated) {
                $this->recordNotFound('user.update', $requestId, $userId, $clientId);
                return $this->notFoundResponse($requestId, 'User not found');
            }

            $duration = (microtime(true) - $startTime) * 1000;

            $this->recordSuccess('user.update', $requestId, $duration, $clientId, [
                'user_id' => $userId
            ]);

            return $this->successResponse($requestId, ['id' => $userId]);
        } catch (\Exception $e) {
            $duration = (microtime(true) - $startTime) * 1000;

            $this->recordError('user.update', $requestId, $e, $duration, $clientId);

            return $this->errorResponse($requestId, $e->getMessage());
        }
    }

    public function handleDeleteUser(array $request): Response
    {
        $requestId = uniqid('user_delete_', true);
        $startTime = microtime(true);
        $clientId = $request['client_id'] ?? 'unknown';

        $this->logRequestStarted('user.delete', $requestId, $clientId);

        if (!$this->checkRateLimit('user.delete', $clientId, $requestId)) {
            return $this->rateLimitExceededResponse($requestId, $clientId);
        }

        try {
            $userId = $request['params']['id'] ?? null;

            if ($userId === null) {
                $this->recordValidationError('user.delete', $requestId, 'missing_user_id', $clientId);
                return $this->validationErrorResponse($requestId, 'User ID is required');
            }

            $deleted = $this->deleteUser($userId);

            if (!$deleted) {
                $this->recordNotFound('user.delete', $requestId, $userId, $clientId);
                return $this->notFoundResponse($requestId, 'User not found');
            }

            $duration = (microtime(true) - $startTime) * 1000;

            $this->recordSuccess('user.delete', $requestId, $duration, $clientId, [
                'user_id' => $userId
            ]);

            return $this->deletedResponse($requestId);
        } catch (\Exception $e) {
            $duration = (microtime(true) - $startTime) * 1000;

            $this->recordError('user.delete', $requestId, $e, $duration, $clientId);

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

    private function isValidEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    private function fetchUser(string $id): ?array
    {
        $result = $this->db->query("SELECT * FROM users WHERE id = ?", [$id]);
        return $result ?: null;
    }

    private function createUser(string $email, string $name): string
    {
        $id = bin2hex(random_bytes(16));
        $this->db->execute(
            "INSERT INTO users (id, email, name, created_at) VALUES (?, ?, ?, ?)",
            [$id, $email, $name, time()]
        );
        return $id;
    }

    private function updateUser(string $id, ?string $email, ?string $name): bool
    {
        $updates = [];
        $params = [];

        if ($email !== null) {
            $updates[] = "email = ?";
            $params[] = $email;
        }

        if ($name !== null) {
            $updates[] = "name = ?";
            $params[] = $name;
        }

        if (empty($updates)) {
            return false;
        }

        $params[] = $id;
        $affected = $this->db->execute(
            "UPDATE users SET " . implode(', ', $updates) . ", updated_at = ? WHERE id = ?",
            $params
        );

        return $affected > 0;
    }

    private function deleteUser(string $id): bool
    {
        $affected = $this->db->execute("DELETE FROM users WHERE id = ?", [$id]);
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

    private function deletedResponse(string $requestId): Response
    {
        return new Response([
            'request_id' => $requestId,
            'deleted' => true
        ], 200);
    }
}
