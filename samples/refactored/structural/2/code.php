<?php

declare(strict_types=1);

namespace Acme\Common\Service;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

final class TransactionalRunner
{
    public function __construct(
        private readonly Connection $db,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @template T
     * @param callable(Connection): T $work
     * @param array<string, mixed> $logContext
     * @return T
     */
    public function run(string $operation, callable $work, array $logContext = []): mixed
    {
        $this->db->beginTransaction();
        try {
            $result = $work($this->db);
            $this->db->commit();
            $this->logger->info($operation, $logContext);
            return $result;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->logger->error($operation . ' failed', $logContext + ['error' => $e->getMessage()]);
            throw $e;
        }
    }
}

// Usage example:
// $runner->run('role assigned', function (Connection $db) use ($userId, $roleCode, $actorId) {
//     $db->executeStatement('DELETE FROM user_role WHERE user_id = ? AND role_code = ?', [$userId, $roleCode]);
//     $db->executeStatement('INSERT INTO user_role (...) VALUES (...)', [...]);
//     $id = (int) $db->lastInsertId();
//     $db->executeStatement('INSERT INTO user_role_audit (...) VALUES (...)', [...]);
//     return new UserRoleAssignment($id, $userId, $roleCode, $actorId);
// }, ['user_id' => $userId, 'role' => $roleCode]);
