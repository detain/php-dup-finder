<?php
declare(strict_types=1);

namespace App\Billing\Handlers;

use App\Entity\Invoice;
use App\Entity\AuditLog;
use App\Entity\AnalyticsEvent;
use App\Service\QueueService;
use App\Service\PdfService;
use App\Service\StorageService;
use App\Service\EmailService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final class InvoiceGeneratedEventHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly QueueService $queueService,
        private readonly PdfService $pdfService,
        private readonly StorageService $storageService,
        private readonly EmailService $emailService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(Invoice $invoice): void
    {
        $this->logger->info('Processing invoice generated event', [
            'invoice_id' => $invoice->getId(),
            'customer_id' => $invoice->getCustomerId(),
            'total' => $invoice->getTotal()->getAmount(),
        ]);

        $this->entityManager->beginTransaction();
        try {
            $this->generateInvoicePdf($invoice);
            $this->storeInvoiceDocument($invoice);
            $this->recordInLedger($invoice);
            $this->updateCustomerBalance($invoice);
            $this->sendInvoiceNotification($invoice);
            $this->recordBillingAnalytics($invoice);
            $this->createAuditEntry($invoice);
            $this->schedulePaymentReminder($invoice);

            $this->entityManager->commit();

            $this->logger->info('Invoice generated event processed', [
                'invoice_id' => $invoice->getId(),
            ]);
        } catch (\Throwable $e) {
            $this->entityManager->rollback();
            $this->logger->error('Failed to process invoice generated event', [
                'invoice_id' => $invoice->getId(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function generateInvoicePdf(Invoice $invoice): void
    {
        $customer = $this->entityManager
            ->getRepository(\App\Entity\Customer::class)
            ->find($invoice->getCustomerId());

        $lineItems = [];
        foreach ($invoice->getLineItems() as $item) {
            $lineItems[] = [
                'description' => $item->getDescription(),
                'quantity' => $item->getQuantity(),
                'unit_price' => $item->getUnitPrice() / 100,
                'total' => $item->getTotal() / 100,
            ];
        }

        $pdfData = [
            'invoice_number' => $invoice->getInvoiceNumber(),
            'invoice_date' => $invoice->getCreatedAt()->format('Y-m-d'),
            'due_date' => $invoice->getDueAt()->format('Y-m-d'),
            'customer' => [
                'name' => $customer?->getName(),
                'address' => $customer?->getBillingAddress(),
                'email' => $customer?->getEmail(),
            ],
            'line_items' => $lineItems,
            'subtotal' => $invoice->getSubtotal() / 100,
            'tax' => $invoice->getTax() / 100,
            'total' => $invoice->getTotal() / 100,
            'currency' => $invoice->getCurrency(),
            'payment_terms' => $invoice->getPaymentTerms(),
        ];

        $pdfContent = $this->pdfService->generate('invoice', $pdfData);

        $invoice->setPdfPath($this->storageService->store(
            'invoices/' . $invoice->getInvoiceNumber() . '.pdf',
            $pdfContent,
            'application/pdf'
        ));
        $invoice->setGeneratedAt(new \DateTimeImmutable());

        $this->entityManager->persist($invoice);

        $this->logger->debug('Generated invoice PDF', [
            'invoice_id' => $invoice->getId(),
            'pdf_path' => $invoice->getPdfPath(),
        ]);
    }

    private function storeInvoiceDocument(Invoice $invoice): void
    {
        $document = new \App\Entity\Document();
        $document->setType('invoice');
        $document->setReferenceType('invoice');
        $document->setReferenceId($invoice->getId());
        $document->setFileName($invoice->getInvoiceNumber() . '.pdf');
        $document->setFilePath($invoice->getPdfPath());
        $document->setMimeType('application/pdf');
        $document->setFileSize(filesize($this->storageService->getAbsolutePath($invoice->getPdfPath())));
        $document->setCustomerId($invoice->getCustomerId());
        $document->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($document);

        $this->logger->debug('Stored invoice document', [
            'invoice_id' => $invoice->getId(),
            'document_id' => $document->getId(),
        ]);
    }

    private function recordInLedger(Invoice $invoice): void
    {
        $ledgerEntry = new \App\Entity\LedgerEntry();
        $ledgerEntry->setType('invoice');
        $ledgerEntry->setReferenceType('invoice');
        $ledgerEntry->setReferenceId($invoice->getId());
        $ledgerEntry->setAmount($invoice->getTotal());
        $ledgerEntry->setCurrency($invoice->getCurrency());
        $ledgerEntry->setAccount($this->getAccountsReceivableAccount());
        $ledgerEntry->setDescription('Invoice ' . $invoice->getInvoiceNumber());
        $ledgerEntry->setTransactionDate($invoice->getCreatedAt());
        $ledgerEntry->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($ledgerEntry);

        $this->logger->debug('Recorded invoice in ledger', [
            'invoice_id' => $invoice->getId(),
            'ledger_entry_id' => $ledgerEntry->getId(),
        ]);
    }

    private function updateCustomerBalance(Invoice $invoice): void
    {
        $customer = $this->entityManager
            ->getRepository(\App\Entity\Customer::class)
            ->find($invoice->getCustomerId());

        if ($customer === null) {
            return;
        }

        $customer->setOutstandingBalance(
            $customer->getOutstandingBalance() + $invoice->getTotal()
        );
        $customer->setLastInvoiceAt(new \DateTimeImmutable());
        $customer->setUpdatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($customer);

        $this->logger->debug('Updated customer balance', [
            'customer_id' => $customer->getId(),
            'outstanding_balance' => $customer->getOutstandingBalance(),
        ]);
    }

    private function sendInvoiceNotification(Invoice $invoice): void
    {
        $customer = $this->entityManager
            ->getRepository(\App\Entity\Customer::class)
            ->find($invoice->getCustomerId());

        if ($customer === null || $customer->getEmail() === null) {
            return;
        }

        $template = $this->entityManager
            ->getRepository(\App\Entity\EmailTemplate::class)
            ->findOneBy(['code' => 'invoice_generated']);

        if ($template !== null) {
            $this->queueService->publish('email.outbound', [
                'template_id' => $template->getId(),
                'recipient' => $customer->getEmail(),
                'variables' => [
                    'customer_name' => $customer->getName(),
                    'invoice_number' => $invoice->getInvoiceNumber(),
                    'amount' => number_format($invoice->getTotal() / 100, 2),
                    'currency' => $invoice->getCurrency(),
                    'due_date' => $invoice->getDueAt()->format('Y-m-d'),
                    'invoice_pdf_url' => $this->storageService->getSignedUrl(
                        $invoice->getPdfPath(),
                        '+7 days'
                    ),
                ],
                'attachments' => [
                    [
                        'name' => 'invoice-' . $invoice->getInvoiceNumber() . '.pdf',
                        'path' => $invoice->getPdfPath(),
                    ],
                ],
                'priority' => 'high',
            ]);
        }

        $this->logger->debug('Sent invoice notification', [
            'invoice_id' => $invoice->getId(),
            'customer_email' => $customer->getEmail(),
        ]);
    }

    private function recordBillingAnalytics(Invoice $invoice): void
    {
        $analyticsEvent = new AnalyticsEvent();
        $analyticsEvent->setEventName('invoice_generated');
        $analyticsEvent->setCustomerId($invoice->getCustomerId());
        $analyticsEvent->setPayload([
            'invoice_id' => $invoice->getId(),
            'invoice_number' => $invoice->getInvoiceNumber(),
            'amount' => $invoice->getTotal(),
            'currency' => $invoice->getCurrency(),
            'line_item_count' => count($invoice->getLineItems()),
        ]);
        $analyticsEvent->setOccurredAt(new \DateTimeImmutable());

        $this->entityManager->persist($analyticsEvent);

        $this->logger->debug('Recorded billing analytics', [
            'invoice_id' => $invoice->getId(),
        ]);
    }

    private function createAuditEntry(Invoice $invoice): void
    {
        $auditEntry = new AuditLog();
        $auditEntry->setAction('INVOICE_GENERATED');
        $auditEntry->setEntityType('invoice');
        $auditEntry->setEntityId($invoice->getId());
        $auditEntry->setUserId($invoice->getCustomerId());
        $auditEntry->setMetadata([
            'invoice_number' => $invoice->getInvoiceNumber(),
            'amount' => $invoice->getTotal(),
            'currency' => $invoice->getCurrency(),
            'due_date' => $invoice->getDueAt()->format(\DATE_ATOM),
        ]);
        $auditEntry->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($auditEntry);

        $this->logger->debug('Created audit log entry', [
            'invoice_id' => $invoice->getId(),
        ]);
    }

    private function schedulePaymentReminder(Invoice $invoice): void
    {
        $reminderDays = [7, 3, 1];
        foreach ($reminderDays as $daysBeforeDue) {
            $reminderDate = $invoice->getDueAt()
                ->modify("-{$daysBeforeDue} days")
                ->setTime(9, 0, 0);

            if ($reminderDate <= new \DateTimeImmutable()) {
                continue;
            }

            $reminder = new \App\Entity\ScheduledReminder();
            $reminder->setType('payment_reminder');
            $reminder->setReferenceType('invoice');
            $reminder->setReferenceId($invoice->getId());
            $reminder->setScheduledFor($reminderDate);
            $reminder->setTemplate('payment_reminder_' . $daysBeforeDue . '_days');
            $reminder->setStatus('pending');
            $reminder->setCreatedAt(new \DateTimeImmutable());

            $this->entityManager->persist($reminder);

            $this->queueService->publish('reminders.schedule', [
                'reminder_id' => $reminder->getId(),
                'invoice_id' => $invoice->getId(),
                'customer_id' => $invoice->getCustomerId(),
                'scheduled_for' => $reminderDate->format(\DATE_ATOM),
            ]);
        }

        $this->logger->debug('Scheduled payment reminders', [
            'invoice_id' => $invoice->getId(),
        ]);
    }

    private function getAccountsReceivableAccount(): string
    {
        return $this->entityManager
            ->getRepository(\App\Entity\SystemSetting::class)
            ->findOneBy(['key' => 'accounts_receivable_account'])
            ?->getValue() ?? '1200';
    }
}
