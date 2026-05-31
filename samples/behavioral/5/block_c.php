<?php

declare(strict_types=1);

namespace Erp\Sku\Render;

final class SkuRenderer
{
    public function render(string $brand, int|string $productId, string $variant): string
    {
        [$brandPart, $idPart, $variantPart] = $this->extractParts($brand, $productId, $variant);

        $assembled = [$brandPart, $idPart, $variantPart];

        return implode('-', $assembled);
    }

    /** @return array{string,string,string} */
    private function extractParts(string $brand, int|string $productId, string $variant): array
    {
        $brandLetters = $this->onlyLetters(strtoupper($brand));
        $brandLetters = $this->padRight($brandLetters, 3, 'X');

        $idDigits = $this->onlyDigits((string) $productId);
        $id = $idDigits === '' ? 0 : ((int) $idDigits) % 10000;

        $variantAlnum = $this->onlyAlnum(strtoupper($variant));
        $variantAlnum = $this->padRight($variantAlnum, 2, '0');

        return [
            substr($brandLetters, 0, 3),
            str_pad((string) $id, 4, '0', STR_PAD_LEFT),
            substr($variantAlnum, 0, 2),
        ];
    }

    private function onlyLetters(string $s): string  { return preg_replace('/[^A-Z]/', '', $s) ?? ''; }
    private function onlyDigits(string $s): string   { return preg_replace('/[^0-9]/', '', $s) ?? ''; }
    private function onlyAlnum(string $s): string    { return preg_replace('/[^A-Z0-9]/', '', $s) ?? ''; }
    private function padRight(string $s, int $n, string $c): string
    {
        return strlen($s) >= $n ? $s : $s . str_repeat($c, $n - strlen($s));
    }
}
