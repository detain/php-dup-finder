<?php
declare(strict_types=1);

namespace App\Inventory;

final class InventoryItem
{
    public function __construct(
        public readonly string $sku,
        public readonly string $description,
        public readonly int $onHand,
        public readonly int $reserved,
        public readonly int $supplierId,
        public readonly float $unitCost,
        public readonly int $minLevel,
        public readonly int $maxLevel,
        public readonly int $leadTimeDays,
        public readonly array $serials = [],
        public readonly string $binLocation = 'UNASSIGNED',
    ) {
        if ($sku === '') throw new \InvalidArgumentException('SKU required');
        if ($unitCost < 0) throw new \InvalidArgumentException('Negative cost');
        if ($onHand < 0) throw new \InvalidArgumentException('Negative on-hand');
    }

    public function available(): int { return max(0, $this->onHand - $this->reserved); }

    public function isInStock(): bool { return $this->available() > 0; }

    public function needsReorder(): bool { return $this->onHand <= $this->minLevel; }

    public function reorderQty(): int { return max(0, $this->maxLevel - $this->onHand); }

    public function reorderCostEstimate(): float
    {
        return round($this->reorderQty() * $this->unitCost, 2);
    }

    public function publishedQty(): int
    {
        return min(10, $this->available());
    }

    public static function fromRow(array $row): self
    {
        return new self(
            (string)$row['sku'],
            (string)($row['description'] ?? $row['name'] ?? ''),
            (int)($row['on_hand'] ?? $row['qty_on_hand'] ?? 0),
            (int)($row['qty_reserved'] ?? 0),
            (int)$row['supplier_id'],
            (float)$row['unit_cost'],
            (int)($row['min_level'] ?? 0),
            (int)($row['max_level'] ?? 0),
            (int)($row['lead_time'] ?? 7),
            is_array($row['serials'] ?? null) ? $row['serials'] : [],
            (string)($row['bin'] ?? 'UNASSIGNED'),
        );
    }
}
