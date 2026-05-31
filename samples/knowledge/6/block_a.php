<?php

declare(strict_types=1);

namespace App\Billing\Tax;

use App\Domain\Invoice;
use App\Domain\InvoiceLine;

final class InvoiceCalculator
{
    public function calculate(Invoice $invoice): Invoice
    {
        $subtotal = 0;
        foreach ($invoice->lines as $line) {
            assert($line instanceof InvoiceLine);
            $subtotal += $line->unitPriceCents * $line->quantity;
        }
        $invoice->subtotalCents = $subtotal;

        $rate = match ($invoice->billingCountry) {
            'US' => 0.0725,
            'CA' => 0.13,
            'GB' => 0.20,
            'DE' => 0.19,
            default => 0.0,
        };

        $invoice->taxRate = $rate;
        $invoice->taxCents = (int) round($subtotal * $rate);
        $invoice->totalCents = $invoice->subtotalCents + $invoice->taxCents;
        $invoice->taxDescription = sprintf(
            '%s tax (%.2f%%)',
            $invoice->billingCountry,
            $rate * 100
        );

        $this->applyExemptions($invoice);
        return $invoice;
    }

    private function applyExemptions(Invoice $invoice): void
    {
        if ($invoice->customer->isTaxExempt) {
            $invoice->taxCents = 0;
            $invoice->totalCents = $invoice->subtotalCents;
            $invoice->taxDescription = 'Tax exempt';
        }
    }
}
