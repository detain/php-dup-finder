<?php

declare(strict_types=1);

namespace App\Repository;

class UserRepository
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

    public function findById(string $id): ?User
    {
        $operationId = uniqid('user_find_', true);
        $startTime = microtime(true);

        $this->logOperationStarted('user.find_by_id', $operationId, ['user_id' => $id]);

        try {
            $query = "SELECT * FROM users WHERE id = ?";
            $result = $this->db->query($query, [$id]);

            $duration = (microtime(true) - $startTime) * 1000;

            $this->logOperationCompleted(
                'user.find_by_id',
                $operationId,
                $result !== null,
                $duration
            );

            $this->recordMetrics(
                'user.find_by_id',
                $result !== null,
                $duration,
                ['user_id' => $id]
            );

            if ($result === null) {
                return null;
            }

            return User::fromRow($result);
        } catch (\Exception $e) {
            $duration = (microtime(true) - $startTime) * 1000;

            $this->logOperationFailed('user.find_by_id', $operationId, $e, $duration);

            $this->recordErrorMetrics('user.find_by_id', $e, $duration, ['user_id' => $id]);

            throw $e;
        }
    }

    public function findByEmail(string $email): ?User
    {
        $operationId = uniqid('user_find_', true);
        $startTime = microtime(true);

        $this->logOperationStarted('user.find_by_email', $operationId, ['email' => $email]);

        try {
            $query = "SELECT * FROM users WHERE email = ?";
            $result = $this->db->query($query, [$email]);

            $duration = (microtime(true) - $startTime) * 1000;

            $this->logOperationCompleted(
                'user.find_by_email',
                $operationId,
                $result !== null,
                $duration
            );

            $this->recordMetrics(
                'user.find_by_email',
                $result !== null,
                $duration,
                ['email_hash' => md5($email)]
            );

            if ($result === null) {
                return null;
            }

            return User::fromRow($result);
        } catch (\Exception $e) {
            $duration = (microtime(true) - $startTime) * 1000;

            $this->logOperationFailed('user.find_by_email', $operationId, $e, $duration);

            $this->recordErrorMetrics('user.find_by_email', $e, $duration, ['email_hash' => md5($email)]);

            throw $e;
        }
    }

    public function save(User $user): bool
    {
        $operationId = uniqid('user_save_', true);
        $startTime = microtime(true);

        $this->logOperationStarted('user.save', $operationId, ['user_id' => $user->getId()]);

        try {
            if ($user->getId() === null) {
                $query = "INSERT INTO users (email, name, created_at) VALUES (?, ?, ?)";
                $params = [$user->getEmail(), $user->getName(), time()];
            } else {
                $query = "UPDATE users SET email = ?, name = ?, updated_at = ? WHERE id = ?";
                $params = [$user->getEmail(), $user->getName(), time(), $user->getId()];
            }

            $affectedRows = $this->db->execute($query, $params);

            $duration = (microtime(true) - $startTime) * 1000;

            $this->logOperationCompleted(
                'user.save',
                $operationId,
                $affectedRows > 0,
                $duration
            );

            $this->recordMetrics(
                'user.save',
                $affectedRows > 0,
                $duration,
                ['user_id' => $user->getId() ?? 'new']
            );

            return $affectedRows > 0;
        } catch (\Exception $e) {
            $duration = (microtime(true) - $startTime) * 1000;

            $this->logOperationFailed('user.save', $operationId, $e, $duration);

            $this->recordErrorMetrics('user.save', $e, $duration, ['user_id' => $user->getId() ?? 'new']);

            throw $e;
        }
    }

    public function delete(string $id): bool
    {
        $operationId = uniqid('user_delete_', true);
        $startTime = microtime(true);

        $this->logOperationStarted('user.delete', $operationId, ['user_id' => $id]);

        try {
            $query = "DELETE FROM users WHERE id = ?";
            $affectedRows = $this->db->execute($query, [$id]);

            $duration = (microtime(true) - $startTime) * 1000;

            $this->logOperationCompleted(
                'user.delete',
                $operationId,
                $affectedRows > 0,
                $duration
            );

            $this->recordMetrics('user.delete', $affectedRows > 0, $duration, ['user_id' => $id]);

            return $affectedRows > 0;
        } catch (\Exception $e) {
            $duration = (microtime(true) - $startTime) * 1000;

            $this->logOperationFailed('user.delete', $operationId, $e, $duration);

            $this->recordErrorMetrics('user.delete', $e, $duration, ['user_id' => $id]);

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
