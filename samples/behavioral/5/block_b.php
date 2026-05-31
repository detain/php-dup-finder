<?php

declare(strict_types=1);

namespace Catalog\Sku;

final class ProductCodeBuilder
{
    public function build(string $brand, int|string $productId, string $variant): string
    {
        $parts = [];

        $brandClean = strtoupper((string) preg_replace('/[^a-zA-Z]/', '', $brand));
        while (strlen($brandClean) < 3) {
            $brandClean .= 'X';
        }
        $parts[] = substr($brandClean, 0, 3);

        $pid = (int) preg_replace('/\D+/', '', (string) $productId);
        $pid = max(0, $pid) % 10000;
        $pidString = (string) $pid;
        while (strlen($pidString) < 4) {
            $pidString = '0' . $pidString;
        }
        $parts[] = $pidString;

        $variantClean = strtoupper((string) preg_replace('/[^a-zA-Z0-9]/', '', $variant));
        while (strlen($variantClean) < 2) {
            $variantClean .= '0';
        }
        $parts[] = substr($variantClean, 0, 2);

        return implode('-', $parts);
    }
}
