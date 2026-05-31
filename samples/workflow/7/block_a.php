<?php
declare(strict_types=1);

namespace App\Document\Generation;

use App\Domain\Entity\Invoice;
use App\Domain\Entity\Document;
use App\Domain\Repository\InvoiceRepositoryInterface;
use App\Domain\Service\TemplateServiceInterface;
use App\Domain\Service\PdfServiceInterface;
use App\Domain\Service\StorageServiceInterface;
use App\Domain\Service\NotificationServiceInterface;
use Psr\Log\LoggerInterface;

final readonly class InvoiceGenerationWorkflow
{
    public function __construct(
        private InvoiceRepositoryInterface $invoiceRepository,
        private TemplateServiceInterface $templateService,
        private PdfServiceInterface $pdfService,
        private StorageServiceInterface $storageService,
        private NotificationServiceInterface $notificationService,
        private LoggerInterface $logger,
    ) {}

    public function generateInvoice(string $invoiceId): void
    {
        $invoice = $this->invoiceRepository->findById($invoiceId);
        if ($invoice === null) {
            throw new \RuntimeException("Invoice not found: {$invoiceId}");
        }

        $this->logger->info('Starting invoice generation workflow', ['invoice_id' => $invoiceId]);

        $this->validateInvoice($invoice);

        $this->loadTemplate($invoice);

        $this->renderHtml($invoice);

        $this->generatePdf($invoice);

        $this->storeDocument($invoice);

        $this->attachToInvoice($invoice);

        $this->sendNotification($invoice);

        $this->updateInvoiceStatus($invoice, 'document_generated');

        $this->recordAuditEvent($invoice, 'invoice_generated');

        $this->logger->info('Invoice generation workflow completed', ['invoice_id' => $invoiceId]);
    }

    private function validateInvoice(Invoice $invoice): void
    {
        if ($invoice->getStatus() !== 'approved') {
            throw new \RuntimeException("Invoice must be approved before generation");
        }

        if (count($invoice->getLineItems()) === 0) {
            throw new \RuntimeException("Invoice must have at least one line item");
        }

        if ($invoice->getCustomer() === null) {
            throw new \RuntimeException("Invoice must have a customer");
        }

        $this->logger->debug('Invoice validation passed', ['invoice_id' => $invoice->getId()->toString()]);
    }

    private function loadTemplate(Invoice $invoice): void
    {
        $templateName = $invoice->getTemplate() ?? 'default_invoice';

        $template = $this->templateService->load($templateName);
        if ($template === null) {
            throw new \RuntimeException("Template not found: {$templateName}");
        }

        $invoice->setTemplateData(['template_id' => $template->getId()]);

        $this->logger->debug('Template loaded', [
            'invoice_id' => $invoice->getId()->toString(),
            'template' => $templateName,
        ]);
    }

    private function renderHtml(Invoice $invoice): void
    {
        $data = $this->prepareTemplateData($invoice);

        $html = $this->templateService->render($invoice->getTemplateData()['template_id'], $data);

        $invoice->setRenderedHtml($html);

        $this->logger->debug('HTML rendered', ['invoice_id' => $invoice->getId()->toString()]);
    }

    private function prepareTemplateData(Invoice $invoice): array
    {
        return [
            'invoice_number' => $invoice->getInvoiceNumber(),
            'issue_date' => $invoice->getIssueDate()->format('Y-m-d'),
            'due_date' => $invoice->getDueDate()->format('Y-m-d'),
            'customer' => [
                'name' => $invoice->getCustomer()->getName(),
                'address' => $invoice->getCustomer()->getBillingAddress(),
                'email' => $invoice->getCustomer()->getEmail(),
            ],
            'line_items' => array_map(fn($item) => [
                'description' => $item->getDescription(),
                'quantity' => $item->getQuantity(),
                'unit_price' => $item->getUnitPrice(),
                'total' => $item->getTotal(),
            ], $invoice->getLineItems()),
            'subtotal' => $invoice->getSubtotal(),
            'tax' => $invoice->getTaxAmount(),
            'total' => $invoice->getTotalAmount(),
            'currency' => $invoice->getCurrency(),
            'payment_terms' => $invoice->getPaymentTerms(),
            'company' => [
                'name' => 'Acme Corporation',
                'address' => '123 Business St',
                'phone' => '555-0100',
            ],
        ];
    }

    private function generatePdf(Invoice $invoice): void
    {
        $result = $this->pdfService->generateFromHtml($invoice->getRenderedHtml(), [
            'page_size' => 'A4',
            'margin_top' => 20,
            'margin_bottom' => 20,
            'margin_left' => 15,
            'margin_right' => 15,
        ]);

        if (!$result->isSuccessful()) {
            $this->recordAuditEvent($invoice, 'pdf_generation_failed', [
                'error' => $result->getError(),
            ]);
            throw new \RuntimeException("PDF generation failed: {$result->getError()}");
        }

        $invoice->setPdfData($result->getPdfData());
        $invoice->setPageCount($result->getPageCount());

        $this->logger->debug('PDF generated', [
            'invoice_id' => $invoice->getId()->toString(),
            'page_count' => $result->getPageCount(),
        ]);
    }

    private function storeDocument(Invoice $invoice): void
    {
        $path = sprintf(
            'invoices/%s/%s.pdf',
            $invoice->getCustomer()->getId()->toString(),
            $invoice->getInvoiceNumber()
        );

        $url = $this->storageService->store($path, $invoice->getPdfData(), [
            'content_type' => 'application/pdf',
            'metadata' => [
                'invoice_id' => $invoice->getId()->toString(),
                'customer_id' => $invoice->getCustomer()->getId()->toString(),
            ],
        ]);

        $invoice->setDocumentUrl($url);

        $this->logger->debug('Document stored', [
            'invoice_id' => $invoice->getId()->toString(),
            'path' => $path,
        ]);
    }

    private function attachToInvoice(Invoice $invoice): void
    {
        $document = new Document();
        $document->setType('invoice_pdf');
        $document->setUrl($invoice->getDocumentUrl());
        $document->setFileName($invoice->getInvoiceNumber() . '.pdf');
        $document->setMimeType('application/pdf');
        $document->setSize(strlen($invoice->getPdfData()));

        $invoice->addDocument($document);

        $this->logger->debug('Document attached to invoice', ['invoice_id' => $invoice->getId()->toString()]);
    }

    private function sendNotification(Invoice $invoice): void
    {
        $this->notificationService->send(
            $invoice->getCustomer()->getId(),
            'invoice_ready',
            [
                'invoice_id' => $invoice->getId()->toString(),
                'invoice_number' => $invoice->getInvoiceNumber(),
                'amount' => $invoice->getTotalAmount(),
                'download_url' => $invoice->getDocumentUrl(),
            ]
        );

        $this->logger->debug('Notification sent', ['invoice_id' => $invoice->getId()->toString()]);
    }

    private function updateInvoiceStatus(Invoice $invoice, string $status): void
    {
        $invoice->setStatus($status);
        $invoice->setUpdatedAt(new \DateTimeImmutable());
        $this->invoiceRepository->save($invoice);
    }

    private function recordAuditEvent(Invoice $invoice, string $event, array $data = []): void
    {
        $this->logger->info('Audit event', array_merge([
            'invoice_id' => $invoice->getId()->toString(),
            'event' => $event,
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ], $data));
    }
}
