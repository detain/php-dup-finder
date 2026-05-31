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

    public function bulkUpdateStatus(array $userIds, string $status): int
    {
        if (empty($userIds)) {
            return 0;
        }

        $sql = 'UPDATE users SET status = :status, updated_at = :updated_at WHERE id IN (:ids) AND deleted_at IS NULL';

        $affectedRows = $this->connection->executeStatement(
            $sql,
            [
                'status' => $status,
                'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'ids' => $userIds,
            ],
            [
                'ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER,
            ]
        );

        return $affectedRows;
    }

    public function bulkUpdateOrganization(array $userIds, int $organizationId): int
    {
        if (empty($userIds)) {
            return 0;
        }

        $sql = 'UPDATE users SET organization_id = :org_id, updated_at = :updated_at WHERE id IN (:ids) AND deleted_at IS NULL';

        $affectedRows = $this->connection->executeStatement(
            $sql,
            [
                'org_id' => $organizationId,
                'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'ids' => $userIds,
            ],
            [
                'ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER,
            ]
        );

        return $affectedRows;
    }

    public function bulkDelete(array $userIds): int
    {
        if (empty($userIds)) {
            return 0;
        }

        $sql = 'UPDATE users SET deleted_at = :deleted_at WHERE id IN (:ids) AND deleted_at IS NULL';

        $affectedRows = $this->connection->executeStatement(
            $sql,
            [
                'deleted_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'ids' => $userIds,
            ],
            [
                'ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER,
            ]
        );

        return $affectedRows;
    }

    public function bulkAddRoles(array $userIds, array $roleIds): int
    {
        if (empty($userIds) || empty($roleIds)) {
            return 0;
        }

        $insertValues = [];
        $params = [];
        $types = [];
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        foreach ($userIds as $index => $userId) {
            foreach ($roleIds as $roleIndex => $roleId) {
                $insertValues[] = "(:user_id_{$index}_{$roleIndex}, :role_id_{$index}_{$roleIndex}, :created_at)";
                $params["user_id_{$index}_{$roleIndex}"] = $userId;
                $params["role_id_{$index}_{$roleIndex}"] = $roleId;
                $params["created_at"] = $now;
            }
        }

        $sql = 'INSERT INTO user_roles (user_id, role_id, created_at) VALUES ' . implode(', ', $insertValues);

        $this->connection->executeStatement($sql, $params);

        return count($userIds) * count($roleIds);
    }

    public function bulkRemoveRoles(array $userIds, array $roleIds): int
    {
        if (empty($userIds) || empty($roleIds)) {
            return 0;
        }

        $sql = 'DELETE FROM user_roles WHERE user_id IN (:user_ids) AND role_id IN (:role_ids)';

        $affectedRows = $this->connection->executeStatement(
            $sql,
            [
                'user_ids' => $userIds,
                'role_ids' => $roleIds,
            ],
            [
                'user_ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER,
                'role_ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER,
            ]
        );

        return $affectedRows;
    }
}
