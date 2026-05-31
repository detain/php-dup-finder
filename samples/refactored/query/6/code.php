<?php
declare(strict_types=1);

namespace App\Api\Cursor;

use App\Database\PdoFactory;
use PDO;
use PDOException;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class CursorPaginator
{
    public function __construct(
        private readonly PdoFactory $pdo,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param list<string> $columns
     * @return array{items: list<array<string, mixed>>, next_cursor: ?int}
     */
    public function paginate(
        string $table,
        array $columns,
        ?int $afterId,
        int $limit,
        int $maxLimit = 200
    ): array {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table)) {
            throw new \InvalidArgumentException("Invalid table: {$table}");
        }
        $limit = max(1, min($maxLimit, $limit));
        $afterId = $afterId ?? 0;
        $columnList = implode(', ', $columns);

        $sql = "SELECT {$columnList}
                FROM {$table}
                WHERE id > :after
                ORDER BY id ASC
                LIMIT :limit_plus_one";

        try {
            $stmt = $this->pdo->connection()->prepare($sql);
            $stmt->bindValue(':after', $afterId, PDO::PARAM_INT);
            $stmt->bindValue(':limit_plus_one', $limit + 1, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            $this->logger->error('Cursor pagination failed', [
                'table' => $table,
                'after' => $afterId,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException("Unable to paginate {$table}", 0, $e);
        }

        $nextCursor = null;
        if (count($rows) > $limit) {
            $overshoot = array_pop($rows);
            $nextCursor = (int) ($overshoot['id'] ?? 0);
        }

        return ['items' => $rows, 'next_cursor' => $nextCursor];
    }
}
