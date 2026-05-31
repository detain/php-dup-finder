<?php
declare(strict_types=1);

namespace App\Billing\Handlers;

use App\Entity\Receipt;
use App\Entity\AuditLog;
use App\Entity\AnalyticsEvent;
use App\Service\QueueService;
use App\Service\PdfService;
use App\Service\StorageService;
use App\Service\EmailService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final class ReceiptIssuedEventHandler
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

    public function handle(Receipt $receipt): void
    {
        $this->logger->info('Processing receipt issued event', [
            'receipt_id' => $receipt->getId(),
            'payment_id' => $receipt->getPaymentId(),
            'amount' => $receipt->getAmount()->getAmount(),
        ]);

        $this->entityManager->beginTransaction();
        try {
            $this->generateReceiptPdf($receipt);
            $this->storeReceiptDocument($receipt);
            $this->recordInTransactionLog($receipt);
            $this->updatePaymentStatus($receipt);
            $this->sendReceiptNotification($receipt);
            $this->recordReceiptAnalytics($receipt);
            $this->createAuditEntry($receipt);
            $this->triggerThankYouSequence($receipt);

            $this->entityManager->commit();

            $this->logger->info('Receipt issued event processed', [
                'receipt_id' => $receipt->getId(),
            ]);
        } catch (\Throwable $e) {
            $this->entityManager->rollback();
            $this->logger->error('Failed to process receipt issued event', [
                'receipt_id' => $receipt->getId(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function generateReceiptPdf(Receipt $receipt): void
    {
        $customer = $this->entityManager
            ->getRepository(\App\Entity\Customer::class)
            ->find($receipt->getCustomerId());

        $payment = $this->entityManager
            ->getRepository(\App\Entity\Payment::class)
            ->find($receipt->getPaymentId());

        $invoice = $payment?->getInvoice();

        $pdfData = [
            'receipt_number' => $receipt->getReceiptNumber(),
            'receipt_date' => $receipt->getCreatedAt()->format('Y-m-d'),
            'customer' => [
                'name' => $customer?->getName(),
                'address' => $customer?->getBillingAddress(),
                'email' => $customer?->getEmail(),
            ],
            'payment' => [
                'amount' => $receipt->getAmount()->getAmount() / 100,
                'currency' => $receipt->getAmount()->getCurrency(),
                'method' => $receipt->getPaymentMethod(),
                'reference' => $receipt->getPaymentReference(),
            ],
            'invoice_reference' => $invoice?->getInvoiceNumber(),
            'notes' => $receipt->getNotes(),
        ];

        $pdfContent = $this->pdfService->generate('receipt', $pdfData);

        $receipt->setPdfPath($this->storageService->store(
            'receipts/' . $receipt->getReceiptNumber() . '.pdf',
            $pdfContent,
            'application/pdf'
        ));
        $receipt->setGeneratedAt(new \DateTimeImmutable());

        $this->entityManager->persist($receipt);

        $this->logger->debug('Generated receipt PDF', [
            'receipt_id' => $receipt->getId(),
            'pdf_path' => $receipt->getPdfPath(),
        ]);
    }

    private function storeReceiptDocument(Receipt $receipt): void
    {
        $document = new \App\Entity\Document();
        $document->setType('receipt');
        $document->setReferenceType('receipt');
        $document->setReferenceId($receipt->getId());
        $document->setFileName($receipt->getReceiptNumber() . '.pdf');
        $document->setFilePath($receipt->getPdfPath());
        $document->setMimeType('application/pdf');
        $document->setFileSize(filesize($this->storageService->getAbsolutePath($receipt->getPdfPath())));
        $document->setCustomerId($receipt->getCustomerId());
        $document->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($document);

        $this->logger->debug('Stored receipt document', [
            'receipt_id' => $receipt->getId(),
            'document_id' => $document->getId(),
        ]);
    }

    private function recordInTransactionLog(Receipt $receipt): void
    {
        $transactionLog = new \App\Entity\TransactionLog();
        $transactionLog->setType('receipt');
        $transactionLog->setReferenceType('receipt');
        $transactionLog->setReferenceId($receipt->getId());
        $transactionLog->setAmount($receipt->getAmount()->getAmount());
        $transactionLog->setCurrency($receipt->getAmount()->getCurrency());
        $transactionLog->setPaymentMethod($receipt->getPaymentMethod());
        $transactionLog->setReferenceNumber($receipt->getPaymentReference());
        $transactionLog->setDescription('Receipt ' . $receipt->getReceiptNumber());
        $transactionLog->setTransactionDate($receipt->getCreatedAt());
        $transactionLog->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($transactionLog);

        $this->logger->debug('Recorded transaction log', [
            'receipt_id' => $receipt->getId(),
            'transaction_log_id' => $transactionLog->getId(),
        ]);
    }

    private function updatePaymentStatus(Receipt $receipt): void
    {
        $payment = $this->entityManager
            ->getRepository(\App\Entity\Payment::class)
            ->find($receipt->getPaymentId());

        if ($payment === null) {
            return;
        }

        $payment->setStatus('completed');
        $payment->setReceiptId($receipt->getId());
        $payment->setPaidAt(new \DateTimeImmutable());

        $this->entityManager->persist($payment);

        $this->logger->debug('Updated payment status', [
            'payment_id' => $payment->getId(),
            'status' => 'completed',
        ]);
    }

    private function sendReceiptNotification(Receipt $receipt): void
    {
        $customer = $this->entityManager
            ->getRepository(\App\Entity\Customer::class)
            ->find($receipt->getCustomerId());

        if ($customer === null || $customer->getEmail() === null) {
            return;
        }

        $template = $this->entityManager
            ->getRepository(\App\Entity\EmailTemplate::class)
            ->findOneBy(['code' => 'receipt_issued']);

        if ($template !== null) {
            $this->queueService->publish('email.outbound', [
                'template_id' => $template->getId(),
                'recipient' => $customer->getEmail(),
                'variables' => [
                    'customer_name' => $customer->getName(),
                    'receipt_number' => $receipt->getReceiptNumber(),
                    'amount' => number_format($receipt->getAmount()->getAmount() / 100, 2),
                    'currency' => $receipt->getAmount()->getCurrency(),
                    'payment_method' => $receipt->getPaymentMethod(),
                    'receipt_pdf_url' => $this->storageService->getSignedUrl(
                        $receipt->getPdfPath(),
                        '+30 days'
                    ),
                ],
                'attachments' => [
                    [
                        'name' => 'receipt-' . $receipt->getReceiptNumber() . '.pdf',
                        'path' => $receipt->getPdfPath(),
                    ],
                ],
                'priority' => 'normal',
            ]);
        }

        $this->logger->debug('Sent receipt notification', [
            'receipt_id' => $receipt->getId(),
            'customer_email' => $customer->getEmail(),
        ]);
    }

    private function recordReceiptAnalytics(Receipt $receipt): void
    {
        $analyticsEvent = new AnalyticsEvent();
        $analyticsEvent->setEventName('receipt_issued');
        $analyticsEvent->setCustomerId($receipt->getCustomerId());
        $analyticsEvent->setPayload([
            'receipt_id' => $receipt->getId(),
            'receipt_number' => $receipt->getReceiptNumber(),
            'amount' => $receipt->getAmount()->getAmount(),
            'currency' => $receipt->getAmount()->getCurrency(),
            'payment_method' => $receipt->getPaymentMethod(),
        ]);
        $analyticsEvent->setOccurredAt(new \DateTimeImmutable());

        $this->entityManager->persist($analyticsEvent);

        $this->logger->debug('Recorded receipt analytics', [
            'receipt_id' => $receipt->getId(),
        ]);
    }

    private function createAuditEntry(Receipt $receipt): void
    {
        $auditEntry = new AuditLog();
        $auditEntry->setAction('RECEIPT_ISSUED');
        $auditEntry->setEntityType('receipt');
        $auditEntry->setEntityId($receipt->getId());
        $auditEntry->setUserId($receipt->getCustomerId());
        $auditEntry->setMetadata([
            'receipt_number' => $receipt->getReceiptNumber(),
            'payment_id' => $receipt->getPaymentId(),
            'amount' => $receipt->getAmount()->getAmount(),
            'currency' => $receipt->getAmount()->getCurrency(),
        ]);
        $auditEntry->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($auditEntry);

        $this->logger->debug('Created audit log entry', [
            'receipt_id' => $receipt->getId(),
        ]);
    }

    private function triggerThankYouSequence(Receipt $receipt): void
    {
        $customer = $this->entityManager
            ->getRepository(\App\Entity\Customer::class)
            ->find($receipt->getCustomerId());

        if ($customer === null || !$customer->isEmailMarketingEnabled()) {
            return;
        }

        $sequence = $this->entityManager
            ->getRepository(\App\Entity\EmailSequence::class)
            ->findOneBy(['trigger' => 'payment_complete', 'status' => 'active']);

        if ($sequence === null) {
            return;
        }

        $enrollment = new \App\Entity\SequenceEnrollment();
        $enrollment->setCustomer($customer);
        $enrollment->setSequence($sequence);
        $enrollment->setStatus('active');
        $enrollment->setEnrolledAt(new \DateTimeImmutable());
        $enrollment->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($enrollment);

        $this->queueService->publish('email_sequence.enroll', [
            'enrollment_id' => $enrollment->getId(),
            'customer_id' => $customer->getId(),
            'sequence_id' => $sequence->getId(),
        ]);

        $this->logger->debug('Triggered thank you sequence', [
            'receipt_id' => $receipt->getId(),
            'sequence_id' => $sequence->getId(),
        ]);
    }
}
