<?php

declare(strict_types=1);

namespace App\Storefront\Product;

use App\Catalog\Product;

final class ProductBadgeRenderer
{
    public function render(Product $product): string
    {
        $badges = [];

        if ($product->isNew()) {
            $badges[] = '<span class="badge badge--new">New</span>';
        }

        if ($product->isOnSale()) {
            $pct = (int) round((1 - ($product->salePriceCents / $product->priceCents)) * 100);
            $badges[] = sprintf('<span class="badge badge--sale">-%d%%</span>', $pct);
        }

        if ($product->priceCents >= 7500) {
            $badges[] = '<span class="badge badge--shipping">Free shipping</span>';
        } else {
            $needCents = 7500 - $product->priceCents;
            $badges[] = sprintf(
                '<span class="badge badge--shipping-eligible">Add $%.2f for free shipping over $75</span>',
                $needCents / 100
            );
        }

        if ($product->stock < 5 && $product->stock > 0) {
            $badges[] = sprintf('<span class="badge badge--low-stock">Only %d left</span>', $product->stock);
        }

        if ($product->stock === 0) {
            $badges[] = '<span class="badge badge--oos">Sold out</span>';
        }

        return '<div class="product-badges">' . implode('', $badges) . '</div>';
    }
}
