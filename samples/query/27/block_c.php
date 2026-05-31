<?php

declare(strict_types=1);

namespace App\Repositories;

use Doctrine\DBAL\Connection;

trait BulkOperationsTrait
{
    abstract protected function getTableName(): string;

    protected function bulkUpdateField(Connection $connection, array $ids, string $field, mixed $value): int
    {
        if (empty($ids)) {
            return 0;
        }

        $sql = "UPDATE {$this->getTableName()} SET {$field} = :value, updated_at = :updated_at WHERE id IN (:ids) AND deleted_at IS NULL";

        return $connection->executeStatement(
            $sql,
            [
                'value' => $value,
                'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'ids' => $ids,
            ],
            [
                'ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER,
            ]
        );
    }

    protected function bulkSoftDelete(Connection $connection, array $ids): int
    {
        if (empty($ids)) {
            return 0;
        }

        $sql = "UPDATE {$this->getTableName()} SET deleted_at = :deleted_at WHERE id IN (:ids) AND deleted_at IS NULL";

        return $connection->executeStatement(
            $sql,
            [
                'deleted_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'ids' => $ids,
            ],
            [
                'ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER,
            ]
        );
    }

    protected function bulkInsertRelations(
        Connection $connection,
        string $table,
        string $foreignKey,
        string $relatedKey,
        array $foreignIds,
        array $relatedIds
    ): int {
        if (empty($foreignIds) || empty($relatedIds)) {
            return 0;
        }

        $insertValues = [];
        $params = [];
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        foreach ($foreignIds as $fIndex => $foreignId) {
            foreach ($relatedIds as $rIndex => $relatedId) {
                $paramKey = "fk_{$fIndex}_{$rIndex}";
                $relKey = "rk_{$fIndex}_{$rIndex}";
                $insertValues[] = "(:{$paramKey}, :{$relKey}, :created_at)";
                $params[$paramKey] = $foreignId;
                $params[$relKey] = $relatedId;
            }
        }
        $params['created_at'] = $now;

        $sql = "INSERT INTO {$table} ({$foreignKey}, {$relatedKey}, created_at) VALUES " . implode(', ', $insertValues);

        $connection->executeStatement($sql, $params);

        return count($foreignIds) * count($relatedIds);
    }
}

class UserRepository
{
    use BulkOperationsTrait;

    protected function getTableName(): string
    {
        return 'users';
    }

    public function bulkUpdateStatus(array $userIds, string $status): int
    {
        return $this->bulkUpdateField($this->connection, $userIds, 'status', $status);
    }
}
