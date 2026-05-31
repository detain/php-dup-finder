<?php

declare(strict_types=1);

namespace App\Api\Endpoint;

class ProductEndpointHandler
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

    public function handleGetProduct(array $request): Response
    {
        $requestId = uniqid('product_get_', true);
        $startTime = microtime(true);
        $clientId = $request['client_id'] ?? 'unknown';

        $this->logRequestStarted('product.get', $requestId, $clientId);

        if (!$this->checkRateLimit('product.get', $clientId, $requestId)) {
            return $this->rateLimitExceededResponse($requestId, $clientId);
        }

        try {
            $productId = $request['params']['id'] ?? null;

            if ($productId === null) {
                $this->recordValidationError('product.get', $requestId, 'missing_product_id', $clientId);
                return $this->validationErrorResponse($requestId, 'Product ID is required');
            }

            $product = $this->fetchProduct($productId);

            if ($product === null) {
                $this->recordNotFound('product.get', $requestId, $productId, $clientId);
                return $this->notFoundResponse($requestId, 'Product not found');
            }

            $duration = (microtime(true) - $startTime) * 1000;

            $this->recordSuccess('product.get', $requestId, $duration, $clientId, [
                'product_id' => $productId
            ]);

            return $this->successResponse($requestId, $product);
        } catch (\Exception $e) {
            $duration = (microtime(true) - $startTime) * 1000;

            $this->recordError('product.get', $requestId, $e, $duration, $clientId);

            return $this->errorResponse($requestId, $e->getMessage());
        }
    }

    public function handleSearchProducts(array $request): Response
    {
        $requestId = uniqid('product_search_', true);
        $startTime = microtime(true);
        $clientId = $request['client_id'] ?? 'unknown';

        $this->logRequestStarted('product.search', $requestId, $clientId);

        if (!$this->checkRateLimit('product.search', $clientId, $requestId)) {
            return $this->rateLimitExceededResponse($requestId, $clientId);
        }

        try {
            $query = $request['params']['q'] ?? '';
            $category = $request['params']['category'] ?? null;
            $limit = min((int)($request['params']['limit'] ?? 50), 100);

            if (strlen($query) < 2 && $category === null) {
                $this->recordValidationError('product.search', $requestId, 'insufficient_search_params', $clientId);
                return $this->validationErrorResponse($requestId, 'Search query must be at least 2 characters or specify a category');
            }

            $products = $this->searchProducts($query, $category, $limit);

            $duration = (microtime(true) - $startTime) * 1000;

            $this->recordSuccess('product.search', $requestId, $duration, $clientId, [
                'result_count' => count($products),
                'query' => substr($query, 0, 100)
            ]);

            return $this->successResponse($requestId, ['products' => $products]);
        } catch (\Exception $e) {
            $duration = (microtime(true) - $startTime) * 1000;

            $this->recordError('product.search', $requestId, $e, $duration, $clientId);

            return $this->errorResponse($requestId, $e->getMessage());
        }
    }

    public function handleCreateProduct(array $request): Response
    {
        $requestId = uniqid('product_create_', true);
        $startTime = microtime(true);
        $clientId = $request['client_id'] ?? 'unknown';

        $this->logRequestStarted('product.create', $requestId, $clientId);

        if (!$this->checkRateLimit('product.create', $clientId, $requestId)) {
            return $this->rateLimitExceededResponse($requestId, $clientId);
        }

        try {
            $name = $request['params']['name'] ?? null;
            $description = $request['params']['description'] ?? null;
            $price = $request['params']['price'] ?? null;
            $categoryId = $request['params']['category_id'] ?? null;

            if ($name === null || strlen($name) < 3) {
                $this->recordValidationError('product.create', $requestId, 'invalid_name', $clientId);
                return $this->validationErrorResponse($requestId, 'Product name must be at least 3 characters');
            }

            if ($price === null || !is_numeric($price) || $price <= 0) {
                $this->recordValidationError('product.create', $requestId, 'invalid_price', $clientId);
                return $this->validationErrorResponse($requestId, 'Product price must be a positive number');
            }

            if ($categoryId === null) {
                $this->recordValidationError('product.create', $requestId, 'missing_category', $clientId);
                return $this->validationErrorResponse($requestId, 'Category ID is required');
            }

            $productId = $this->createProduct($name, $description, $price, $categoryId);

            $duration = (microtime(true) - $startTime) * 1000;

            $this->recordSuccess('product.create', $requestId, $duration, $clientId, [
                'product_id' => $productId
            ]);

            return $this->createdResponse($requestId, ['id' => $productId]);
        } catch (\Exception $e) {
            $duration = (microtime(true) - $startTime) * 1000;

            $this->recordError('product.create', $requestId, $e, $duration, $clientId);

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

    private function fetchProduct(string $id): ?array
    {
        $result = $this->db->query("SELECT * FROM products WHERE id = ?", [$id]);
        return $result ?: null;
    }

    private function searchProducts(string $query, ?string $category, int $limit): array
    {
        if ($category !== null) {
            return $this->db->queryAll(
                "SELECT * FROM products WHERE category_id = ? AND active = 1 LIMIT ?",
                [$category, $limit]
            );
        }

        $searchPattern = "%{$query}%";
        return $this->db->queryAll(
            "SELECT * FROM products WHERE (name LIKE ? OR description LIKE ?) AND active = 1 LIMIT ?",
            [$searchPattern, $searchPattern, $limit]
        );
    }

    private function createProduct(string $name, ?string $description, float $price, string $categoryId): string
    {
        $id = bin2hex(random_bytes(16));
        $this->db->execute(
            "INSERT INTO products (id, name, description, price, category_id, active, created_at) VALUES (?, ?, ?, ?, ?, 1, ?)",
            [$id, $name, $description, $price, $categoryId, time()]
        );
        return $id;
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
