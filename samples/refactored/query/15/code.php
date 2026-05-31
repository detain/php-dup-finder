<?php
declare(strict_types=1);

namespace App\Database\Repositories;

use App\Database\Connection;
use App\Logging\LoggerInterface;
use PDO;

interface FilterableRepositoryInterface
{
    public function findPaginated(array $params = []): array;
    public function findById(int $id): ?array;
}

abstract class AbstractFilterableRepository implements FilterableRepositoryInterface
{
    protected PDO $db;
    protected LoggerInterface $logger;

    abstract protected function getTable(): string;
    abstract protected function getDefaultColumns(): string;
    abstract protected function getAllowedSorts(): array;

    public function __construct(Connection $connection, LoggerInterface $logger)
    {
        $this->db = $connection->getPdo();
        $this->logger = $logger;
    }

    protected function buildFilters(array $params): array
    {
        $conditions = ['deleted_at IS NULL'];
        $bindings = [];
        
        if (!empty($params['search'])) {
            $searchColumns = $this->getSearchColumns();
            $conditions[] = '(' . implode(' LIKE :search OR ', $searchColumns) . ' LIKE :search)';
            $bindings[':search'] = '%' . $params['search'] . '%';
        }
        
        if (!empty($params['created_after'])) {
            $conditions[] = 'created_at >= :created_after';
            $bindings[':created_after'] = $params['created_after'];
        }
        
        if (!empty($params['created_before'])) {
            $conditions[] = 'created_at <= :created_before';
            $bindings[':created_before'] = $params['created_before'];
        }
        
        return [$conditions, $bindings];
    }

    protected function getSearchColumns(): array
    {
        return ['name', 'email'];
    }

    public function findPaginated(array $params = []): array
    {
        $startTime = microtime(true);
        
        $page = max(1, (int)($params['page'] ?? 1));
        $perPage = min(100, max(10, (int)($params['per_page'] ?? 25)));
        $sortBy = $params['sort_by'] ?? $this->getAllowedSorts()[0];
        $sortDir = strtoupper($params['sort_dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
        
        if (!in_array($sortBy, $this->getAllowedSorts(), true)) {
            $sortBy = $this->getAllowedSorts()[0];
        }
        
        [$baseConditions, $baseBindings] = $this->buildFilters($params);
        $conditions = array_merge($baseConditions, $this->buildEntityFilters($params, $baseBindings));
        $bindings = array_merge($baseBindings, $this->buildEntityBindings($params));
        
        $whereClause = implode(' AND ', $conditions);
        $offset = ($page - 1) * $perPage;
        
        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM {$this->getTable()} WHERE {$whereClause}");
        $this->bindValues($countStmt, $bindings);
        $countStmt->execute();
        $totalRecords = (int)$countStmt->fetchColumn();
        
        $query = "
            SELECT {$this->getDefaultColumns()}
            FROM {$this->getTable()}
            WHERE {$whereClause}
            ORDER BY {$sortBy} {$sortDir}
            LIMIT :limit OFFSET :offset
        ";
        
        $stmt = $this->db->prepare($query);
        $this->bindValues($stmt, $bindings);
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $this->logger->info('Paginated query executed', [
            'table' => $this->getTable(),
            'filters' => $params,
            'total' => $totalRecords,
            'page' => $page,
            'duration_ms' => round((microtime(true) - $startTime) * 1000, 2)
        ]);
        
        return [
            'data' => $records,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total_records' => $totalRecords,
                'total_pages' => (int)ceil($totalRecords / $perPage),
                'sort_by' => $sortBy,
                'sort_dir' => $sortDir,
            ]
        ];
    }

    protected function buildEntityFilters(array $params, array $bindings): array
    {
        return [];
    }

    protected function buildEntityBindings(array $params): array
    {
        return [];
    }

    private function bindValues(\PDOStatement $stmt, array $bindings): void
    {
        foreach ($bindings as $key => $value) {
            if (is_int($key)) {
                $stmt->bindValue($key + 1, $value);
            } else {
                $stmt->bindValue($key, $value);
            }
        }
    }
}
