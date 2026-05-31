<?php

declare(strict_types=1);

namespace Acme\Cart\Pricing;

use Acme\Cart\Model\Cart;
use Acme\Cart\Model\Customer;
use Acme\Cart\Pricing\PriceQuote;

final class CartShippingCalculator
{
    public function quote(Cart $cart, Customer $customer): PriceQuote
    {
        $totalGrams = 0;
        foreach ($cart->lines() as $line) {
            $totalGrams += $line->weightGrams() * $line->quantity();
        }

        $country = $customer->shippingAddress()->countryCode();
        $isMember = $customer->membershipLevel() >= 3;

        $qualifies = $totalGrams <= 5000
            && $country === 'US'
            && $isMember;

        if ($qualifies) {
            return new PriceQuote(0, 'FREE_SHIPPING');
        }

        $base = 4.99 + ($totalGrams / 1000) * 0.5;
        return new PriceQuote((int) round($base * 100), 'STANDARD');
    }
}
