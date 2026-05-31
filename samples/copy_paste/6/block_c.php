<?php
declare(strict_types=1);

namespace Acme\Reports\Inventory;

final class InventoryExporter
{
    public function __construct(private readonly StockSnapshot $stock)
    {
    }

    public function exportLowStock(int $threshold): array
    {
        $lines = ["sku,name,quantity,warehouse\n"];

        foreach ($this->stock->findBelow($threshold) as $item) {
            $row = [$item->sku, $item->name, $item->quantity, $item->warehouse];

            // ---- BEGIN copy-pasted CSV row builder ----
            $cleaned = [];
            foreach ($row as $value) {
                if ($value === null) {
                    $cleaned[] = '';
                    continue;
                }
                $str = (string) $value;
                $str = str_replace(["\r\n", "\r"], "\n", $str);
                $needsQuote = preg_match('/[",\n]/', $str) === 1;
                $escaped = str_replace('"', '""', $str);
                $cleaned[] = $needsQuote ? '"' . $escaped . '"' : $escaped;
            }
            $line = implode(',', $cleaned) . "\n";
            // ---- END copy-pasted CSV row builder ----

            $lines[] = $line;
        }
        return $lines;
    }
}
