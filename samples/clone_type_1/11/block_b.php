<?php

declare(strict_types=1);

namespace App\Reporting\Invoice;

use App\Entity\Invoice;
use App\Repository\InvoiceRepository;
use App\Service\PdfGenerator;
use App\Service\TaxCalculator;
use Psr\Log\LoggerInterface;
use Twig\Environment;

final class InvoiceGenerator
{
    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly TaxCalculator $taxCalculator,
        private readonly PdfGenerator $pdfGenerator,
        private readonly Environment $twig,
        private readonly LoggerInterface $logger,
    ) {}

    public function generateForInvoice(int $invoiceId): string
    {
        $invoice = $this->invoices->findById($invoiceId);

        if ($invoice === null) {
            $this->logger->error('Invoice not found for PDF generation', [
                'invoice_id' => $invoiceId,
            ]);
            throw new \RuntimeException("Invoice {$invoiceId} not found");
        }

        $lineItems = $this->buildLineItems($invoice);
        $subtotal = $this->calculateSubtotal($lineItems);
        $taxAmount = $this->taxCalculator->calculateTax($invoice);
        $total = $subtotal + $taxAmount;

        $invoiceData = [
            'invoice' => $invoice,
            'line_items' => $lineItems,
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'total' => $total,
            'payment_method' => $invoice->getPaymentMethod(),
            'merchant_name' => $invoice->getMerchantName(),
            'invoice_date' => $invoice->getCreatedAt()->format('Y-m-d H:i:s'),
            'invoice_number' => $this->generateInvoiceNumber($invoice),
        ];

        $html = $this->twig->render('invoice/standard.html.twig', $invoiceData);
        $pdfPath = $this->pdfGenerator->generateFromHtml($html, [
            'filename' => "invoice_{$invoice->getInvoiceNumber()}.pdf",
            'directory' => '/var/storage/invoices',
        ]);

        $this->logger->info('Invoice PDF generated successfully', [
            'invoice_id' => $invoiceId,
            'pdf_path' => $pdfPath,
        ]);

        return $pdfPath;
    }

    private function buildLineItems(Invoice $invoice): array
    {
        $items = [];
        foreach ($invoice->getItems() as $item) {
            $items[] = [
                'description' => $item->getDescription(),
                'quantity' => $item->getQuantity(),
                'unit_price' => $item->getUnitPrice(),
                'line_total' => $item->getQuantity() * $item->getUnitPrice(),
            ];
        }
        return $items;
    }

    private function calculateSubtotal(array $lineItems): float
    {
        return array_reduce(
            $lineItems,
            fn(float $carry, array $item) => $carry + $item['line_total'],
            0.0
        );
    }

    private function generateInvoiceNumber(Invoice $invoice): string
    {
        return sprintf(
            'INV-%s-%04d',
            $invoice->getCreatedAt()->format('Ymd'),
            $invoice->getId()
        );
    }
}
