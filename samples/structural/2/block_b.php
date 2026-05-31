<?php

declare(strict_types=1);

namespace Acme\Warehouse\Service;

use Doctrine\DBAL\Connection;
use Acme\Warehouse\Entity\StockAdjustment;
use Psr\Log\LoggerInterface;

final class StockAdjustmentService
{
    public function __construct(
        private readonly Connection $db,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function adjust(string $sku, int $deltaUnits, string $reason, int $actorId): StockAdjustment
    {
        $this->db->beginTransaction();
        try {
            $this->db->executeStatement(
                'UPDATE stock_level SET on_hand = on_hand + ? WHERE sku = ?',
                [$deltaUnits, $sku],
            );

            $this->db->executeStatement(
                'INSERT INTO stock_adjustment (sku, delta, reason, actor_id, created_at) VALUES (?, ?, ?, ?, NOW())',
                [$sku, $deltaUnits, $reason, $actorId],
            );

            $id = (int) $this->db->lastInsertId();

            $this->db->executeStatement(
                'INSERT INTO stock_adjustment_audit (adjustment_id, action, actor_id) VALUES (?, ?, ?)',
                [$id, 'apply', $actorId],
            );

            $this->db->commit();
            $this->logger->info('stock adjusted', ['sku' => $sku, 'delta' => $deltaUnits]);

            return new StockAdjustment($id, $sku, $deltaUnits, $reason);
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->logger->error('stock adjustment failed', ['error' => $e->getMessage(), 'sku' => $sku]);
            throw $e;
        }
    }
}
