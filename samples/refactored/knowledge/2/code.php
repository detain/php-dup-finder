<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

final class OrderAmountPolicy
{
    public const MIN_ORDER_CENTS = 1000;       // $10.00
    public const MAX_ORDER_CENTS = 5_000_000;  // $50,000

    public static function isBelowMinimum(int $subtotalCents): bool
    {
        return $subtotalCents < self::MIN_ORDER_CENTS;
    }

    public static function isAboveMaximum(int $subtotalCents): bool
    {
        return $subtotalCents > self::MAX_ORDER_CENTS;
    }

    public static function shortfallCents(int $subtotalCents): int
    {
        return max(0, self::MIN_ORDER_CENTS - $subtotalCents);
    }

    public static function formatMinimum(): string
    {
        return '$' . number_format(self::MIN_ORDER_CENTS / 100, 2);
    }
}

// Controller:
// if (OrderAmountPolicy::isBelowMinimum($subtotal)) { return JsonResponse::error('Order subtotal must be at least ' . OrderAmountPolicy::formatMinimum(), 422); }

// Invoice generator:
// if (OrderAmountPolicy::isBelowMinimum($lineTotal)) { throw new BillingException('Below ' . OrderAmountPolicy::formatMinimum() . ' minimum.'); }

// Checkout policy:
// if (OrderAmountPolicy::isBelowMinimum($subtotal)) {
//     $decision->bannerMessage = sprintf('Add %s more to reach the %s minimum.',
//         $this->fmt(OrderAmountPolicy::shortfallCents($subtotal)),
//         OrderAmountPolicy::formatMinimum());
// }
