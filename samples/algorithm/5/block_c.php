<?php

declare(strict_types=1);

namespace Acme\AuditLog\Repository;

use Acme\AuditLog\Dto\AuditEntry;
use Acme\AuditLog\Dto\AuditPage;
use PDO;

final class AuditLogRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function listAfter(?string $cursor, int $limit = 100): AuditPage
    {
        $lastSeen = '1970-01-01 00:00:00';
        $lastEntryId = 0;

        if ($cursor !== null && $cursor !== '') {
            $decoded = base64_decode($cursor, true);
            if ($decoded === false) {
                throw new \InvalidArgumentException('Malformed cursor');
            }
            [$lastSeen, $lastEntryId] = explode('|', $decoded, 2);
            $lastEntryId = (int) $lastEntryId;
        }

        $sql = 'SELECT id, actor_id, action, occurred_at FROM audit_log
                WHERE (occurred_at, id) > (:occurred, :id)
                ORDER BY occurred_at ASC, id ASC
                LIMIT :limit';
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':occurred', $lastSeen);
        $stmt->bindValue(':id', $lastEntryId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $items = array_map(
            static fn(array $r): AuditEntry => new AuditEntry((int) $r['id'], (int) $r['actor_id'], $r['action'], $r['occurred_at']),
            $rows,
        );

        $nextCursor = null;
        if (count($items) === $limit) {
            $tail = end($rows);
            $nextCursor = base64_encode($tail['occurred_at'] . '|' . $tail['id']);
        }

        return new AuditPage($items, $nextCursor);
    }
}
