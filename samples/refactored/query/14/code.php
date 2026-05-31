<?php
declare(strict_types=1);

namespace App\Database\Repositories;

use App\Database\Connection;
use App\Logging\LoggerInterface;
use PDO;

interface SoftDeletableRepositoryInterface
{
    public function findActive(array $filters = [], int $page = 1, int $perPage = 25): array;
    public function findById(int $id): ?array;
    public function softDelete(int $id, int $deletedBy): bool;
}

abstract class AbstractSoftDeleteRepository implements SoftDeletableRepositoryInterface
{
    protected PDO $db;
    protected LoggerInterface $logger;

    abstract protected function getTable(): string;
    abstract protected function getSelectColumns(): string;
    abstract protected function getAllowedFilters(): array;
    abstract protected function getSortableFields(): array;

    public function __construct(Connection $connection, LoggerInterface $logger)
    {
        $this->db = $connection->getPdo();
        $this->logger = $logger;
    }

    public function findActive(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $startTime = microtime(true);
        
        $conditions = ['deleted_at IS NULL'];
        $params = [];
        
        foreach ($this->getAllowedFilters() as $filterKey => $column) {
            if (isset($filters[$filterKey]) && $filters[$filterKey] !== '') {
                $value = $filters[$filterKey];
                
                if (is_callable($column)) {
                    $column($value, $conditions, $params);
                } else {
                    if (is_array($value)) {
                        $placeholders = implode(',', array_fill(0, count($value), '?'));
                        $conditions[] = "{$filterKey} IN ({$placeholders})";
                        foreach ($value as $i => $v) {
                            $params[] = $v;
                        }
                    } else {
                        $conditions[] = "{$column} = :{$filterKey}";
                        $params[":{$filterKey}"] = $value;
                    }
                }
            }
        }
        
        $sortField = $filters['sort_by'] ?? $this->getSortableFields()[0];
        $sortDir = strtoupper($filters['sort_dir'] ?? 'DESC');
        if (!in_array($sortField, $this->getSortableFields(), true)) {
            $sortField = $this->getSortableFields()[0];
        }
        if (!in_array($sortDir, ['ASC', 'DESC'], true)) {
            $sortDir = 'DESC';
        }
        
        $whereClause = implode(' AND ', $conditions);
        $offset = ($page - 1) * $perPage;
        
        $countSql = "SELECT COUNT(*) FROM {$this->getTable()} WHERE {$whereClause}";
        $countStmt = $this->db->prepare($countSql);
        foreach ($params as $key => $value) {
            $countStmt->bindValue($key, $value);
        }
        $countStmt->execute();
        $total = (int) $countStmt->fetchColumn();
        
        $sql = "SELECT {$this->getSelectColumns()}
                FROM {$this->getTable()}
                WHERE {$whereClause}
                ORDER BY {$sortField} {$sortDir}
                LIMIT :limit OFFSET :offset";
        
        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $this->logger->info('Active records query executed', [
            'table' => $this->getTable(),
            'filters' => $filters,
            'total' => $total,
            'page' => $page,
            'elapsed_ms' => round((microtime(true) - $startTime) * 1000, 2)
        ]);
        
        return [
            'data' => $data,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) ceil($total / $perPage)
            ]
        ];
    }

    public function findById(int $id): ?array
    {
        $sql = "SELECT {$this->getSelectColumns()}
                FROM {$this->getTable()}
                WHERE id = :id AND deleted_at IS NULL";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function softDelete(int $id, int $deletedBy): bool
    {
        $sql = "UPDATE {$this->getTable()} SET deleted_at = NOW(), deleted_by = :deleted_by 
                WHERE id = :id AND deleted_at IS NULL";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->bindValue(':deleted_by', $deletedBy, PDO::PARAM_INT);
        
        return $stmt->execute();
    }
}
