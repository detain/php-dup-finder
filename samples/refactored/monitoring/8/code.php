<?php

declare(strict_types=1);

namespace App\Repository;

trait RepositoryMonitoringTrait
{
    private LoggerInterface $logger;
    private MetricsCollector $metrics;

    protected function monitorOperation(
        string $entity,
        string $operation,
        callable $action,
        array $context = []
    ): mixed {
        $operationId = uniqid("{$entity}_{$operation}_", true);
        $startTime = microtime(true);

        $this->logger->debug("Repository operation started", [
            'operation' => "{$entity}.{$operation}",
            'operation_id' => $operationId,
            ...$context
        ]);

        try {
            $result = $action();
            $duration = (microtime(true) - $startTime) * 1000;

            $success = $this->isSuccessful($result);

            $this->logger->info("Repository operation completed", [
                'operation' => "{$entity}.{$operation}",
                'operation_id' => $operationId,
                'success' => $success,
                'duration_ms' => round($duration, 2)
            ]);

            $this->recordMetrics("{$entity}.{$operation}", $success, $duration, $context);

            return $result;
        } catch (\Exception $e) {
            $duration = (microtime(true) - $startTime) * 1000;

            $this->logger->error("Repository operation failed", [
                'operation' => "{$entity}.{$operation}",
                'operation_id' => $operationId,
                'error' => $e->getMessage(),
                'error_type' => get_class($e),
                'duration_ms' => round($duration, 2)
            ]);

            $this->recordErrorMetrics("{$entity}.{$operation}", $e, $duration, $context);

            throw $e;
        }
    }

    private function recordMetrics(string $operation, bool $success, float $durationMs, array $labels): void
    {
        $this->metrics->incrementCounter(
            'repository_operations_total',
            1,
            array_merge(['operation' => $operation, 'success' => $success ? 'true' : 'false'], $labels)
        );

        $this->metrics->histogram(
            'repository_operation_duration_ms',
            $durationMs,
            array_merge(['operation' => $operation], $labels)
        );
    }

    private function recordErrorMetrics(string $operation, \Exception $error, float $durationMs, array $labels): void
    {
        $this->metrics->incrementCounter(
            'repository_operation_errors_total',
            1,
            array_merge(['operation' => $operation, 'error_type' => get_class($error)], $labels)
        );

        $this->metrics->histogram(
            'repository_operation_error_duration_ms',
            $durationMs,
            array_merge(['operation' => $operation], $labels)
        );
    }

    private function isSuccessful(mixed $result): bool
    {
        if (is_bool($result)) {
            return $result;
        }

        if ($result === null) {
            return false;
        }

        if (is_countable($result)) {
            return count($result) > 0;
        }

        return true;
    }
}

abstract class AbstractRepository
{
    use RepositoryMonitoringTrait;

    protected DatabaseConnection $db;

    abstract protected function getEntityName(): string;

    protected function findById(string $id): ?array
    {
        return $this->monitorOperation(
            $this->getEntityName(),
            'find_by_id',
            fn() => $this->db->query("SELECT * FROM {$this->getTableName()} WHERE id = ?", [$id]),
            ['id' => $id]
        );
    }

    protected function save(array $data): bool
    {
        return $this->monitorOperation(
            $this->getEntityName(),
            'save',
            fn() => $this->executeSave($data),
            ['id' => $data['id'] ?? 'new']
        );
    }

    abstract protected function getTableName(): string;
    abstract protected function executeSave(array $data): bool;
}
