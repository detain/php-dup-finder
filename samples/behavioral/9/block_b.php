<?php

declare(strict_types=1);

namespace Commerce\Basket;

/**
 * @phpstan-type CartItem array{sku:string,qty:int,priceCents:int}
 * @phpstan-type Cart     array{items:list<CartItem>,coupons:list<string>}
 */
final class FunctionalCartMerger
{
    /**
     * @param Cart $a
     * @param Cart $b
     * @return Cart
     */
    public function combine(array $a, array $b): array
    {
        $allItems = array_merge($a['items'], $b['items']);

        $merged = array_reduce(
            $allItems,
            /**
             * @param array<string,CartItem> $acc
             * @param CartItem $line
             * @return array<string,CartItem>
             */
            static function (array $acc, array $line): array {
                if (isset($acc[$line['sku']])) {
                    $acc[$line['sku']]['qty'] += $line['qty'];
                } else {
                    $acc[$line['sku']] = $line;
                }
                return $acc;
            },
            [],
        );

        $items = array_values(array_filter(
            $merged,
            static fn(array $line): bool => $line['qty'] > 0,
        ));

        $coupons = array_values(array_unique(array_merge($a['coupons'], $b['coupons'])));

        return ['items' => $items, 'coupons' => $coupons];
    }
}
