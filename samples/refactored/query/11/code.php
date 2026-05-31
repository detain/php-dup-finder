<?php
declare(strict_types=1);

namespace App\Database\Repositories;

use App\Database\Connection;
use App\Logging\LoggerInterface;
use App\Cache\CacheManager;
use PDO;

trait SoftDeleteQueryBuilder
{
    protected function buildActiveRecordsQuery(
        string $table,
        array $filters,
        array $allowedFilters,
        int $page,
        int $perPage,
        array $sortableFields = ['created_at'],
        string $defaultSortField = 'created_at',
        string $defaultSortDir = 'DESC'
    ): array {
        $conditions = ['deleted_at IS NULL'];
        $params = [];
        
        foreach ($allowedFilters as $filterKey => $dbColumn) {
            if (isset($filters[$filterKey]) && $filters[$filterKey] !== '') {
                if (is_callable($dbColumn)) {
                    $result = $dbColumn($filters[$filterKey], $conditions, $params);
                    if ($result !== null) {
                        $params = $result;
                    }
                } else {
                    $conditions[] = "{$dbColumn} = :{$filterKey}";
                    $params[":{$filterKey}"] = $filters[$filterKey];
                }
            }
        }
        
        if (isset($filters['search']) && $filters['search'] !== '') {
            $conditions[] = '(name LIKE :search OR sku LIKE :search OR description LIKE :search)';
            $params[':search'] = '%' . $filters['search'] . '%';
        }
        
        $whereClause = implode(' AND ', $conditions);
        $offset = ($page - 1) * $perPage;
        
        return [
            'conditions' => $conditions,
            'params' => $params,
            'where' => $whereClause,
            'offset' => $offset
        ];
    }

    protected function paginate(
        string $table,
        string $whereClause,
        array $params,
        int $page,
        int $perPage,
        string $sortField = 'created_at',
        string $sortDir = 'DESC',
        array $allowedSorts = ['created_at']
    ): array {
        if (!in_array($sortField, $allowedSorts, true)) {
            $sortField = $allowedSorts[0];
        }
        $sortDir = in_array(strtoupper($sortDir), ['ASC', 'DESC']) ? strtoupper($sortDir) : 'DESC';
        
        $countSql = "SELECT COUNT(*) FROM {$table} WHERE {$whereClause}";
        $stmt = $this->db->prepare($countSql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        $total = (int) $stmt->fetchColumn();
        
        $sql = "SELECT * FROM {$table} 
                WHERE {$whereClause}
                ORDER BY {$sortField} {$sortDir}
                LIMIT :limit OFFSET :offset";
        
        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $stmt->execute();
        
        return [
            'data' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) ceil($total / $perPage)
            ]
        ];
    }
}

abstract class AbstractSoftDeleteRepository
{
    use SoftDeleteQueryBuilder;

    protected PDO $db;
    protected LoggerInterface $logger;
    protected ?CacheManager $cache = null;

    abstract protected function getTable(): string;
    abstract protected function getAllowedFilters(): array;
    abstract protected function getSortableFields(): array;

    public function findActive(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $startTime = microtime(true);
        
        $query = $this->buildActiveRecordsQuery(
            $this->getTable(),
            $filters,
            $this->getAllowedFilters(),
            $page,
            $perPage,
            $this->getSortableFields()
        );
        
        $result = $this->paginate(
            $this->getTable(),
            $query['where'],
            $query['params'],
            $page,
            $perPage,
            $filters['sort_by'] ?? 'created_at',
            $filters['sort_dir'] ?? 'DESC',
            $this->getSortableFields()
        );
        
        $this->logger->info('Active records query executed', [
            'table' => $this->getTable(),
            'filters' => $filters,
            'total' => $result['pagination']['total'],
            'page' => $page,
            'elapsed_ms' => round((microtime(true) - $startTime) * 1000, 2)
        ]);
        
        return $result;
    }

    public function findById(int $id): ?array
    {
        $sql = "SELECT * FROM {$this->getTable()} WHERE id = :id AND deleted_at IS NULL";
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
        
        $result = $stmt->execute();
        
        if ($result && $this->cache) {
            $this->cache->invalidatePattern($this->getTable() . ':*');
        }
        
        return $result;
    }
}
