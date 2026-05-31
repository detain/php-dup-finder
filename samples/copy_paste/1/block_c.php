<?php
declare(strict_types=1);

namespace Acme\Shop\Refund;

final class RefundSummaryBuilder
{
    public function build(RefundRequest $request): string
    {
        $subtotal = 0.0;
        foreach ($request->refundedItems() as $item) {
            $subtotal += $item->quantity * $item->unitPrice;
        }

        $tax = round($subtotal * $request->originalTaxRate(), 2);
        $discount = $request->restockingFee();
        $shipping = -1 * $request->originalShippingCost();

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

        return "Refund #{$request->id()} -> {$currency}";
    }
}
