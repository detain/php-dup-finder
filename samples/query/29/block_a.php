<?php

declare(strict_types=1);

namespace App\Repositories;

use Doctrine\DBAL\Connection;

class UserRepository
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public function exportUsersCsv(array $filters = []): array
    {
        $sql = "SELECT
                    u.id,
                    u.email,
                    u.name,
                    u.phone,
                    u.status,
                    u.tier,
                    o.name as organization_name,
                    u.created_at,
                    u.updated_at,
                    u.last_login_at
                FROM users u
                LEFT JOIN organizations o ON u.organization_id = o.id
                WHERE u.deleted_at IS NULL";

        $params = [];

        if (!empty($filters['status'])) {
            $sql .= " AND u.status = :status";
            $params['status'] = $filters['status'];
        }

        if (!empty($filters['organization_id'])) {
            $sql .= " AND u.organization_id = :org_id";
            $params['org_id'] = $filters['organization_id'];
        }

        if (!empty($filters['created_after'])) {
            $sql .= " AND u.created_at >= :created_after";
            $params['created_after'] = $filters['created_after'];
        }

        if (!empty($filters['created_before'])) {
            $sql .= " AND u.created_at <= :created_before";
            $params['created_before'] = $filters['created_before'];
        }

        if (!empty($filters['role'])) {
            $sql .= " AND u.id IN (
                        SELECT ur.user_id FROM user_roles ur
                        JOIN roles r ON ur.role_id = r.id
                        WHERE r.slug = :role
                     )";
            $params['role'] = $filters['role'];
        }

        $sql .= " ORDER BY u.created_at DESC";

        if (!empty($filters['limit'])) {
            $sql .= " LIMIT :limit";
            $params['limit'] = $filters['limit'];
        }

        return $this->connection->fetchAllAssociative($sql, $params);
    }

    public function exportUsersJson(array $filters = []): array
    {
        $users = $this->exportUsersCsv($filters);

        return array_map(function ($user) {
            return [
                'id' => (int) $user['id'],
                'email' => $user['email'],
                'name' => $user['name'],
                'phone' => $user['phone'],
                'status' => $user['status'],
                'tier' => $user['tier'],
                'organization' => $user['organization_name'],
                'created_at' => $user['created_at'],
                'updated_at' => $user['updated_at'],
                'last_login' => $user['last_login_at'],
                'roles' => $this->getUserRoles((int) $user['id']),
                'permissions' => $this->getUserPermissions((int) $user['id']),
            ];
        }, $users);
    }

    private function getUserRoles(int $userId): array
    {
        $sql = "SELECT r.slug FROM roles r
                JOIN user_roles ur ON r.id = ur.role_id
                WHERE ur.user_id = :user_id";

        $roles = $this->connection->fetchAllAssociative($sql, ['user_id' => $userId]);

        return array_column($roles, 'slug');
    }

    private function getUserPermissions(int $userId): array
    {
        $sql = "SELECT DISTINCT p.slug FROM permissions p
                JOIN role_permissions rp ON p.id = rp.permission_id
                JOIN user_roles ur ON rp.role_id = ur.role_id
                WHERE ur.user_id = :user_id";

        $permissions = $this->connection->fetchAllAssociative($sql, ['user_id' => $userId]);

        return array_column($permissions, 'slug');
    }
}
