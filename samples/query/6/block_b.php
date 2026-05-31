<?php
declare(strict_types=1);

namespace App\Api\Messages;

use App\Database\PdoFactory;
use PDO;
use PDOException;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class MessageCursorListing
{
    public function __construct(
        private readonly PdoFactory $pdo,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return array{items: list<array<string, mixed>>, next_cursor: ?int}
     */
    public function list(?int $afterId, int $limit = 100): array
    {
        $limit = max(1, min(200, $limit));
        $afterId = $afterId ?? 0;

        $sql = 'SELECT id, conversation_id, sender_id, body, created_at
                FROM messages
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
            $this->logger->error('Message cursor list failed', [
                'after' => $afterId,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException('Unable to list messages', 0, $e);
        }

        $nextCursor = null;
        if (count($rows) > $limit) {
            $overshoot = array_pop($rows);
            $nextCursor = (int) ($overshoot['id'] ?? 0);
        }

        return ['items' => $rows, 'next_cursor' => $nextCursor];
    }
}
