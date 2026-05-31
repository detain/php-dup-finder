<?php

declare(strict_types=1);

namespace App\Reporting\Receipt;

use App\Entity\Transaction;
use App\Repository\TransactionRepository;
use App\Service\PdfGenerator;
use App\Service\TaxCalculator;
use Psr\Log\LoggerInterface;
use Twig\Environment;

final class ReceiptGenerator
{
    public function __construct(
        private readonly TransactionRepository $transactions,
        private readonly TaxCalculator $taxCalculator,
        private readonly PdfGenerator $pdfGenerator,
        private readonly Environment $twig,
        private readonly LoggerInterface $logger,
    ) {}

    public function generateForTransaction(int $transactionId): string
    {
        $transaction = $this->transactions->findById($transactionId);

        if ($transaction === null) {
            $this->logger->error('Transaction not found for receipt', [
                'transaction_id' => $transactionId,
            ]);
            throw new \RuntimeException("Transaction {$transactionId} not found");
        }

        $lineItems = $this->buildLineItems($transaction);
        $subtotal = $this->calculateSubtotal($lineItems);
        $taxAmount = $this->taxCalculator->calculateTax($transaction);
        $total = $subtotal + $taxAmount;

        $receiptData = [
            'transaction' => $transaction,
            'line_items' => $lineItems,
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'total' => $total,
            'payment_method' => $transaction->getPaymentMethod(),
            'merchant_name' => $transaction->getMerchantName(),
            'transaction_date' => $transaction->getCreatedAt()->format('Y-m-d H:i:s'),
            'receipt_number' => $this->generateReceiptNumber($transaction),
        ];

        $html = $this->twig->render('receipt/standard.html.twig', $receiptData);
        $pdfPath = $this->pdfGenerator->generateFromHtml($html, [
            'filename' => "receipt_{$transaction->getReceiptNumber()}.pdf",
            'directory' => '/var/storage/receipts',
        ]);

        $this->logger->info('Receipt generated successfully', [
            'transaction_id' => $transactionId,
            'pdf_path' => $pdfPath,
        ]);

        return $pdfPath;
    }

    private function buildLineItems(Transaction $transaction): array
    {
        $items = [];
        foreach ($transaction->getItems() as $item) {
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

    private function generateReceiptNumber(Transaction $transaction): string
    {
        return sprintf(
            'RCP-%s-%04d',
            $transaction->getCreatedAt()->format('Ymd'),
            $transaction->getId()
        );
    }
}
