<?php

declare(strict_types=1);

namespace App\Cart;

/**
 * @phpstan-type CartItem array{sku:string,qty:int,priceCents:int}
 * @phpstan-type Cart     array{items:list<CartItem>,coupons:list<string>}
 */
final class CartMerger
{
    /**
     * @param Cart $stored
     * @param Cart $incoming
     * @return Cart
     */
    public function merge(array $stored, array $incoming): array
    {
        return [
            'items'   => $this->mergeItems($stored['items'], $incoming['items']),
            'coupons' => $this->mergeCoupons($stored['coupons'], $incoming['coupons']),
        ];
    }

    /**
     * @param list<CartItem> $a
     * @param list<CartItem> $b
     * @return list<CartItem>
     */
    private function mergeItems(array $a, array $b): array
    {
        $bySku = [];
        foreach ([...$a, ...$b] as $line) {
            $sku = $line['sku'];
            if (isset($bySku[$sku])) {
                $bySku[$sku]['qty'] += $line['qty'];
            } else {
                $bySku[$sku] = $line;
            }
        }

        return array_values(array_filter(
            $bySku,
            static fn(array $line): bool => $line['qty'] > 0,
        ));
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     * @return list<string>
     */
    private function mergeCoupons(array $a, array $b): array
    {
        return array_values(array_unique([...$a, ...$b]));
    }
}
