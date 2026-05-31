<?php
declare(strict_types=1);

namespace App\Api\Notifications;

use App\Database\PdoFactory;
use PDO;
use PDOException;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class NotificationCursorListing
{
    public function __construct(
        private readonly PdoFactory $pdo,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return array{items: list<array<string, mixed>>, next_cursor: ?int}
     */
    public function list(?int $afterId, int $limit = 25): array
    {
        $limit = max(1, min(200, $limit));
        $afterId = $afterId ?? 0;

        $sql = 'SELECT id, user_id, channel, payload, read_at, created_at
                FROM notifications
                WHERE id > :after
                ORDER BY id ASC
                LIMIT :limit_plus_one';

        try {
            $stmt = $this->pdo->connection()->prepare($sql);
            $stmt->bindValue(':after', $afterId, PDO::PARAM_INT);
            $stmt->bindValue(':limit_plus_one', $limit + 1, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            $this->logger->error('Notification cursor list failed', [
                'after' => $afterId,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException('Unable to list notifications', 0, $e);
        }

        $nextCursor = null;
        if (count($rows) > $limit) {
            $overshoot = array_pop($rows);
            $nextCursor = (int) ($overshoot['id'] ?? 0);
        }

        return ['items' => $rows, 'next_cursor' => $nextCursor];
    }
}
