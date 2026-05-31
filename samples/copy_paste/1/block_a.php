<?php
declare(strict_types=1);

namespace Acme\Shop\Checkout;

final class OrderTotalCalculator
{
    public function __construct(private readonly CartRepository $cartRepository)
    {
    }

    public function calculate(int $orderId): string
    {
        $cart = $this->cartRepository->findByOrderId($orderId);
        if ($cart === null) {
            throw new \RuntimeException("Cart for order {$orderId} not found");
        }

        $subtotal = 0.0;
        foreach ($cart->items() as $item) {
            $subtotal += $item->quantity() * $item->unitPrice();
        }

        $taxRate = $cart->customer()->isExempt() ? 0.0 : 0.0825;
        $tax = round($subtotal * $taxRate, 2);

        $discount = 0.0;
        foreach ($cart->coupons() as $coupon) {
            $discount += $coupon->amountFor($subtotal);
        }

        $shipping = $cart->shippingMethod()->cost();

        // ---- BEGIN copy-pasted total assembly ----
        $finalTotal = $subtotal + $tax - $discount + $shipping;
        if ($finalTotal < 0) {
            $finalTotal = 0.0;
        }
        $rounded = round($finalTotal, 2);
        $formatted = number_format($rounded, 2, '.', ',');
        $currency = '$' . $formatted;
        $audit = sprintf(
            'subtotal=%.2f tax=%.2f discount=%.2f shipping=%.2f total=%s',
            $subtotal,
            $tax,
            $discount,
            $shipping,
            $currency,
        );
        error_log('[total] ' . $audit);
        // ---- END copy-pasted total assembly ----

        return $currency;
    }
}
