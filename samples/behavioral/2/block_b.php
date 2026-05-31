<?php

declare(strict_types=1);

namespace Billing\Cart\Tax;

use Shop\Catalog\LineItem;

final class CartTaxCalculator
{
    /**
     * @param list<LineItem> $lineItems
     */
    public function compute(array $lineItems, float $rate): int
    {
        $total = 0;

        foreach ($lineItems as $line) {
            if ($line->isTaxable() === false) {
                continue;
            }

            $subtotal = $line->unitPriceCents() * $line->quantity();
            $afterDiscount = $subtotal - $line->discountCents();

            if ($afterDiscount < 0) {
                $afterDiscount = 0;
            }

            $lineTax = (int) round($afterDiscount * $rate);
            $total += $lineTax;
        }

        return $total;
    }
}
