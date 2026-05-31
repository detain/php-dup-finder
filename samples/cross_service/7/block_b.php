<?php
declare(strict_types=1);

namespace Acme\CheckoutService\Tax;

use Acme\CheckoutService\Provider\TaxRateProvider;

final class CheckoutTaxCharger
{
    private const EXEMPT_CATEGORIES = ['groceries', 'prescription', 'baby-formula'];

    public function __construct(private readonly TaxRateProvider $provider)
    {
    }

    public function chargeAmount(array $order, float $shippingCost): float
    {
        $taxableSubtotal = 0.0;
        foreach ($order['lines'] as $li) {
            if (in_array($li['cat'], self::EXEMPT_CATEGORIES, true)) {
                continue;
            }
            $taxableSubtotal += $li['quantity'] * $li['unit_price'];
        }

        $state = $order['state'];
        $county = $order['county'];
        $city = $order['city'];

        $combinedRate = $this->provider->forState($state)
            + $this->provider->forCounty($state, $county)
            + $this->provider->forCity($state, $county, $city);

        $taxBase = $taxableSubtotal;
        if ($this->provider->shippingIsTaxable($state)) {
            $taxBase += $shippingCost;
        }

        return round($taxBase * $combinedRate, 2);
    }
}
