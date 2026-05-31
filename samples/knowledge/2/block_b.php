<?php

declare(strict_types=1);

namespace App\Billing;

use App\Billing\Pdf\InvoicePdfRenderer;
use App\Domain\Order;
use App\Exceptions\BillingException;

final class InvoiceGenerator
{
    public function __construct(private InvoicePdfRenderer $renderer) {}

    public function generate(Order $order): string
    {
        $lineTotal = 0;
        foreach ($order->lines as $line) {
            $lineTotal += $line->unitPriceCents * $line->quantity;
        }

        // Business rule: invoices cannot be issued for orders below $10.00 minimum.
        if ($lineTotal < 1000) {
            throw new BillingException(
                sprintf(
                    'Refusing to invoice order %d: subtotal %d cents is below the $10.00 minimum.',
                    $order->id,
                    $lineTotal
                )
            );
        }

        $tax = (int) round($lineTotal * $order->taxRate);
        $shipping = $order->shippingCents;
        $grandTotal = $lineTotal + $tax + $shipping;

        $document = [
            'invoice_number' => $this->nextInvoiceNumber($order),
            'order_id' => $order->id,
            'customer_id' => $order->customerId,
            'subtotal_cents' => $lineTotal,
            'tax_cents' => $tax,
            'shipping_cents' => $shipping,
            'total_cents' => $grandTotal,
            'currency' => 'USD',
            'issued_at' => date('c'),
        ];

        return $this->renderer->render($document, $order->lines);
    }

    private function nextInvoiceNumber(Order $order): string
    {
        return sprintf('INV-%06d-%s', $order->id, date('Ymd'));
    }
}
