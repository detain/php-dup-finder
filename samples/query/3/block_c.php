<?php
declare(strict_types=1);

namespace App\Order\Read;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class WarehousePickListReader
{
    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function pickListForWarehouse(int $warehouseId): array
    {
        if ($warehouseId <= 0) {
            throw new \InvalidArgumentException('warehouseId must be positive');
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
            WHERE o.warehouse_id = :warehouse_id
              AND o.status = 'awaiting_pick'
              AND o.deleted_at IS NULL
            ORDER BY o.placed_at ASC
        SQL;

        try {
            return $this->connection->fetchAllAssociative($sql, [
                'warehouse_id' => $warehouseId,
            ]);
        } catch (DbalException $e) {
            $this->logger->error('Pick list query failed', [
                'warehouse_id' => $warehouseId,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException('Unable to load pick list', 0, $e);
        }
    }
}
