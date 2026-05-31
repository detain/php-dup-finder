<?php
declare(strict_types=1);

namespace Acme\Shop\Pricing;

final class MoneyTotalFormatter
{
    public function format(float $subtotal, float $tax, float $discount, float $shipping): string
    {
        $finalTotal = $subtotal + $tax - $discount + $shipping;
        if ($finalTotal < 0) {
            $finalTotal = 0.0;
        }
        $rounded = round($finalTotal, 2);
        $formatted = number_format($rounded, 2, '.', ',');
        $currency = '$' . $formatted;
        error_log(sprintf(
            '[total] subtotal=%.2f tax=%.2f discount=%.2f shipping=%.2f total=%s',
            $subtotal,
            $tax,
            $discount,
            $shipping,
            $currency,
        ));
        return $currency;
    }
}

final class OrderTotalCalculator
{
    public function __construct(
        private readonly CartRepository $cartRepository,
        private readonly MoneyTotalFormatter $formatter,
    ) {
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
        $discount = array_sum(array_map(fn ($c) => $c->amountFor($subtotal), $cart->coupons()));
        $shipping = $cart->shippingMethod()->cost();

        return $this->formatter->format($subtotal, $tax, $discount, $shipping);
    }
}
