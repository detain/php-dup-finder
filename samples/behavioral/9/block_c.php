<?php

declare(strict_types=1);

namespace Storefront\Bag;

/**
 * @phpstan-type CartItem array{sku:string,qty:int,priceCents:int}
 * @phpstan-type Cart     array{items:list<CartItem>,coupons:list<string>}
 */
final class DiffPatchCartMerger
{
    /**
     * @param Cart $base
     * @param Cart $update
     * @return Cart
     */
    public function apply(array $base, array $update): array
    {
        $baseIndex = [];
        foreach ($base['items'] as $i => $line) {
            $baseIndex[$line['sku']] = $i;
        }

        $patched = $base['items'];

        foreach ($update['items'] as $line) {
            if (isset($baseIndex[$line['sku']])) {
                $patched[$baseIndex[$line['sku']]]['qty'] += $line['qty'];
                continue;
            }

            $baseIndex[$line['sku']] = count($patched);
            $patched[] = $line;
        }

        $finalItems = [];
        foreach ($patched as $line) {
            if ($line['qty'] > 0) {
                $finalItems[] = $line;
            }
        }

        $couponSet = array_flip($base['coupons']);
        foreach ($update['coupons'] as $c) {
            $couponSet[$c] = true;
        }

        return ['items' => $finalItems, 'coupons' => array_keys($couponSet)];
    }
}
