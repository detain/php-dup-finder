<?php
declare(strict_types=1);

namespace App\Inventory\Reconcile;

function reconcile_warehouse_stock(\PDO $pdo, int $warehouseId): array
{
    $countStmt = $pdo->prepare(
        'SELECT COUNT(*) AS c FROM warehouse_stock WHERE warehouse_id = :w'
    );
    $countStmt->execute([':w' => $warehouseId]);
    $total = (int)$countStmt->fetchColumn();

    if ($total === 0) {
        error_log("No stock entries to reconcile for warehouse {$warehouseId}");
        return ['processed' => 0, 'discrepancies' => 0];
    }

    $processed = 0;
    $discrepancies = 0;
    $offset = 0;

    while ($offset < $total) {
        $stmt = $pdo->prepare(
            'SELECT id, sku, on_hand, last_counted_at
             FROM warehouse_stock
             WHERE warehouse_id = :w
             ORDER BY id
             LIMIT 500 OFFSET :o'
        );
        $stmt->bindValue(':w', $warehouseId, \PDO::PARAM_INT);
        $stmt->bindValue(':o', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        if ($rows === []) {
            break;
        }

        $pdo->beginTransaction();
        try {
            foreach ($rows as $row) {
                $physical = lookup_physical_count($pdo, (string)$row['sku'], $warehouseId);
                if ($physical !== (int)$row['on_hand']) {
                    $discrepancies++;
                    $pdo->prepare(
                        'INSERT INTO stock_discrepancies (sku, warehouse_id, system_count, physical_count, recorded_at)
                         VALUES (?, ?, ?, ?, NOW())'
                    )->execute([$row['sku'], $warehouseId, (int)$row['on_hand'], $physical]);
                }
                $processed++;
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $offset += 500;
    }

    return [
        'processed'      => $processed,
        'discrepancies' => $discrepancies,
    ];
}

function lookup_physical_count(\PDO $pdo, string $sku, int $warehouse): int
{
    $s = $pdo->prepare('SELECT count FROM physical_counts WHERE sku = ? AND warehouse_id = ? ORDER BY counted_at DESC LIMIT 1');
    $s->execute([$sku, $warehouse]);
    return (int)$s->fetchColumn();
}
