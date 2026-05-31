<?php

declare(strict_types=1);

namespace App\Repository;

class OrderRepository
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

    public function findById(string $id): ?Order
    {
        $operationId = uniqid('order_find_', true);
        $startTime = microtime(true);

        $this->logOperationStarted('order.find_by_id', $operationId, ['order_id' => $id]);

        try {
            $query = "SELECT * FROM orders WHERE id = ?";
            $result = $this->db->query($query, [$id]);

            $duration = (microtime(true) - $startTime) * 1000;

            $this->logOperationCompleted(
                'order.find_by_id',
                $operationId,
                $result !== null,
                $duration
            );

            $this->recordMetrics(
                'order.find_by_id',
                $result !== null,
                $duration,
                ['order_id' => $id]
            );

            if ($result === null) {
                return null;
            }

            return Order::fromRow($result);
        } catch (\Exception $e) {
            $duration = (microtime(true) - $startTime) * 1000;

            $this->logOperationFailed('order.find_by_id', $operationId, $e, $duration);

            $this->recordErrorMetrics('order.find_by_id', $e, $duration, ['order_id' => $id]);

            throw $e;
        }
    }

    public function findByUserId(string $userId, int $limit = 50): array
    {
        $operationId = uniqid('order_find_', true);
        $startTime = microtime(true);

        $this->logOperationStarted('order.find_by_user_id', $operationId, [
            'user_id' => $userId,
            'limit' => $limit
        ]);

        try {
            $query = "SELECT * FROM orders WHERE user_id = ? ORDER BY created_at DESC LIMIT ?";
            $results = $this->db->queryAll($query, [$userId, $limit]);

            $duration = (microtime(true) - $startTime) * 1000;

            $this->logOperationCompleted(
                'order.find_by_user_id',
                $operationId,
                count($results) > 0,
                $duration
            );

            $this->recordMetrics(
                'order.find_by_user_id',
                true,
                $duration,
                ['user_id' => $userId, 'result_count' => count($results)]
            );

            return array_map(fn($row) => Order::fromRow($row), $results);
        } catch (\Exception $e) {
            $duration = (microtime(true) - $startTime) * 1000;

            $this->logOperationFailed('order.find_by_user_id', $operationId, $e, $duration);

            $this->recordErrorMetrics('order.find_by_user_id', $e, $duration, ['user_id' => $userId]);

            throw $e;
        }
    }

    public function save(Order $order): bool
    {
        $operationId = uniqid('order_save_', true);
        $startTime = microtime(true);

        $this->logOperationStarted('order.save', $operationId, ['order_id' => $order->getId()]);

        try {
            if ($order->getId() === null) {
                $query = "INSERT INTO orders (user_id, total, status, created_at) VALUES (?, ?, ?, ?)";
                $params = [$order->getUserId(), $order->getTotal(), $order->getStatus(), time()];
            } else {
                $query = "UPDATE orders SET user_id = ?, total = ?, status = ?, updated_at = ? WHERE id = ?";
                $params = [$order->getUserId(), $order->getTotal(), $order->getStatus(), time(), $order->getId()];
            }

            $affectedRows = $this->db->execute($query, $params);

            $duration = (microtime(true) - $startTime) * 1000;

            $this->logOperationCompleted(
                'order.save',
                $operationId,
                $affectedRows > 0,
                $duration
            );

            $this->recordMetrics(
                'order.save',
                $affectedRows > 0,
                $duration,
                ['order_id' => $order->getId() ?? 'new']
            );

            return $affectedRows > 0;
        } catch (\Exception $e) {
            $duration = (microtime(true) - $startTime) * 1000;

            $this->logOperationFailed('order.save', $operationId, $e, $duration);

            $this->recordErrorMetrics('order.save', $e, $duration, ['order_id' => $order->getId() ?? 'new']);

            throw $e;
        }
    }

    public function updateStatus(string $id, string $status): bool
    {
        $operationId = uniqid('order_status_', true);
        $startTime = microtime(true);

        $this->logOperationStarted('order.update_status', $operationId, [
            'order_id' => $id,
            'new_status' => $status
        ]);

        try {
            $query = "UPDATE orders SET status = ?, updated_at = ? WHERE id = ?";
            $affectedRows = $this->db->execute($query, [$status, time(), $id]);

            $duration = (microtime(true) - $startTime) * 1000;

            $this->logOperationCompleted(
                'order.update_status',
                $operationId,
                $affectedRows > 0,
                $duration
            );

            $this->recordMetrics(
                'order.update_status',
                $affectedRows > 0,
                $duration,
                ['order_id' => $id, 'new_status' => $status]
            );

            return $affectedRows > 0;
        } catch (\Exception $e) {
            $duration = (microtime(true) - $startTime) * 1000;

            $this->logOperationFailed('order.update_status', $operationId, $e, $duration);

            $this->recordErrorMetrics('order.update_status', $e, $duration, ['order_id' => $id]);

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
