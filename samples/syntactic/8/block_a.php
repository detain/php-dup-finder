<?php
declare(strict_types=1);

namespace Acme\Commerce;

final class CartPriceAggregator
{
    public function __construct(private TaxResolver $tax) {}

    /** @param array<int,CartLine> $lines */
    public function aggregate(array $lines, string $taxRegion): CartTotals
    {
        $reducer = function (array $carry, CartLine $line): array {
            $lineGross = $line->unitPrice * $line->quantity;
            $lineTax   = (int) round($lineGross * $this->tax->rateFor($carry['region']));

            $carry['gross']    += $lineGross;
            $carry['tax']      += $lineTax;
            $carry['skuCount'] += 1;
            return $carry;
        };

        $reducer = \Closure::bind($reducer, $this, self::class);

        $initial = [
            'gross'    => 0,
            'tax'      => 0,
            'skuCount' => 0,
            'region'   => $taxRegion,
        ];

        $result = array_reduce($lines, $reducer, $initial);

        return new CartTotals(
            gross: $result['gross'],
            tax:   $result['tax'],
            net:   $result['gross'] + $result['tax'],
            lines: $result['skuCount'],
        );
    }
}
