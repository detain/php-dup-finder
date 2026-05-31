<?php

declare(strict_types=1);

namespace App\Inventory\Sku;

final class SkuFormatter
{
    private const BRAND_LEN = 3;
    private const ID_LEN = 4;
    private const VARIANT_LEN = 2;

    public function format(string $brand, int|string $productId, string $variant): string
    {
        return sprintf(
            '%s-%s-%s',
            $this->formatBrand($brand),
            $this->formatProductId($productId),
            $this->formatVariant($variant),
        );
    }

    private function formatBrand(string $brand): string
    {
        $clean = preg_replace('/[^A-Z]/', '', strtoupper($brand)) ?? '';
        $padded = str_pad($clean, self::BRAND_LEN, 'X');

        return substr($padded, 0, self::BRAND_LEN);
    }

    private function formatProductId(int|string $productId): string
    {
        $digits = preg_replace('/\D+/', '', (string) $productId) ?? '';
        $value = ($digits === '' ? 0 : (int) $digits) % (10 ** self::ID_LEN);

        return str_pad((string) max(0, $value), self::ID_LEN, '0', STR_PAD_LEFT);
    }

    private function formatVariant(string $variant): string
    {
        $clean = preg_replace('/[^A-Z0-9]/', '', strtoupper($variant)) ?? '';
        $padded = str_pad($clean, self::VARIANT_LEN, '0');

        return substr($padded, 0, self::VARIANT_LEN);
    }
}
