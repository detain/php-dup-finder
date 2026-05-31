<?php
declare(strict_types=1);

namespace App\Analytics\Payments;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class PaymentDailyTotals
{
    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return array<int, array{day: string, total: float, count: int}>
     */
    public function totalsForRange(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        if ($from > $to) {
            throw new \InvalidArgumentException('from must be <= to');
        }

        $sql = <<<'SQL'
            SELECT DATE(created_at) AS day,
                   COALESCE(SUM(amount_cents), 0) AS total_cents,
                   COUNT(*) AS row_count
            FROM payments
            WHERE created_at BETWEEN :from AND :to
              AND status = 'captured'
            GROUP BY DATE(created_at)
            ORDER BY day ASC
        SQL;

        try {
            $rows = $this->connection->fetchAllAssociative($sql, [
                'from' => $from->format('Y-m-d H:i:s'),
                'to' => $to->format('Y-m-d H:i:s'),
            ]);
        } catch (DbalException $e) {
            $this->logger->error('Payment daily totals query failed', [
                'from' => $from->format(DATE_ATOM),
                'to' => $to->format(DATE_ATOM),
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException('Payment totals failed', 0, $e);
        }

        return array_map(static fn (array $row): array => [
            'day' => (string) $row['day'],
            'total' => ((int) $row['total_cents']) / 100,
            'count' => (int) $row['row_count'],
        ], $rows);
    }
}
