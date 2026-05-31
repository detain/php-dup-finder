<?php
declare(strict_types=1);

namespace App\Domain\Payment\EventHandler;

use App\Entity\Dispute;
use App\Entity\AuditLog;
use App\Entity\AnalyticsEvent;
use App\Service\QueueService;
use App\Service\FraudDetectionService;
use App\Service\RefundService;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final class DisputeOpenedEventHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly QueueService $queueService,
        private readonly FraudDetectionService $fraudService,
        private readonly RefundService $refundService,
        private readonly NotificationService $notificationService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(Dispute $dispute): void
    {
        $this->logger->info('Processing dispute opened event', [
            'dispute_id' => $dispute->getId(),
            'payment_id' => $dispute->getPaymentId(),
            'reason' => $dispute->getReason(),
        ]);

        $this->entityManager->beginTransaction();
        try {
            $this->assessFraudRisk($dispute);
            $this->freezeDisputedAmount($dispute);
            $this->notifyMerchant($dispute);
            $this->updatePaymentStatus($dispute);
            $this->recordDisputeAnalytics($dispute);
            $this->createAuditEntry($dispute);
            $this->prepareDisputeEvidence($dispute);
            $this->assignDisputeHandler($dispute);

            $this->entityManager->commit();

            $this->logger->info('Dispute opened event processed', [
                'dispute_id' => $dispute->getId(),
            ]);
        } catch (\Throwable $e) {
            $this->entityManager->rollback();
            $this->logger->error('Failed to process dispute opened event', [
                'dispute_id' => $dispute->getId(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function assessFraudRisk(Dispute $dispute): void
    {
        $payment = $this->entityManager
            ->getRepository(\App\Entity\Payment::class)
            ->find($dispute->getPaymentId());

        if ($payment === null) {
            throw new \RuntimeException('Payment not found');
        }

        $fraudAssessment = $this->fraudService->assessDispute([
            'customer_id' => $payment->getCustomerId(),
            'payment_id' => $payment->getId(),
            'amount' => $payment->getAmount()->getAmount(),
            'dispute_reason' => $dispute->getReason(),
            'dispute_amount' => $dispute->getAmount(),
            'customer_history' => $this->getCustomerDisputeHistory($payment->getCustomerId()),
        ]);

        $dispute->setFraudRiskScore($fraudAssessment->getRiskScore());
        $dispute->setFraudIndicators($fraudAssessment->getIndicators());
        $dispute->setAutomatedResolution($fraudAssessment->canAutoResolve());
        $dispute->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($dispute);

        if ($fraudAssessment->isHighRisk()) {
            $this->queueService->publish('fraud.high_risk_alert', [
                'dispute_id' => $dispute->getId(),
                'risk_score' => $fraudAssessment->getRiskScore(),
                'customer_id' => $payment->getCustomerId(),
                'amount' => $dispute->getAmount(),
                'priority' => 'high',
            ]);
        }

        $this->logger->debug('Assessed fraud risk for dispute', [
            'dispute_id' => $dispute->getId(),
            'risk_score' => $fraudAssessment->getRiskScore(),
        ]);
    }

    private function freezeDisputedAmount(Dispute $dispute): void
    {
        $payment = $this->entityManager
            ->getRepository(\App\Entity\Payment::class)
            ->find($dispute->getPaymentId());

        if ($payment === null) {
            throw new \RuntimeException('Payment not found');
        }

        $freeze = new \App\Entity\FundFreeze();
        $freeze->setType('dispute');
        $freeze->setReferenceType('dispute');
        $freeze->setReferenceId($dispute->getId());
        $freeze->setAmount($dispute->getAmount());
        $freeze->setCurrency($payment->getAmount()->getCurrency());
        $freeze->setStatus('active');
        $freeze->setExpiresAt(
            (new \DateTimeImmutable())->modify('+90 days')
        );
        $freeze->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($freeze);

        $this->refundService->freezeFunds(
            $payment->getCustomerId(),
            $dispute->getAmount(),
            'dispute_' . $dispute->getId()
        );

        $this->logger->debug('Frozen disputed amount', [
            'dispute_id' => $dispute->getId(),
            'amount' => $dispute->getAmount(),
            'freeze_id' => $freeze->getId(),
        ]);
    }

    private function notifyMerchant(Dispute $dispute): void
    {
        $merchant = $this->entityManager
            ->getRepository(\App\Entity\Merchant::class)
            ->find($dispute->getMerchantId());

        if ($merchant === null) {
            throw new \RuntimeException('Merchant not found');
        }

        $template = $this->entityManager
            ->getRepository(\App\Entity\EmailTemplate::class)
            ->findOneBy(['code' => 'dispute_opened_merchant']);

        if ($template !== null) {
            $this->queueService->publish('email.outbound', [
                'template_id' => $template->getId(),
                'recipient' => $merchant->getEmail(),
                'variables' => [
                    'merchant_name' => $merchant->getBusinessName(),
                    'dispute_id' => $dispute->getId(),
                    'reason' => $dispute->getReason(),
                    'amount' => number_format($dispute->getAmount() / 100, 2),
                    'deadline' => (new \DateTimeImmutable())->modify('+14 days')->format('Y-m-d'),
                    'evidence_url' => sprintf('/merchant/disputes/%d/evidence', $dispute->getId()),
                ],
                'priority' => 'high',
            ]);
        }

        if ($merchant->getPhone()) {
            $this->queueService->publish('sms.outbound', [
                'recipient' => $merchant->getPhone(),
                'message' => sprintf(
                    'A dispute has been opened for $%.2f. Reason: %s. Deadline to respond: %s',
                    $dispute->getAmount() / 100,
                    $dispute->getReason(),
                    (new \DateTimeImmutable())->modify('+14 days')->format('Y-m-d')
                ),
            ]);
        }

        $notification = new \App\Entity\MerchantNotification();
        $notification->setMerchant($merchant);
        $notification->setType('dispute_opened');
        $notification->setTitle('Dispute Opened - Action Required');
        $notification->setBody(sprintf(
            'A dispute of $%.2f has been opened. Reason: %s. Respond by: %s',
            $dispute->getAmount() / 100,
            $dispute->getReason(),
            (new \DateTimeImmutable())->modify('+14 days')->format('Y-m-d H:i')
        ));
        $notification->setMetadata([
            'dispute_id' => $dispute->getId(),
            'amount' => $dispute->getAmount(),
            'reason' => $dispute->getReason(),
        ]);
        $notification->setStatus('unread');
        $notification->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($notification);

        $this->logger->debug('Notified merchant of dispute', [
            'dispute_id' => $dispute->getId(),
            'merchant_id' => $merchant->getId(),
        ]);
    }

    private function updatePaymentStatus(Dispute $dispute): void
    {
        $payment = $this->entityManager
            ->getRepository(\App\Entity\Payment::class)
            ->find($dispute->getPaymentId());

        if ($payment === null) {
            throw new \RuntimeException('Payment not found');
        }

        $payment->setStatus('disputed');
        $payment->setDisputeId($dispute->getId());
        $payment->setDisputeReason($dispute->getReason());
        $payment->setDisputeOpenedAt(new \DateTimeImmutable());

        $this->entityManager->persist($payment);

        $this->logger->debug('Updated payment status to disputed', [
            'payment_id' => $payment->getId(),
            'dispute_id' => $dispute->getId(),
        ]);
    }

    private function recordDisputeAnalytics(Dispute $dispute): void
    {
        $analyticsEvent = new AnalyticsEvent();
        $analyticsEvent->setEventName('dispute_opened');
        $analyticsEvent->setCustomerId($dispute->getMerchantId());
        $analyticsEvent->setPayload([
            'dispute_id' => $dispute->getId(),
            'payment_id' => $dispute->getPaymentId(),
            'reason' => $dispute->getReason(),
            'amount' => $dispute->getAmount(),
            'fraud_risk_score' => $dispute->getFraudRiskScore(),
        ]);
        $analyticsEvent->setOccurredAt(new \DateTimeImmutable());

        $this->entityManager->persist($analyticsEvent);

        $this->logger->debug('Recorded dispute analytics', [
            'dispute_id' => $dispute->getId(),
            'event' => 'dispute_opened',
        ]);
    }

    private function createAuditEntry(Dispute $dispute): void
    {
        $auditEntry = new AuditLog();
        $auditEntry->setAction('DISPUTE_OPENED');
        $auditEntry->setEntityType('dispute');
        $auditEntry->setEntityId($dispute->getId());
        $auditEntry->setUserId($dispute->getCustomerId());
        $auditEntry->setMetadata([
            'payment_id' => $dispute->getPaymentId(),
            'merchant_id' => $dispute->getMerchantId(),
            'reason' => $dispute->getReason(),
            'amount' => $dispute->getAmount(),
            'fraud_risk_score' => $dispute->getFraudRiskScore(),
        ]);
        $auditEntry->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($auditEntry);

        $this->logger->debug('Created audit log entry', [
            'dispute_id' => $dispute->getId(),
            'action' => 'DISPUTE_OPENED',
        ]);
    }

    private function prepareDisputeEvidence(Dispute $dispute): void
    {
        $payment = $this->entityManager
            ->getRepository(\App\Entity\Payment::class)
            ->find($dispute->getPaymentId());

        $evidence = new \App\Entity\DisputeEvidence();
        $evidence->setDispute($dispute);
        $evidence->setInvoiceAvailable(true);
        $evidence->setShippingProofAvailable(
            in_array($dispute->getReason(), ['not_received', 'defective'])
        );
        $evidence->setCommunicationHistoryAvailable(true);
        $evidence->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($evidence);

        $this->queueService->publish('dispute.evidence_preparation', [
            'dispute_id' => $dispute->getId(),
            'payment_id' => $dispute->getPaymentId(),
            'evidence_id' => $evidence->getId(),
            'priority' => 'normal',
        ]);

        $this->logger->debug('Prepared dispute evidence template', [
            'dispute_id' => $dispute->getId(),
            'evidence_id' => $evidence->getId(),
        ]);
    }

    private function assignDisputeHandler(Dispute $dispute): void
    {
        $availableHandlers = $this->entityManager
            ->getRepository(\App\Entity\DisputeHandler::class)
            ->findAvailableHandlers();

        if (empty($availableHandlers)) {
            $this->logger->warning('No available dispute handlers', [
                'dispute_id' => $dispute->getId(),
            ]);
            return;
        }

        $assignedHandler = $availableHandlers[0];

        $assignment = new \App\Entity\DisputeAssignment();
        $assignment->setDispute($dispute);
        $assignment->setHandler($assignedHandler);
        $assignment->setAssignedAt(new \DateTimeImmutable());
        $assignment->setStatus('active');

        $assignedHandler->setCurrentCaseload($assignedHandler->getCurrentCaseload() + 1);
        $assignedHandler->setLastAssignedAt(new \DateTimeImmutable());

        $this->entityManager->persist($assignment);
        $this->entityManager->persist($assignedHandler);

        $dispute->setStatus('under_review');
        $dispute->setHandlerId($assignedHandler->getId());
        $this->entityManager->persist($dispute);

        $this->queueService->publish('dispute.handler_assigned', [
            'dispute_id' => $dispute->getId(),
            'handler_id' => $assignedHandler->getId(),
            'handler_email' => $assignedHandler->getEmail(),
        ]);

        $this->logger->info('Assigned dispute handler', [
            'dispute_id' => $dispute->getId(),
            'handler_id' => $assignedHandler->getId(),
        ]);
    }

    private function getCustomerDisputeHistory(int $customerId): array
    {
        $disputes = $this->entityManager
            ->getRepository(Dispute::class)
            ->findBy(['customerId' => $customerId]);

        return [
            'total_disputes' => count($disputes),
            'won_disputes' => count(array_filter($disputes, fn($d) => $d->getStatus() === 'won')),
            'lost_disputes' => count(array_filter($disputes, fn($d) => $d->getStatus() === 'lost')),
        ];
    }
}
