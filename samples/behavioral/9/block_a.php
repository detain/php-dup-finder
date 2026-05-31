<?php

declare(strict_types=1);

namespace Shop\Cart\Merge;

/**
 * @phpstan-type CartItem array{sku:string,qty:int,priceCents:int}
 * @phpstan-type Cart     array{items:list<CartItem>,coupons:list<string>}
 */
final class ImperativeCartMerger
{
    /**
     * @param Cart $stored
     * @param Cart $incoming
     * @return Cart
     */
    public function merge(array $stored, array $incoming): array
    {
        $bySku = [];

        foreach ($stored['items'] as $line) {
            $bySku[$line['sku']] = $line;
        }

        foreach ($incoming['items'] as $line) {
            if (isset($bySku[$line['sku']])) {
                $bySku[$line['sku']]['qty'] += $line['qty'];
            } else {
                $bySku[$line['sku']] = $line;
            }
        }

        $items = [];
        foreach ($bySku as $line) {
            if ($line['qty'] > 0) {
                $items[] = $line;
            }
        }

        $coupons = $stored['coupons'];
        foreach ($incoming['coupons'] as $coupon) {
            if (!in_array($coupon, $coupons, true)) {
                $coupons[] = $coupon;
            }
        }

        return ['items' => $items, 'coupons' => $coupons];
    }
}
