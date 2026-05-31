<?php
declare(strict_types=1);

namespace Warehouse;

final class WarehouseInventoryRecord
{
    public string $sku;
    public string $description;
    public int $qty_on_hand;
    public int $qty_reserved;
    public string $bin_location;
    public array $serial_numbers;
    public int $supplier_id;
    public float $unit_cost;
    public \DateTimeImmutable $last_counted_at;

    public function __construct(array $row)
    {
        if (empty($row['sku'])) {
            throw new \InvalidArgumentException('SKU required');
        }
        if ((float)($row['unit_cost'] ?? 0) < 0) {
            throw new \InvalidArgumentException('Negative cost');
        }
        if ((int)($row['qty_on_hand'] ?? 0) < 0) {
            throw new \InvalidArgumentException('Negative on-hand');
        }
        $this->sku = (string)$row['sku'];
        $this->description = (string)$row['description'];
        $this->qty_on_hand = (int)$row['qty_on_hand'];
        $this->qty_reserved = (int)($row['qty_reserved'] ?? 0);
        $this->bin_location = (string)($row['bin'] ?? 'UNASSIGNED');
        $this->serial_numbers = is_array($row['serials'] ?? null) ? $row['serials'] : [];
        $this->supplier_id = (int)$row['supplier_id'];
        $this->unit_cost = (float)$row['unit_cost'];
        $this->last_counted_at = new \DateTimeImmutable((string)($row['last_counted'] ?? 'now'));
    }

    public function available(): int
    {
        return max(0, $this->qty_on_hand - $this->qty_reserved);
    }
}

final class WarehouseRepo
{
    public function __construct(private \PDO $pdo) {}

    public function find(string $sku): ?WarehouseInventoryRecord
    {
        $stmt = $this->pdo->prepare('SELECT * FROM warehouse_inventory WHERE sku = ?');
        $stmt->execute([$sku]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? new WarehouseInventoryRecord($row) : null;
    }
}
