<?php
declare(strict_types=1);

namespace App\Reads\Inventory;

final class InventorySnapshotQuery
{
    public function __construct(public int $warehouseId, public \DateTimeImmutable $at) {}
}

final class InventorySnapshotProjection
{
    public function __construct(public int $skus, public int $totalUnits, public int $belowReorder) {}
}

final class InventorySnapshotReader
{
    public function __construct(private \PDO $pdo) {}

    public function read(InventorySnapshotQuery $q): InventorySnapshotProjection
    {
        $sql = 'SELECT COUNT(*) AS s, COALESCE(SUM(units),0) AS t,
                       COALESCE(SUM(CASE WHEN units < reorder_at THEN 1 ELSE 0 END),0) AS b
                FROM stock_snapshots WHERE warehouse_id = ? AND captured_at <= ?';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$q->warehouseId, $q->at->format('Y-m-d H:i:s')]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: ['s' => 0, 't' => 0, 'b' => 0];
        return new InventorySnapshotProjection((int) $row['s'], (int) $row['t'], (int) $row['b']);
    }
}

final class InventoryQueryHandler
{
    private array $cache = [];

    public function __construct(private InventorySnapshotReader $reader) {}

    public function handle(InventorySnapshotQuery $q): InventorySnapshotProjection
    {
        $key = $q->warehouseId . '|' . $q->at->format('U');
        return $this->cache[$key] ??= $this->reader->read($q);
    }
}
