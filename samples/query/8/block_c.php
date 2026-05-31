<?php
declare(strict_types=1);

namespace App\Records\Audit;

use App\Auth\ViewerContext;
use PDO;
use PDOException;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class AuditLogRepository
{
    private const ROLE_VISIBILITY = [
        'admin'     => ['public', 'restricted', 'sensitive'],
        'auditor'   => ['public', 'restricted'],
        'manager'   => ['public'],
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly ViewerContext $viewer,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findForActor(int $actorId): array
    {
        $role = $this->viewer->role();
        $visibilities = self::ROLE_VISIBILITY[$role] ?? ['public'];
        $placeholders = implode(',', array_map(static fn (int $i): string => ":vis{$i}", array_keys($visibilities)));

        $sql = "SELECT id, actor_id, action, occurred_at, visibility
                FROM audit_logs
                WHERE deleted_at IS NULL
                  AND actor_id = :actor_id
                  AND visibility IN ({$placeholders})
                ORDER BY occurred_at DESC";

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':actor_id', $actorId, PDO::PARAM_INT);
            foreach ($visibilities as $i => $v) {
                $stmt->bindValue(":vis{$i}", $v);
            }
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            $this->logger->error('Audit log query failed', [
                'actor_id' => $actorId,
                'role' => $role,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException('Unable to load audit logs', 0, $e);
        }
    }
}
