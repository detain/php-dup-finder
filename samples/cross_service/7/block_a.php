<?php
declare(strict_types=1);

namespace Acme\CatalogService\Tax;

use Acme\CatalogService\Rates\RateTable;

final class CatalogTaxPreview
{
    private const EXEMPT = ['groceries', 'prescription', 'baby-formula'];

    public function __construct(private readonly RateTable $rates)
    {
    }

    public function preview(array $cartLines, string $state, string $county, string $city, float $shipping): float
    {
        $taxable = 0.0;
        foreach ($cartLines as $line) {
            if (in_array($line['category'], self::EXEMPT, true)) {
                continue;
            }
            $taxable += $line['qty'] * $line['price'];
        }

        $rate = 0.0;
        $rate += $this->rates->stateRate($state);
        $rate += $this->rates->countyRate($state, $county);
        $rate += $this->rates->cityRate($state, $county, $city);

        $base = $taxable;
        if ($this->rates->shippingTaxable($state)) {
            $base += $shipping;
        }

        return round($base * $rate, 2);
    }
}
