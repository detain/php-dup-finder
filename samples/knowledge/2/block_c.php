<?php

declare(strict_types=1);

namespace App\Checkout;

use App\Cart\Cart;
use App\Cart\CartTotals;

final class CheckoutPolicy
{
    public function evaluate(Cart $cart): CheckoutDecision
    {
        $totals = CartTotals::fromCart($cart);
        $subtotal = $totals->subtotalCents();

        $decision = new CheckoutDecision();
        $decision->subtotalCents = $subtotal;
        $decision->itemCount = $cart->itemCount();

        if ($cart->isEmpty()) {
            $decision->canCheckout = false;
            $decision->buttonLabel = 'Cart is empty';
            $decision->buttonDisabled = true;
            $decision->bannerMessage = 'Add at least one product to your cart.';
            return $decision;
        }

        if ($subtotal < 1000) {
            $missing = 1000 - $subtotal;
            $decision->canCheckout = false;
            $decision->buttonLabel = 'Proceed to payment';
            $decision->buttonDisabled = true;
            $decision->bannerMessage = sprintf(
                'Add %s more to reach the $10.00 minimum order amount.',
                $this->fmt($missing)
            );
            return $decision;
        }

        $decision->canCheckout = true;
        $decision->buttonLabel = sprintf('Pay %s', $this->fmt($subtotal));
        $decision->buttonDisabled = false;
        $decision->bannerMessage = null;
        return $decision;
    }

    private function fmt(int $cents): string
    {
        return '$' . number_format($cents / 100, 2);
    }
}
