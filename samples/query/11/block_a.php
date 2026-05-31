<?php
declare(strict_types=1);

namespace App\Ecommerce\Repositories;

use App\Database\Connection;
use App\Logging\LoggerInterface;
use PDO;

final class UserRepository
{
    private PDO $db;
    private LoggerInterface $logger;

    public function __construct(Connection $connection, LoggerInterface $logger)
    {
        $this->db = $connection->getPdo();
        $this->logger = $logger;
    }

    /**
     * Find all active users with optional filters and pagination.
     * Only returns users where deleted_at IS NULL.
     */
    public function findActiveUsers(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $startTime = microtime(true);
        
        $conditions = ['deleted_at IS NULL'];
        $params = [];
        
        if (isset($filters['role']) && $filters['role'] !== '') {
            $conditions[] = 'role = :role';
            $params[':role'] = $filters['role'];
        }
        
        if (isset($filters['status']) && $filters['status'] !== '') {
            $conditions[] = 'status = :status';
            $params[':status'] = $filters['status'];
        }
        
        if (isset($filters['search']) && $filters['search'] !== '') {
            $conditions[] = '(email LIKE :search OR full_name LIKE :search)';
            $params[':search'] = '%' . $filters['search'] . '%';
        }
        
        if (isset($filters['created_after'])) {
            $conditions[] = 'created_at >= :created_after';
            $params[':created_after'] = $filters['created_after'];
        }
        
        if (isset($filters['created_before'])) {
            $conditions[] = 'created_at <= :created_before';
            $params[':created_before'] = $filters['created_before'];
        }
        
        $whereClause = implode(' AND ', $conditions);
        
        $offset = ($page - 1) * $perPage;
        
        $countSql = "SELECT COUNT(*) FROM users WHERE {$whereClause}";
        $stmt = $this->db->prepare($countSql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        $total = (int) $stmt->fetchColumn();
        
        $sql = "SELECT id, email, full_name, role, status, created_at, last_login_at 
                FROM users 
                WHERE {$whereClause}
                ORDER BY created_at DESC
                LIMIT :limit OFFSET :offset";
        
        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $elapsed = microtime(true) - $startTime;
        $this->logger->info('Active users query executed', [
            'filters' => $filters,
            'total' => $total,
            'page' => $page,
            'elapsed_ms' => round($elapsed * 1000, 2)
        ]);
        
        return [
            'data' => $users,
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
        $sql = "SELECT id, email, full_name, role, status, created_at, last_login_at 
                FROM users 
                WHERE id = :id AND deleted_at IS NULL";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function softDelete(int $id, int $deletedBy): bool
    {
        $sql = "UPDATE users SET deleted_at = NOW(), deleted_by = :deleted_by WHERE id = :id AND deleted_at IS NULL";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->bindValue(':deleted_by', $deletedBy, PDO::PARAM_INT);
        
        $result = $stmt->execute();
        
        if ($result) {
            $this->logger->warning('User soft deleted', ['user_id' => $id, 'deleted_by' => $deletedBy]);
        }
        
        return $result;
    }
}
