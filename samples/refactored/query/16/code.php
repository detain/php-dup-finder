<?php
declare(strict_types=1);

namespace App\Queue\Repositories;

use App\Database\Connection;
use App\Logging\LoggerInterface;
use PDO;

abstract class AbstractJobRepository
{
    protected PDO $db;
    protected LoggerInterface $logger;

    abstract protected function getTable(): string;
    abstract protected function getSelectColumns(): string;

    public function __construct(Connection $connection, LoggerInterface $logger)
    {
        $this->db = $connection->getPdo();
        $this->logger = $logger;
    }

    protected function getAllowedSorts(): array
    {
        return ['created_at', 'scheduled_at', 'attempts', 'priority', 'status'];
    }

    protected function buildConditions(array $params): array
    {
        $conditions = ['1=1'];
        $bindings = [];
        
        $this->addStatusCondition($params, $conditions, $bindings);
        $this->addPriorityConditions($params, $conditions, $bindings);
        $this->addAttemptConditions($params, $conditions, $bindings);
        $this->addDateConditions($params, $conditions, $bindings);
        $this->addErrorCondition($params, $conditions);
        $this->addSearchCondition($params, $conditions, $bindings);
        $this->addEntitySpecificConditions($params, $conditions, $bindings);
        
        return [$conditions, $bindings];
    }

    protected function addStatusCondition(array $params, array &$conditions, array &$bindings): void
    {
        if (!empty($params['status'])) {
            $conditions[] = 'status = :status';
            $bindings[':status'] = $params['status'];
        }
    }

    protected function addPriorityConditions(array $params, array &$conditions, array &$bindings): void
    {
        if (!empty($params['priority'])) {
            $conditions[] = 'priority = :priority';
            $bindings[':priority'] = (int)$params['priority'];
        }
    }

    protected function addAttemptConditions(array $params, array &$conditions, array &$bindings): void
    {
        if (isset($params['min_attempts']) && $params['min_attempts'] > 0) {
            $conditions[] = 'attempts >= :min_attempts';
            $bindings[':min_attempts'] = (int)$params['min_attempts'];
        }
        if (isset($params['max_attempts']) && $params['max_attempts'] > 0) {
            $conditions[] = 'attempts <= :max_attempts';
            $bindings[':max_attempts'] = (int)$params['max_attempts'];
        }
    }

    protected function addDateConditions(array $params, array &$conditions, array &$bindings): void
    {
        foreach (['scheduled', 'created'] as $dateType) {
            $afterKey = "{$dateType}_after";
            $beforeKey = "{$dateType}_before";
            
            if (!empty($params[$afterKey])) {
                $conditions[] = "{$dateType}_at >= :{$afterKey}";
                $bindings[":{$afterKey}"] = $params[$afterKey];
            }
            if (!empty($params[$beforeKey])) {
                $conditions[] = "{$dateType}_at <= :{$beforeKey}";
                $bindings[":{$beforeKey}"] = $params[$beforeKey];
            }
        }
    }

    protected function addErrorCondition(array $params, array &$conditions): void
    {
        if (isset($params['has_error']) && $params['has_error']) {
            $conditions[] = 'last_error IS NOT NULL';
        }
    }

    protected function addSearchCondition(array $params, array &$conditions, array &$bindings): void
    {
        if (!empty($params['search'])) {
            $conditions[] = '(job_id LIKE :search OR payload LIKE :search)';
            $bindings[':search'] = '%' . $params['search'] . '%';
        }
    }

    protected function addEntitySpecificConditions(array $params, array &$conditions, array &$bindings): void
    {
    }

    public function findPaginated(array $params = []): array
    {
        $startTime = microtime(true);
        
        $page = max(1, (int)($params['page'] ?? 1));
        $perPage = min(100, max(10, (int)($params['per_page'] ?? 25)));
        $sortBy = $params['sort_by'] ?? 'created_at';
        $sortDir = strtoupper($params['sort_dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
        
        if (!in_array($sortBy, $this->getAllowedSorts(), true)) {
            $sortBy = $this->getAllowedSorts()[0];
        }
        
        [$conditions, $bindings] = $this->buildConditions($params);
        $whereClause = implode(' AND ', $conditions);
        $offset = ($page - 1) * $perPage;
        
        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM {$this->getTable()} WHERE {$whereClause}");
        $this->bindValues($countStmt, $bindings);
        $countStmt->execute();
        $totalRecords = (int)$countStmt->fetchColumn();
        
        $query = "
            SELECT {$this->getSelectColumns()}
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
        
        $this->logger->info('Jobs pagination query executed', [
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

    public function findById(int $id): ?array
    {
        $sql = "SELECT {$this->getSelectColumns()} FROM {$this->getTable()} WHERE id = :id";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function markFailed(int $id, string $error): bool
    {
        $sql = "UPDATE {$this->getTable()} SET 
                    status = 'failed',
                    last_error = :error,
                    attempts = attempts + 1,
                    updated_at = NOW()
                WHERE id = :id";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->bindValue(':error', $error);
        
        return $stmt->execute();
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
