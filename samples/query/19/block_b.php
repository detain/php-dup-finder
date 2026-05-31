<?php
declare(strict_types=1);

namespace App\Permissions\Repositories;

use App\Database\Connection;
use App\Logging\LoggerInterface;
use PDO;

final class ApiKeyRepository
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
        
        $allowedSorts = ['created_at', 'updated_at', 'name', 'last_used_at', 'status'];
        if (!in_array($sortBy, $allowedSorts, true)) {
            $sortBy = 'created_at';
        }
        
        $conditions = ['1=1'];
        $bindings = [];
        
        if (!empty($params['status'])) {
            $conditions[] = 'status = :status';
            $bindings[':status'] = $params['status'];
        }
        
        if (!empty($params['key_type'])) {
            $conditions[] = 'key_type = :key_type';
            $bindings[':key_type'] = $params['key_type'];
        }
        
        if (!empty($params['user_id'])) {
            $conditions[] = 'user_id = :user_id';
            $bindings[':user_id'] = (int)$params['user_id'];
        }
        
        if (!empty($params['service'])) {
            $conditions[] = 'service = :service';
            $bindings[':service'] = $params['service'];
        }
        
        if (!empty($params['environment'])) {
            $conditions[] = 'environment = :environment';
            $bindings[':environment'] = $params['environment'];
        }
        
        if (!empty($params['permission_level'])) {
            $conditions[] = 'permission_level >= :permission_level';
            $bindings[':permission_level'] = (int)$params['permission_level'];
        }
        
        if (!empty($params['has_expiry'])) {
            $conditions[] = $params['has_expiry'] ? 'expires_at IS NOT NULL' : 'expires_at IS NULL';
        }
        
        if (!empty($params['is_active'])) {
            $conditions[] = $params['is_active'] ? 'status = "active"' : 'status != "active"';
        }
        
        if (!empty($params['created_after'])) {
            $conditions[] = 'created_at >= :created_after';
            $bindings[':created_after'] = $params['created_after'];
        }
        
        if (!empty($params['created_before'])) {
            $conditions[] = 'created_at <= :created_before';
            $bindings[':created_before'] = $params['created_before'];
        }
        
        if (!empty($params['last_used_after'])) {
            $conditions[] = 'last_used_at >= :last_used_after';
            $bindings[':last_used_after'] = $params['last_used_after'];
        }
        
        if (!empty($params['last_used_before'])) {
            $conditions[] = 'last_used_at <= :last_used_before';
            $bindings[':last_used_before'] = $params['last_used_before'];
        }
        
        if (!empty($params['search'])) {
            $conditions[] = '(name LIKE :search OR key_prefix LIKE :search)';
            $bindings[':search'] = '%' . $params['search'] . '%';
        }
        
        $whereClause = implode(' AND ', $conditions);
        $offset = ($page - 1) * $perPage;
        
        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM api_keys WHERE {$whereClause}");
        $this->bindValues($countStmt, $bindings);
        $countStmt->execute();
        $totalRecords = (int)$countStmt->fetchColumn();
        
        $query = "
            SELECT id, user_id, name, key_type, service, environment,
                   permission_level, status, last_used_at, expires_at,
                   created_at, updated_at
            FROM api_keys
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
        
        $this->logger->info('API keys pagination query executed', [
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
        $sql = "SELECT id, user_id, name, key_type, service, environment,
                       permission_level, status, scopes, last_used_at,
                       expires_at, created_at
                FROM api_keys 
                WHERE id = :id";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function updateScopes(int $id, array $scopes): bool
    {
        $sql = "UPDATE api_keys SET 
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
