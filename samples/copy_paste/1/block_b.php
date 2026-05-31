<?php
declare(strict_types=1);

namespace Acme\Shop\Quote;

final class QuoteEstimator
{
    public function __construct(private readonly QuoteRepository $quotes)
    {
    }

    public function estimate(string $quoteCode): array
    {
        $quote = $this->quotes->findByCode($quoteCode);
        if ($quote === null) {
            throw new \DomainException("Quote {$quoteCode} missing");
        }

        $subtotal = 0.0;
        foreach ($quote->lineItems() as $line) {
            $subtotal += $line->qty * $line->price;
        }

        $tax = round($subtotal * $quote->taxRate(), 2);
        $discount = $quote->promoDiscount();
        $shipping = $quote->shippingEstimate();

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

        return ['code' => $quoteCode, 'total' => $currency];
    }
}
