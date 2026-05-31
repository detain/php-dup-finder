<?php
declare(strict_types=1);

namespace App\Order\Read;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class OrderExportReader
{
    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return iterable<array<string, mixed>>
     */
    public function streamForExport(\DateTimeImmutable $from, \DateTimeImmutable $to): iterable
    {
        if ($from > $to) {
            throw new \InvalidArgumentException('from must be <= to');
        }

        $sql = <<<'SQL'
            SELECT o.id AS order_id,
                   o.placed_at,
                   o.total_cents,
                   oi.id AS item_id,
                   oi.quantity,
                   p.id AS product_id,
                   p.sku,
                   p.name AS product_name
            FROM orders o
            INNER JOIN order_items oi ON oi.order_id = o.id
            INNER JOIN products p ON p.id = oi.product_id
            WHERE o.placed_at BETWEEN :from AND :to
              AND o.deleted_at IS NULL
            ORDER BY o.placed_at, oi.id
        SQL;

        try {
            $stmt = $this->connection->prepare($sql);
            $result = $stmt->executeQuery([
                'from' => $from->format('Y-m-d H:i:s'),
                'to' => $to->format('Y-m-d H:i:s'),
            ]);

            while (($row = $result->fetchAssociative()) !== false) {
                yield $row;
            }
        } catch (DbalException $e) {
            $this->logger->error('Order export query failed', [
                'from' => $from->format(DATE_ATOM),
                'to' => $to->format(DATE_ATOM),
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException('Unable to stream order export', 0, $e);
        }
    }
}
