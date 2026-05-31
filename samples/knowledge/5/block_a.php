<?php

declare(strict_types=1);

namespace App\Cart;

use App\Domain\CartItem;
use App\Domain\Money;

final class CartCalculator
{
    public function summary(array $items, string $countryCode): array
    {
        $subtotalCents = 0;
        foreach ($items as $item) {
            assert($item instanceof CartItem);
            $subtotalCents += $item->unitPriceCents * $item->quantity;
        }

        $taxCents = (int) round($subtotalCents * $this->taxRate($countryCode));

        $shippingCents = $this->standardShippingFor($countryCode);
        $freeShippingApplied = false;
        if ($subtotalCents >= 7500) {
            $shippingCents = 0;
            $freeShippingApplied = true;
        }

        $totalCents = $subtotalCents + $taxCents + $shippingCents;

        return [
            'subtotal_cents' => $subtotalCents,
            'tax_cents' => $taxCents,
            'shipping_cents' => $shippingCents,
            'shipping_was_waived' => $freeShippingApplied,
            'shipping_threshold_cents' => 7500,
            'total_cents' => $totalCents,
            'currency' => 'USD',
        ];
    }

    private function taxRate(string $country): float
    {
        return match ($country) {
            'US' => 0.0725,
            'CA' => 0.13,
            'GB' => 0.20,
            default => 0.0,
        };
    }

    private function standardShippingFor(string $country): int
    {
        return match ($country) {
            'US' => 599,
            'CA' => 999,
            default => 1499,
        };
    }
}
