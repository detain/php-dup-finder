<?php
declare(strict_types=1);

namespace App\Billing\Handlers;

use App\Entity\Refund;
use App\Entity\AuditLog;
use App\Entity\AnalyticsEvent;
use App\Service\QueueService;
use App\Service\PdfService;
use App\Service\StorageService;
use App\Service\EmailService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final class RefundProcessedEventHandler
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

    public function handle(Refund $refund): void
    {
        $this->logger->info('Processing refund processed event', [
            'refund_id' => $refund->getId(),
            'original_payment_id' => $refund->getOriginalPaymentId(),
            'amount' => $refund->getAmount()->getAmount(),
        ]);

        $this->entityManager->beginTransaction();
        try {
            $this->generateRefundMemo($refund);
            $this->storeRefundDocument($refund);
            $this->recordInLedger($refund);
            $this->releaseFundsHold($refund);
            $this->sendRefundNotification($refund);
            $this->recordRefundAnalytics($refund);
            $this->createAuditEntry($refund);
            $this->processCompensationIfNeeded($refund);

            $this->entityManager->commit();

            $this->logger->info('Refund processed event processed', [
                'refund_id' => $refund->getId(),
            ]);
        } catch (\Throwable $e) {
            $this->entityManager->rollback();
            $this->logger->error('Failed to process refund processed event', [
                'refund_id' => $refund->getId(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function generateRefundMemo(Refund $refund): void
    {
        $customer = $this->entityManager
            ->getRepository(\App\Entity\Customer::class)
            ->find($refund->getCustomerId());

        $originalPayment = $this->entityManager
            ->getRepository(\App\Entity\Payment::class)
            ->find($refund->getOriginalPaymentId());

        $pdfData = [
            'memo_number' => $refund->getMemoNumber(),
            'memo_date' => $refund->getCreatedAt()->format('Y-m-d'),
            'customer' => [
                'name' => $customer?->getName(),
                'address' => $customer?->getBillingAddress(),
                'email' => $customer?->getEmail(),
            ],
            'refund' => [
                'amount' => $refund->getAmount()->getAmount() / 100,
                'currency' => $refund->getAmount()->getCurrency(),
                'method' => $refund->getRefundMethod(),
                'reason' => $refund->getReason(),
            ],
            'original_payment' => $originalPayment ? [
                'payment_id' => $originalPayment->getId(),
                'amount' => $originalPayment->getAmount()->getAmount() / 100,
                'date' => $originalPayment->getCreatedAt()->format('Y-m-d'),
            ] : null,
            'notes' => $refund->getNotes(),
        ];

        $pdfContent = $this->pdfService->generate('refund_memo', $pdfData);

        $refund->setPdfPath($this->storageService->store(
            'refunds/' . $refund->getMemoNumber() . '.pdf',
            $pdfContent,
            'application/pdf'
        ));
        $refund->setGeneratedAt(new \DateTimeImmutable());

        $this->entityManager->persist($refund);

        $this->logger->debug('Generated refund memo PDF', [
            'refund_id' => $refund->getId(),
            'pdf_path' => $refund->getPdfPath(),
        ]);
    }

    private function storeRefundDocument(Refund $refund): void
    {
        $document = new \App\Entity\Document();
        $document->setType('refund_memo');
        $document->setReferenceType('refund');
        $document->setReferenceId($refund->getId());
        $document->setFileName($refund->getMemoNumber() . '.pdf');
        $document->setFilePath($refund->getPdfPath());
        $document->setMimeType('application/pdf');
        $document->setFileSize(filesize($this->storageService->getAbsolutePath($refund->getPdfPath())));
        $document->setCustomerId($refund->getCustomerId());
        $document->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($document);

        $this->logger->debug('Stored refund document', [
            'refund_id' => $refund->getId(),
            'document_id' => $document->getId(),
        ]);
    }

    private function recordInLedger(Refund $refund): void
    {
        $ledgerEntry = new \App\Entity\LedgerEntry();
        $ledgerEntry->setType('refund');
        $ledgerEntry->setReferenceType('refund');
        $ledgerEntry->setReferenceId($refund->getId());
        $ledgerEntry->setAmount(-$refund->getAmount()->getAmount());
        $ledgerEntry->setCurrency($refund->getAmount()->getCurrency());
        $ledgerEntry->setAccount($this->getCashAccount());
        $ledgerEntry->setDescription('Refund ' . $refund->getMemoNumber());
        $ledgerEntry->setTransactionDate($refund->getCreatedAt());
        $ledgerEntry->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($ledgerEntry);

        $this->logger->debug('Recorded refund in ledger', [
            'refund_id' => $refund->getId(),
            'ledger_entry_id' => $ledgerEntry->getId(),
        ]);
    }

    private function releaseFundsHold(Refund $refund): void
    {
        $hold = $this->entityManager
            ->getRepository(\App\Entity\FundHold::class)
            ->findActiveHoldForPayment($refund->getOriginalPaymentId());

        if ($hold !== null) {
            $hold->setStatus('released');
            $hold->setReleasedAt(new \DateTimeImmutable());
            $hold->setReleaseReason('refund_processed');

            $this->entityManager->persist($hold);

            $this->logger->debug('Released funds hold', [
                'refund_id' => $refund->getId(),
                'hold_id' => $hold->getId(),
            ]);
        }
    }

    private function sendRefundNotification(Refund $refund): void
    {
        $customer = $this->entityManager
            ->getRepository(\App\Entity\Customer::class)
            ->find($refund->getCustomerId());

        if ($customer === null || $customer->getEmail() === null) {
            return;
        }

        $template = $this->entityManager
            ->getRepository(\App\Entity\EmailTemplate::class)
            ->findOneBy(['code' => 'refund_processed']);

        if ($template !== null) {
            $this->queueService->publish('email.outbound', [
                'template_id' => $template->getId(),
                'recipient' => $customer->getEmail(),
                'variables' => [
                    'customer_name' => $customer->getName(),
                    'memo_number' => $refund->getMemoNumber(),
                    'amount' => number_format($refund->getAmount()->getAmount() / 100, 2),
                    'currency' => $refund->getAmount()->getCurrency(),
                    'refund_method' => $refund->getRefundMethod(),
                    'reason' => $refund->getReason(),
                    'processing_time' => $refund->getCreatedAt()->diff(
                        $refund->getOriginalPayment()?->getCreatedAt()
                    )->days,
                    'refund_pdf_url' => $this->storageService->getSignedUrl(
                        $refund->getPdfPath(),
                        '+30 days'
                    ),
                ],
                'attachments' => [
                    [
                        'name' => 'refund-' . $refund->getMemoNumber() . '.pdf',
                        'path' => $refund->getPdfPath(),
                    ],
                ],
                'priority' => 'high',
            ]);
        }

        $this->logger->debug('Sent refund notification', [
            'refund_id' => $refund->getId(),
            'customer_email' => $customer->getEmail(),
        ]);
    }

    private function recordRefundAnalytics(Refund $refund): void
    {
        $analyticsEvent = new AnalyticsEvent();
        $analyticsEvent->setEventName('refund_processed');
        $analyticsEvent->setCustomerId($refund->getCustomerId());
        $analyticsEvent->setPayload([
            'refund_id' => $refund->getId(),
            'memo_number' => $refund->getMemoNumber(),
            'amount' => $refund->getAmount()->getAmount(),
            'currency' => $refund->getAmount()->getCurrency(),
            'reason' => $refund->getReason(),
            'refund_method' => $refund->getRefundMethod(),
        ]);
        $analyticsEvent->setOccurredAt(new \DateTimeImmutable());

        $this->entityManager->persist($analyticsEvent);

        $this->logger->debug('Recorded refund analytics', [
            'refund_id' => $refund->getId(),
        ]);
    }

    private function createAuditEntry(Refund $refund): void
    {
        $auditEntry = new AuditLog();
        $auditEntry->setAction('REFUND_PROCESSED');
        $auditEntry->setEntityType('refund');
        $auditEntry->setEntityId($refund->getId());
        $auditEntry->setUserId($refund->getCustomerId());
        $auditEntry->setMetadata([
            'memo_number' => $refund->getMemoNumber(),
            'original_payment_id' => $refund->getOriginalPaymentId(),
            'amount' => $refund->getAmount()->getAmount(),
            'currency' => $refund->getAmount()->getCurrency(),
            'reason' => $refund->getReason(),
        ]);
        $auditEntry->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($auditEntry);

        $this->logger->debug('Created audit log entry', [
            'refund_id' => $refund->getId(),
        ]);
    }

    private function processCompensationIfNeeded(Refund $refund): void
    {
        if ($refund->getReason() !== 'service_dissatisfaction') {
            return;
        }

        $customer = $this->entityManager
            ->getRepository(\App\Entity\Customer::class)
            ->find($refund->getCustomerId());

        if ($customer === null) {
            return;
        }

        $coupon = new \App\Entity\Coupon();
        $coupon->setCode(bin2hex(random_bytes(8)));
        $coupon->setDiscountType('percentage');
        $coupon->setDiscountValue(15);
        $coupon->setMinimumOrderAmount($refund->getAmount()->getAmount() / 100);
        $coupon->setValidFrom(new \DateTimeImmutable());
        $coupon->setValidUntil((new \DateTimeImmutable())->modify('+30 days'));
        $coupon->setMaxUses(1);
        $coupon->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($coupon);

        $this->queueService->publish('email.outbound', [
            'template' => 'compensation_coupon',
            'recipient' => $customer->getEmail(),
            'variables' => [
                'coupon_code' => $coupon->getCode(),
                'discount' => '15%',
                'valid_until' => $coupon->getValidUntil()->format('Y-m-d'),
            ],
        ]);

        $this->logger->debug('Processed compensation coupon', [
            'refund_id' => $refund->getId(),
            'coupon_id' => $coupon->getId(),
        ]);
    }

    private function getCashAccount(): string
    {
        return $this->entityManager
            ->getRepository(\App\Entity\SystemSetting::class)
            ->findOneBy(['key' => 'cash_account'])
            ?->getValue() ?? '1001';
    }
}
