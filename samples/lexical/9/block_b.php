<?php
declare(strict_types=1);

namespace Acme\Warehouse\Inventory;

use Acme\Warehouse\Domain\StockItem;

final class InventoryRankLister
{
    /**
     * @param array<string, StockItem> $items  sku => StockItem
     * @return array<int, array{0:int,1:string}>
     */
    public function rankByQuantity(array $items): array
    {
        $rows = [];

        // same lexeme stream: foreach k=>v, push pair, usort by 0 desc
        foreach ($items as $sku => $item) {
            $rows[] = [$item->onHand(), $sku . ' — ' . $item->name()];
        }
        usort($rows, static fn (array $a, array $b): int => $b[0] <=> $a[0]);

        return $rows;
    }

    /**
     * @param array<string, StockItem> $items
     * @return array<int, array{0:int,1:string}>
     */
    public function lowStockBottom(array $items, int $limit): array
    {
        $rows = $this->rankByQuantity($items);
        return array_slice(array_reverse($rows), 0, $limit);
    }
}
