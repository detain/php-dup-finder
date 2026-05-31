<?php

declare(strict_types=1);

namespace App\Repository;

class ProductRepository
{
    private DatabaseConnection $db;
    private LoggerInterface $logger;
    private MetricsCollector $metrics;

    public function __construct(
        DatabaseConnection $db,
        LoggerInterface $logger,
        MetricsCollector $metrics
    ) {
        $this->db = $db;
        $this->logger = $logger;
        $this->metrics = $metrics;
    }

    public function findById(string $id): ?Product
    {
        $operationId = uniqid('product_find_', true);
        $startTime = microtime(true);

        $this->logOperationStarted('product.find_by_id', $operationId, ['product_id' => $id]);

        try {
            $query = "SELECT * FROM products WHERE id = ?";
            $result = $this->db->query($query, [$id]);

            $duration = (microtime(true) - $startTime) * 1000;

            $this->logOperationCompleted(
                'product.find_by_id',
                $operationId,
                $result !== null,
                $duration
            );

            $this->recordMetrics(
                'product.find_by_id',
                $result !== null,
                $duration,
                ['product_id' => $id]
            );

            if ($result === null) {
                return null;
            }

            return Product::fromRow($result);
        } catch (\Exception $e) {
            $duration = (microtime(true) - $startTime) * 1000;

            $this->logOperationFailed('product.find_by_id', $operationId, $e, $duration);

            $this->recordErrorMetrics('product.find_by_id', $e, $duration, ['product_id' => $id]);

            throw $e;
        }
    }

    public function findByCategory(string $categoryId, int $limit = 100): array
    {
        $operationId = uniqid('product_find_', true);
        $startTime = microtime(true);

        $this->logOperationStarted('product.find_by_category', $operationId, [
            'category_id' => $categoryId,
            'limit' => $limit
        ]);

        try {
            $query = "SELECT * FROM products WHERE category_id = ? AND active = 1 LIMIT ?";
            $results = $this->db->queryAll($query, [$categoryId, $limit]);

            $duration = (microtime(true) - $startTime) * 1000;

            $this->logOperationCompleted(
                'product.find_by_category',
                $operationId,
                count($results) > 0,
                $duration
            );

            $this->recordMetrics(
                'product.find_by_category',
                true,
                $duration,
                ['category_id' => $categoryId, 'result_count' => count($results)]
            );

            return array_map(fn($row) => Product::fromRow($row), $results);
        } catch (\Exception $e) {
            $duration = (microtime(true) - $startTime) * 1000;

            $this->logOperationFailed('product.find_by_category', $operationId, $e, $duration);

            $this->recordErrorMetrics('product.find_by_category', $e, $duration, ['category_id' => $categoryId]);

            throw $e;
        }
    }

    public function save(Product $product): bool
    {
        $operationId = uniqid('product_save_', true);
        $startTime = microtime(true);

        $this->logOperationStarted('product.save', $operationId, ['product_id' => $product->getId()]);

        try {
            if ($product->getId() === null) {
                $query = "INSERT INTO products (name, description, price, category_id, active, created_at) VALUES (?, ?, ?, ?, ?, ?)";
                $params = [
                    $product->getName(),
                    $product->getDescription(),
                    $product->getPrice(),
                    $product->getCategoryId(),
                    $product->isActive() ? 1 : 0,
                    time()
                ];
            } else {
                $query = "UPDATE products SET name = ?, description = ?, price = ?, category_id = ?, active = ?, updated_at = ? WHERE id = ?";
                $params = [
                    $product->getName(),
                    $product->getDescription(),
                    $product->getPrice(),
                    $product->getCategoryId(),
                    $product->isActive() ? 1 : 0,
                    time(),
                    $product->getId()
                ];
            }

            $affectedRows = $this->db->execute($query, $params);

            $duration = (microtime(true) - $startTime) * 1000;

            $this->logOperationCompleted(
                'product.save',
                $operationId,
                $affectedRows > 0,
                $duration
            );

            $this->recordMetrics(
                'product.save',
                $affectedRows > 0,
                $duration,
                ['product_id' => $product->getId() ?? 'new']
            );

            return $affectedRows > 0;
        } catch (\Exception $e) {
            $duration = (microtime(true) - $startTime) * 1000;

            $this->logOperationFailed('product.save', $operationId, $e, $duration);

            $this->recordErrorMetrics('product.save', $e, $duration, ['product_id' => $product->getId() ?? 'new']);

            throw $e;
        }
    }

    public function search(string $query, int $limit = 50): array
    {
        $operationId = uniqid('product_search_', true);
        $startTime = microtime(true);

        $this->logOperationStarted('product.search', $operationId, [
            'query' => $query,
            'limit' => $limit
        ]);

        try {
            $searchPattern = "%{$query}%";
            $sql = "SELECT * FROM products WHERE (name LIKE ? OR description LIKE ?) AND active = 1 LIMIT ?";
            $results = $this->db->queryAll($sql, [$searchPattern, $searchPattern, $limit]);

            $duration = (microtime(true) - $startTime) * 1000;

            $this->logOperationCompleted(
                'product.search',
                $operationId,
                count($results) > 0,
                $duration
            );

            $this->recordMetrics(
                'product.search',
                true,
                $duration,
                ['result_count' => count($results)]
            );

            return array_map(fn($row) => Product::fromRow($row), $results);
        } catch (\Exception $e) {
            $duration = (microtime(true) - $startTime) * 1000;

            $this->logOperationFailed('product.search', $operationId, $e, $duration);

            $this->recordErrorMetrics('product.search', $e, $duration, ['query' => substr($query, 0, 100)]);

            throw $e;
        }
    }

    private function logOperationStarted(string $operation, string $operationId, array $context): void
    {
        $this->logger->debug('Repository operation started', array_merge(
            ['operation' => $operation, 'operation_id' => $operationId],
            $context
        ));
    }

    private function logOperationCompleted(
        string $operation,
        string $operationId,
        bool $success,
        float $durationMs
    ): void {
        $this->logger->info('Repository operation completed', [
            'operation' => $operation,
            'operation_id' => $operationId,
            'success' => $success,
            'duration_ms' => round($durationMs, 2)
        ]);
    }

    private function logOperationFailed(
        string $operation,
        string $operationId,
        \Exception $error,
        float $durationMs
    ): void {
        $this->logger->error('Repository operation failed', [
            'operation' => $operation,
            'operation_id' => $operationId,
            'error' => $error->getMessage(),
            'error_type' => get_class($error),
            'duration_ms' => round($durationMs, 2)
        ]);
    }

    private function recordMetrics(string $operation, bool $success, float $durationMs, array $labels): void
    {
        $this->metrics->incrementCounter(
            'repository_operations_total',
            'Total repository operations',
            1,
            array_merge(['operation' => $operation, 'success' => $success ? 'true' : 'false'], $labels)
        );

        $this->metrics->histogram(
            'repository_operation_duration_ms',
            'Repository operation duration in milliseconds',
            $durationMs,
            array_merge(['operation' => $operation], $labels)
        );
    }

    private function recordErrorMetrics(
        string $operation,
        \Exception $error,
        float $durationMs,
        array $labels
    ): void {
        $this->metrics->incrementCounter(
            'repository_operation_errors_total',
            'Total repository operation errors',
            1,
            array_merge(['operation' => $operation, 'error_type' => get_class($error)], $labels)
        );

        $this->metrics->histogram(
            'repository_operation_error_duration_ms',
            'Repository operation error duration in milliseconds',
            $durationMs,
            array_merge(['operation' => $operation], $labels)
        );
    }
}
