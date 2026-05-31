<?php

declare(strict_types=1);

namespace App\Transform;

use App\Entity\Invoice;
use App\Entity\InvoiceLine;

final class InvoiceMapper
{
    public function toArray(Invoice $invoice): array
    {
        return [
            'id' => $invoice->getId(),
            'number' => $invoice->getNumber(),
            'status' => $invoice->getStatus(),
            'client' => [
                'id' => $invoice->getClient()->getId(),
                'name' => $invoice->getClient()->getName(),
                'email' => $invoice->getClient()->getEmail(),
            ],
            'lines' => array_map(
                fn(InvoiceLine $line) => [
                    'description' => $line->getDescription(),
                    'quantity' => $line->getQuantity(),
                    'unit_price' => $line->getUnitPrice(),
                    'total' => $line->getTotal(),
                ],
                $invoice->getLines()
            ),
            'subtotal' => $invoice->getSubtotal(),
            'tax_rate' => $invoice->getTaxRate(),
            'tax_amount' => $invoice->getTaxAmount(),
            'total' => $invoice->getTotal(),
            'due_date' => $invoice->getDueDate()->format('c'),
            'created_at' => $invoice->getCreatedAt()->format('c'),
        ];
    }

    public function toSummaryArray(Invoice $invoice): array
    {
        return [
            'id' => $invoice->getId(),
            'number' => $invoice->getNumber(),
            'status' => $invoice->getStatus(),
            'client_name' => $invoice->getClient()->getName(),
            'line_count' => count($invoice->getLines()),
            'total' => $invoice->getTotal(),
            'due_date' => $invoice->getDueDate()->format('Y-m-d'),
        ];
    }

    public function toCsvRow(Invoice $invoice): array
    {
        return [
            $invoice->getNumber(),
            $invoice->getClient()->getName(),
            $invoice->getClient()->getEmail(),
            count($invoice->getLines()),
            number_format($invoice->getTotal(), 2),
            $invoice->getStatus(),
            $invoice->getDueDate()->format('Y-m-d'),
        ];
    }

    public function toFlatArray(Invoice $invoice): array
    {
        $flat = [
            'invoice_id' => $invoice->getId(),
            'invoice_number' => $invoice->getNumber(),
            'invoice_status' => $invoice->getStatus(),
            'invoice_subtotal' => $invoice->getSubtotal(),
            'invoice_tax_rate' => $invoice->getTaxRate(),
            'invoice_tax_amount' => $invoice->getTaxAmount(),
            'invoice_total' => $invoice->getTotal(),
            'invoice_due_date' => $invoice->getDueDate()->format('Y-m-d'),
            'client_id' => $invoice->getClient()->getId(),
            'client_name' => $invoice->getClient()->getName(),
            'client_email' => $invoice->getClient()->getEmail(),
        ];

        foreach ($invoice->getLines() as $index => $line) {
            $flat["line_{$index}_description"] = $line->getDescription();
            $flat["line_{$index}_quantity"] = $line->getQuantity();
            $flat["line_{$index}_unit_price"] = $line->getUnitPrice();
            $flat["line_{$index}_total"] = $line->getTotal();
        }

        return $flat;
    }
}
