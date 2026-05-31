<?php

declare(strict_types=1);

namespace App\Reporting;

use App\Entity\Document;
use App\Repository\DocumentRepository;
use App\Service\PdfGenerator;
use App\Service\FeeCalculatorInterface;
use Psr\Log\LoggerInterface;
use Twig\Environment;

final class DocumentGenerator
{
    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly FeeCalculatorInterface $feeCalculator,
        private readonly PdfGenerator $pdfGenerator,
        private readonly Environment $twig,
        private readonly LoggerInterface $logger,
    ) {}

    public function generateForDocument(int $documentId, string $type): string
    {
        $document = $this->documents->findById($documentId);

        if ($document === null) {
            $this->logger->error("{$type} not found for generation", [
                'document_id' => $documentId,
                'type' => $type,
            ]);
            throw new \RuntimeException("{$type} {$documentId} not found");
        }

        $lineItems = $this->buildLineItems($document);
        $subtotal = $this->calculateSubtotal($lineItems);
        $feeAmount = $this->feeCalculator->calculateFee($document);
        $total = $subtotal + $feeAmount;

        $templateMap = [
            'receipt' => 'receipt/standard.html.twig',
            'invoice' => 'invoice/standard.html.twig',
            'statement' => 'statement/standard.html.twig',
        ];

        $documentData = [
            'document' => $document,
            'line_items' => $lineItems,
            'subtotal' => $subtotal,
            'fee_amount' => $feeAmount,
            'total' => $total,
            'document_date' => $document->getCreatedAt()->format('Y-m-d H:i:s'),
            'document_number' => $this->generateDocumentNumber($document, $type),
        ];

        $html = $this->twig->render($templateMap[$type], $documentData);
        $pdfPath = $this->pdfGenerator->generateFromHtml($html, [
            'filename' => "{$type}_{$documentData['document_number']}.pdf",
            'directory' => "/var/storage/{$type}s",
        ]);

        $this->logger->info("{$type} generated successfully", [
            'document_id' => $documentId,
            'pdf_path' => $pdfPath,
        ]);

        return $pdfPath;
    }

    private function buildLineItems(Document $document): array
    {
        $items = [];
        foreach ($document->getItems() as $item) {
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

    private function generateDocumentNumber(Document $document, string $type): string
    {
        $prefix = strtoupper(substr($type, 0, 4));
        return sprintf(
            '%s-%s-%04d',
            $prefix,
            $document->getCreatedAt()->format('Ymd'),
            $document->getId()
        );
    }
}
