<?php
declare(strict_types=1);

namespace App\Order\Read;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class OrderSummaryReader
{
    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findSummariesForCustomer(int $customerId): array
    {
        if ($customerId <= 0) {
            throw new \InvalidArgumentException('customerId must be positive');
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
            WHERE o.customer_id = :customer_id
              AND o.deleted_at IS NULL
            ORDER BY o.placed_at DESC, oi.id ASC
        SQL;

        try {
            $rows = $this->connection->fetchAllAssociative($sql, [
                'customer_id' => $customerId,
            ]);
        } catch (DbalException $e) {
            $this->logger->error('Order summary query failed', [
                'customer_id' => $customerId,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException('Unable to load order summary', 0, $e);
        }

        return $rows;
    }
}
