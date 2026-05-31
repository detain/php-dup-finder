<?php
declare(strict_types=1);

namespace App\Permissions\Repositories;

use App\Database\Connection;
use App\Logging\LoggerInterface;
use PDO;

final class GroupPermissionRepository
{
    private PDO $db;
    private LoggerInterface $logger;

    public function __construct(Connection $connection, LoggerInterface $logger)
    {
        $this->db = $connection->getPdo();
        $this->logger = $logger;
    }

    public function findPaginated(array $params = []): array
    {
        $startTime = microtime(true);
        
        $page = max(1, (int)($params['page'] ?? 1));
        $perPage = min(100, max(10, (int)($params['per_page'] ?? 25)));
        $sortBy = $params['sort_by'] ?? 'created_at';
        $sortDir = strtoupper($params['sort_dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
        
        $allowedSorts = ['created_at', 'updated_at', 'name', 'member_count', 'status'];
        if (!in_array($sortBy, $allowedSorts, true)) {
            $sortBy = 'created_at';
        }
        
        $conditions = ['1=1'];
        $bindings = [];
        
        if (!empty($params['status'])) {
            $conditions[] = 'status = :status';
            $bindings[':status'] = $params['status'];
        }
        
        if (!empty($params['group_type'])) {
            $conditions[] = 'group_type = :group_type';
            $bindings[':group_type'] = $params['group_type'];
        }
        
        if (!empty($params['owner_id'])) {
            $conditions[] = 'owner_id = :owner_id';
            $bindings[':owner_id'] = (int)$params['owner_id'];
        }
        
        if (!empty($params['department'])) {
            $conditions[] = 'department = :department';
            $bindings[':department'] = $params['department'];
        }
        
        if (!empty($params['permission_level'])) {
            $conditions[] = 'permission_level >= :permission_level';
            $bindings[':permission_level'] = (int)$params['permission_level'];
        }
        
        if (!empty($params['is_system'])) {
            $conditions[] = $params['is_system'] ? 'is_system = 1' : 'is_system = 0';
        }
        
        if (!empty($params['has_members'])) {
            $conditions[] = $params['has_members'] ? 'member_count > 0' : 'member_count = 0';
        }
        
        if (!empty($params['created_after'])) {
            $conditions[] = 'created_at >= :created_after';
            $bindings[':created_after'] = $params['created_after'];
        }
        
        if (!empty($params['created_before'])) {
            $conditions[] = 'created_at <= :created_before';
            $bindings[':created_before'] = $params['created_before'];
        }
        
        if (!empty($params['modified_after'])) {
            $conditions[] = 'updated_at >= :modified_after';
            $bindings[':modified_after'] = $params['modified_after'];
        }
        
        if (!empty($params['modified_before'])) {
            $conditions[] = 'updated_at <= :modified_before';
            $bindings[':modified_before'] = $params['modified_before'];
        }
        
        if (!empty($params['search'])) {
            $conditions[] = '(name LIKE :search OR description LIKE :search)';
            $bindings[':search'] = '%' . $params['search'] . '%';
        }
        
        $whereClause = implode(' AND ', $conditions);
        $offset = ($page - 1) * $perPage;
        
        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM permission_groups WHERE {$whereClause}");
        $this->bindValues($countStmt, $bindings);
        $countStmt->execute();
        $totalRecords = (int)$countStmt->fetchColumn();
        
        $query = "
            SELECT id, owner_id, name, description, group_type, department,
                   permission_level, is_system, status, member_count,
                   created_at, updated_at
            FROM permission_groups
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
        
        $this->logger->info('Permission groups pagination query executed', [
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
        $sql = "SELECT id, owner_id, name, description, group_type, department,
                       permission_level, is_system, status, scopes, member_count,
                       created_at, updated_at
                FROM permission_groups 
                WHERE id = :id";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function updateScopes(int $id, array $scopes): bool
    {
        $sql = "UPDATE permission_groups SET 
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
