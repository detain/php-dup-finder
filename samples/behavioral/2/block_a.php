<?php

declare(strict_types=1);

namespace Shop\Checkout\Totals;

use Shop\Catalog\LineItem;

final class TaxAggregator
{
    /**
     * @param iterable<LineItem> $items
     */
    public function totalTaxInCents(iterable $items, float $taxRate): int
    {
        $items = is_array($items) ? $items : iterator_to_array($items);

        return array_reduce(
            $items,
            static function (int $carry, LineItem $item) use ($taxRate): int {
                if (!$item->isTaxable()) {
                    return $carry;
                }

                $gross = $item->unitPriceCents() * $item->quantity();
                $discount = $item->discountCents();
                $taxable = max(0, $gross - $discount);
                $tax = (int) round($taxable * $taxRate);

                return $carry + $tax;
            },
            0,
        );
    }
}
