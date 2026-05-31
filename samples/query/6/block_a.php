<?php
declare(strict_types=1);

namespace App\Api\Events;

use App\Database\PdoFactory;
use PDO;
use PDOException;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class EventCursorListing
{
    public function __construct(
        private readonly PdoFactory $pdo,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return array{items: list<array<string, mixed>>, next_cursor: ?int}
     */
    public function list(?int $afterId, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $afterId = $afterId ?? 0;

        $sql = 'SELECT id, type, payload, created_at
                FROM events
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
            $this->logger->error('Event cursor list failed', [
                'after' => $afterId,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException('Unable to list events', 0, $e);
        }

        $nextCursor = null;
        if (count($rows) > $limit) {
            $overshoot = array_pop($rows);
            $nextCursor = (int) ($overshoot['id'] ?? 0);
        }

        return ['items' => $rows, 'next_cursor' => $nextCursor];
    }
}
