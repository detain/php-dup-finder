<?php

declare(strict_types=1);

namespace Pos\Totals;

use Shop\Catalog\LineItem;

final class PosTaxSummer
{
    /**
     * @param list<LineItem> $items
     */
    public function sumTaxes(array $items, float $rate): int
    {
        $taxableLines = array_filter(
            $items,
            static fn(LineItem $i): bool => $i->isTaxable(),
        );

        $perLineTaxes = array_map(
            function (LineItem $i) use ($rate): int {
                $gross = $i->unitPriceCents() * $i->quantity();
                $net = max(0, $gross - $i->discountCents());

                return $this->roundCents($net * $rate);
            },
            $taxableLines,
        );

        return array_sum($perLineTaxes);
    }

    private function roundCents(float $amount): int
    {
        return (int) round($amount);
    }
}
