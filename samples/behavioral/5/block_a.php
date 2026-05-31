<?php

declare(strict_types=1);

namespace Inventory\Sku\Format;

final class WarehouseSkuFormatter
{
    public function format(string $brand, int|string $productId, string $variant): string
    {
        $brand = strtoupper(trim($brand));
        $brand = preg_replace('/[^A-Z]/', '', $brand) ?? '';
        $brand = substr(str_pad($brand, 3, 'X'), 0, 3);

        $product = (int) preg_replace('/[^0-9]/', '', (string) $productId);
        if ($product < 0) {
            $product = 0;
        }
        if ($product > 9999) {
            $product = $product % 10000;
        }

        $variant = strtoupper(trim($variant));
        $variant = preg_replace('/[^A-Z0-9]/', '', $variant) ?? '';
        $variant = substr(str_pad($variant, 2, '0'), 0, 2);

        return sprintf('%s-%04d-%s', $brand, $product, $variant);
    }
}
