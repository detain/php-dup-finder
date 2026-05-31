<?php
declare(strict_types=1);

namespace App\Analytics;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class DailyTotalsAggregator
{
    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return array<int, array{day: string, total: float, count: int}>
     */
    public function totalsForRange(
        string $table,
        string $sumColumn,
        string $statusValue,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to
    ): array {
        if ($from > $to) {
            throw new \InvalidArgumentException('from must be <= to');
        }
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table)
            || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $sumColumn)) {
            throw new \InvalidArgumentException('Invalid identifier');
        }

        $sql = "SELECT DATE(created_at) AS day,
                       COALESCE(SUM({$sumColumn}), 0) AS total_cents,
                       COUNT(*) AS row_count
                FROM {$table}
                WHERE created_at BETWEEN :from AND :to
                  AND status = :status
                GROUP BY DATE(created_at)
                ORDER BY day ASC";

        try {
            $rows = $this->connection->fetchAllAssociative($sql, [
                'from' => $from->format('Y-m-d H:i:s'),
                'to' => $to->format('Y-m-d H:i:s'),
                'status' => $statusValue,
            ]);
        } catch (DbalException $e) {
            $this->logger->error('Daily totals query failed', [
                'table' => $table,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException("Daily totals failed for {$table}", 0, $e);
        }

        return array_map(static fn (array $row): array => [
            'day' => (string) $row['day'],
            'total' => ((int) $row['total_cents']) / 100,
            'count' => (int) $row['row_count'],
        ], $rows);
    }
}
