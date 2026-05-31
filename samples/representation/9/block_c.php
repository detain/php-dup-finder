<?php
declare(strict_types=1);

namespace Reports\Reorder;

final class ReorderReportRow
{
    public string $itemSku;
    public string $itemDescription;
    public int $currentStock;
    public int $reorderPoint;
    public int $reorderQty;
    public int $supplierRef;
    public float $unitCost;
    public int $leadTimeDays;
    public float $reorderCostEstimate;

    public function buildFrom(array $data): void
    {
        if (empty($data['sku'])) {
            throw new \InvalidArgumentException('SKU required');
        }
        if ((float)($data['unit_cost'] ?? 0) < 0) {
            throw new \InvalidArgumentException('Negative cost');
        }
        if ((int)($data['on_hand'] ?? 0) < 0) {
            throw new \InvalidArgumentException('Negative on-hand');
        }
        $this->itemSku = (string)$data['sku'];
        $this->itemDescription = (string)($data['description'] ?? $data['name'] ?? '');
        $this->currentStock = (int)$data['on_hand'];
        $this->reorderPoint = (int)($data['min_level'] ?? 0);
        $this->reorderQty = max(0, (int)($data['max_level'] ?? 0) - $this->currentStock);
        $this->supplierRef = (int)$data['supplier_id'];
        $this->unitCost = (float)$data['unit_cost'];
        $this->leadTimeDays = (int)($data['lead_time'] ?? 7);
        $this->reorderCostEstimate = round($this->reorderQty * $this->unitCost, 2);
    }

    public function needsReorder(): bool
    {
        return $this->currentStock <= $this->reorderPoint;
    }
}

final class ReorderReportBuilder
{
    /** @return ReorderReportRow[] */
    public function build(array $items): array
    {
        $rows = [];
        foreach ($items as $i) {
            $r = new ReorderReportRow();
            $r->buildFrom($i);
            if ($r->needsReorder()) {
                $rows[] = $r;
            }
        }
        return $rows;
    }
}
