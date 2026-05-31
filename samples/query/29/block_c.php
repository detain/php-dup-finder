<?php

declare(strict_types=1);

namespace App\Repositories;

use Doctrine\DBAL\Connection;

trait DataExportTrait
{
    abstract protected function getExportTableName(): string;

    abstract protected function getExportColumns(): array;

    protected function buildExportQuery(Connection $connection, array $filters = []): array
    {
        $table = $this->getExportTableName();
        $columns = $this->getExportColumns();

        $sql = "SELECT " . implode(', ', $columns) . " FROM {$table} WHERE deleted_at IS NULL";

        $params = [];
        $filterHandlers = [
            'status' => fn($v) => ["{$table}.status = :status", ['status' => $v]],
            'organization_id' => fn($v) => ["{$table}.organization_id = :org_id", ['org_id' => $v]],
            'created_after' => fn($v) => ["{$table}.created_at >= :created_after", ['created_after' => $v]],
            'created_before' => fn($v) => ["{$table}.created_at <= :created_before", ['created_before' => $v]],
        ];

        foreach ($filterHandlers as $key => $handler) {
            if (!empty($filters[$key])) {
                [$condition, $extraParams] = $handler($filters[$key]);
                $sql .= " AND " . $condition;
                $params = array_merge($params, $extraParams);
            }
        }

        $sql .= " ORDER BY {$table}.created_at DESC";

        if (!empty($filters['limit'])) {
            $sql .= " LIMIT :limit";
            $params['limit'] = $filters['limit'];
        }

        return [$sql, $params];
    }

    protected function exportData(Connection $connection, array $filters = []): array
    {
        [$sql, $params] = $this->buildExportQuery($connection, $filters);
        return $connection->fetchAllAssociative($sql, $params);
    }
}

class UserRepository
{
    use DataExportTrait;

    protected function getExportTableName(): string
    {
        return 'users';
    }

    protected function getExportColumns(): array
    {
        return [
            'u.id',
            'u.email',
            'u.name',
            'u.phone',
            'u.status',
            'u.tier',
        ];
    }

    public function exportUsersCsv(array $filters = []): array
    {
        return $this->exportData($this->connection, $filters);
    }
}
