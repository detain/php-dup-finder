<?php

declare(strict_types=1);

namespace App\Domain\Shipping;

final class FreeShippingPolicy
{
    public const THRESHOLD_CENTS = 7500; // $75.00

    public static function qualifies(int $subtotalCents): bool
    {
        return $subtotalCents >= self::THRESHOLD_CENTS;
    }

    public static function shortfallCents(int $subtotalCents): int
    {
        return max(0, self::THRESHOLD_CENTS - $subtotalCents);
    }

    public static function formatThreshold(): string
    {
        return '$' . number_format(self::THRESHOLD_CENTS / 100, 0);
    }
}

// Cart calculator:
// if (FreeShippingPolicy::qualifies($subtotalCents)) { $shippingCents = 0; }
// return ['shipping_threshold_cents' => FreeShippingPolicy::THRESHOLD_CENTS, ...];

// Product badge:
// if (FreeShippingPolicy::qualifies($product->priceCents)) {
//     $badges[] = '<span class="badge badge--shipping">Free shipping over ' . FreeShippingPolicy::formatThreshold() . '</span>';
// }

// Confirmation email:
// if (FreeShippingPolicy::qualifies($order->subtotalCents)) {
//     $context['saved_shipping_message'] = sprintf('You saved on shipping by ordering over %s!', FreeShippingPolicy::formatThreshold());
// }
