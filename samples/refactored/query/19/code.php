<?php
declare(strict_types=1);

namespace App\Permissions\Repositories;

use App\Database\Connection;
use App\Logging\LoggerInterface;
use PDO;

abstract class AbstractPermissionRepository
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
        return ['created_at', 'updated_at', 'name', 'status'];
    }

    protected function buildConditions(array $params): array
    {
        $conditions = ['1=1'];
        $bindings = [];
        
        $this->addStatusCondition($params, $conditions, $bindings);
        $this->addTypeCondition($params, $conditions, $bindings);
        $this->addOwnerCondition($params, $conditions, $bindings);
        $this->addDepartmentCondition($params, $conditions, $bindings);
        $this->addPermissionLevelCondition($params, $conditions, $bindings);
        $this->addTimestampConditions($params, $conditions, $bindings);
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

    protected function addTypeCondition(array $params, array &$conditions, array &$bindings): void
    {
        $typeField = $this->getTypeField();
        if (!empty($params[$typeField])) {
            $conditions[] = "{$typeField} = :{$typeField}";
            $bindings[":{$typeField}"] = $params[$typeField];
        }
    }

    protected function getTypeField(): string
    {
        return 'type';
    }

    protected function addOwnerCondition(array $params, array &$conditions, array &$bindings): void
    {
        if (!empty($params['owner_id'])) {
            $conditions[] = 'owner_id = :owner_id';
            $bindings[':owner_id'] = (int)$params['owner_id'];
        }
    }

    protected function addDepartmentCondition(array $params, array &$conditions, array &$bindings): void
    {
        if (!empty($params['department'])) {
            $conditions[] = 'department = :department';
            $bindings[':department'] = $params['department'];
        }
    }

    protected function addPermissionLevelCondition(array $params, array &$conditions, array &$bindings): void
    {
        if (!empty($params['permission_level'])) {
            $conditions[] = 'permission_level >= :permission_level';
            $bindings[':permission_level'] = (int)$params['permission_level'];
        }
    }

    protected function addTimestampConditions(array $params, array &$conditions, array &$bindings): void
    {
        $timestampFields = ['created_at', 'updated_at'];
        foreach ($timestampFields as $field) {
            $prefix = str_replace('_at', '', $field);
            $afterKey = "{$prefix}_after";
            $beforeKey = "{$prefix}_before";
            
            if (!empty($params[$afterKey])) {
                $conditions[] = "{$field} >= :{$afterKey}";
                $bindings[":{$afterKey}"] = $params[$afterKey];
            }
            if (!empty($params[$beforeKey])) {
                $conditions[] = "{$field} <= :{$beforeKey}";
                $bindings[":{$beforeKey}"] = $params[$beforeKey];
            }
        }
    }

    protected function addSearchCondition(array $params, array &$conditions, array &$bindings): void
    {
        if (!empty($params['search'])) {
            $searchColumns = $this->getSearchColumns();
            $conditions[] = '(' . implode(' LIKE :search OR ', $searchColumns) . ' LIKE :search)';
            $bindings[':search'] = '%' . $params['search'] . '%';
        }
    }

    protected function getSearchColumns(): array
    {
        return ['name'];
    }

    protected function addEntitySpecificConditions(array $params, array &$conditions, array &$bindings): void
    {
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
        
        $this->logger->info('Permission entities pagination query executed', [
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

    public function updateScopes(int $id, array $scopes): bool
    {
        $sql = "UPDATE {$this->getTable()} SET 
                    scopes = :scopes,
                    permission_level = :permission_level,
                    updated_at = NOW()
                WHERE id = :id";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->bindValue(':scopes', json_encode($scopes));
        $stmt->bindValue(':permission_level', $scopes['level'] ?? 1, PDO::PARAM_INT);
        
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
