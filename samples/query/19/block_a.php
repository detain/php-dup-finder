<?php
declare(strict_types=1);

namespace App\Permissions\Repositories;

use App\Database\Connection;
use App\Logging\LoggerInterface;
use PDO;

final class UserPermissionRepository
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
        
        $allowedSorts = ['created_at', 'updated_at', 'name', 'email', 'status'];
        if (!in_array($sortBy, $allowedSorts, true)) {
            $sortBy = 'created_at';
        }
        
        $conditions = ['1=1'];
        $bindings = [];
        
        if (!empty($params['status'])) {
            $conditions[] = 'status = :status';
            $bindings[':status'] = $params['status'];
        }
        
        if (!empty($params['role'])) {
            $conditions[] = 'role = :role';
            $bindings[':role'] = $params['role'];
        }
        
        if (!empty($params['department'])) {
            $conditions[] = 'department = :department';
            $bindings[':department'] = $params['department'];
        }
        
        if (!empty($params['team_id'])) {
            $conditions[] = 'team_id = :team_id';
            $bindings[':team_id'] = (int)$params['team_id'];
        }
        
        if (!empty($params['location'])) {
            $conditions[] = 'location = :location';
            $bindings[':location'] = $params['location'];
        }
        
        if (!empty($params['permission_level'])) {
            $conditions[] = 'permission_level >= :permission_level';
            $bindings[':permission_level'] = (int)$params['permission_level'];
        }
        
        if (!empty($params['has_mfa'])) {
            $conditions[] = $params['has_mfa'] ? 'mfa_enabled = 1' : 'mfa_enabled = 0';
        }
        
        if (!empty($params['email_domain'])) {
            $conditions[] = 'email LIKE :email_domain';
            $bindings[':email_domain'] = '%@' . $params['email_domain'];
        }
        
        if (!empty($params['created_after'])) {
            $conditions[] = 'created_at >= :created_after';
            $bindings[':created_after'] = $params['created_after'];
        }
        
        if (!empty($params['created_before'])) {
            $conditions[] = 'created_at <= :created_before';
            $bindings[':created_before'] = $params['created_before'];
        }
        
        if (!empty($params['last_login_after'])) {
            $conditions[] = 'last_login_at >= :last_login_after';
            $bindings[':last_login_after'] = $params['last_login_after'];
        }
        
        if (!empty($params['last_login_before'])) {
            $conditions[] = 'last_login_at <= :last_login_before';
            $bindings[':last_login_before'] = $params['last_login_before'];
        }
        
        if (!empty($params['search'])) {
            $conditions[] = '(name LIKE :search OR email LIKE :search OR employee_id LIKE :search)';
            $bindings[':search'] = '%' . $params['search'] . '%';
        }
        
        $whereClause = implode(' AND ', $conditions);
        $offset = ($page - 1) * $perPage;
        
        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM users WHERE {$whereClause}");
        $this->bindValues($countStmt, $bindings);
        $countStmt->execute();
        $totalRecords = (int)$countStmt->fetchColumn();
        
        $query = "
            SELECT id, employee_id, name, email, role, department, team_id,
                   location, permission_level, mfa_enabled, status,
                   last_login_at, created_at, updated_at
            FROM users
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
        
        $this->logger->info('Users pagination query executed', [
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
        $sql = "SELECT id, employee_id, name, email, role, department, team_id,
                       location, permission_level, mfa_enabled, status,
                       employee_type, start_date, last_login_at, created_at
                FROM users 
                WHERE id = :id";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function updatePermissions(int $id, array $permissions): bool
    {
        $sql = "UPDATE users SET 
                    permissions = :permissions,
                    permission_level = :permission_level,
                    updated_at = NOW()
                WHERE id = :id";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->bindValue(':permissions', json_encode($permissions));
        $stmt->bindValue(':permission_level', $permissions['level'] ?? 1, PDO::PARAM_INT);
        
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
