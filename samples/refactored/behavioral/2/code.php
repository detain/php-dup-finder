<?php

declare(strict_types=1);

namespace Shop\Tax;

use Shop\Catalog\LineItem;

final class LineItemTaxCalculator
{
    /**
     * @param iterable<LineItem> $items
     */
    public function totalTaxInCents(iterable $items, float $rate): int
    {
        $this->assertValidRate($rate);

        $total = 0;
        foreach ($items as $item) {
            $total += $this->taxForLine($item, $rate);
        }

        return $total;
    }

    public function taxForLine(LineItem $item, float $rate): int
    {
        if (!$item->isTaxable()) {
            return 0;
        }

        $gross = $item->unitPriceCents() * $item->quantity();
        $taxable = max(0, $gross - $item->discountCents());

        return (int) round($taxable * $rate);
    }

    private function assertValidRate(float $rate): void
    {
        if ($rate < 0.0 || $rate > 1.0) {
            throw new \InvalidArgumentException(
                sprintf('Tax rate must be in [0,1]; got %f', $rate),
            );
        }
    }
}
