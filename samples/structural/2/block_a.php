<?php

declare(strict_types=1);

namespace Acme\Iam\Service;

use Doctrine\DBAL\Connection;
use Acme\Iam\Entity\UserRoleAssignment;
use Psr\Log\LoggerInterface;

final class RoleAssignmentService
{
    public function __construct(
        private readonly Connection $db,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function assign(int $userId, string $roleCode, int $actorId): UserRoleAssignment
    {
        $this->db->beginTransaction();
        try {
            $this->db->executeStatement(
                'DELETE FROM user_role WHERE user_id = ? AND role_code = ?',
                [$userId, $roleCode],
            );

            $this->db->executeStatement(
                'INSERT INTO user_role (user_id, role_code, granted_by, granted_at) VALUES (?, ?, ?, NOW())',
                [$userId, $roleCode, $actorId],
            );

            $id = (int) $this->db->lastInsertId();

            $this->db->executeStatement(
                'INSERT INTO user_role_audit (user_role_id, action, actor_id) VALUES (?, ?, ?)',
                [$id, 'grant', $actorId],
            );

            $this->db->commit();
            $this->logger->info('role assigned', ['user_id' => $userId, 'role' => $roleCode]);

            return new UserRoleAssignment($id, $userId, $roleCode, $actorId);
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->logger->error('role assignment failed', ['error' => $e->getMessage(), 'user_id' => $userId]);
            throw $e;
        }
    }
}
