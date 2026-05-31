<?php
declare(strict_types=1);

namespace App\Fraud\Chargeback;

use App\Domain\Entity\Chargeback;
use App\Domain\Repository\ChargebackRepositoryInterface;
use App\Domain\Service\ChargebackServiceInterface;
use App\Domain\Service\OrderServiceInterface;
use App\Domain\Service\NotificationServiceInterface;
use Psr\Log\LoggerInterface;

final readonly class ChargebackWorkflow
{
    public function __construct(
        private ChargebackRepositoryInterface $chargebackRepository,
        private ChargebackServiceInterface $chargebackService,
        private OrderServiceInterface $orderService,
        private NotificationServiceInterface $notificationService,
        private LoggerInterface $logger,
    ) {}

    public function processChargeback(string $chargebackId): void
    {
        $chargeback = $this->chargebackRepository->findById($chargebackId);
        if ($chargeback === null) {
            throw new \RuntimeException("Chargeback not found: {$chargebackId}");
        }

        $this->logger->info('Starting chargeback workflow', ['chargeback_id' => $chargebackId]);

        $this->validateChargeback($chargeback);

        $this->analyzeChargeback($chargeback);

        $this->assessFraudIndicator($chargeback);

        $this->determineResponseStrategy($chargeback);

        $this->prepareEvidence($chargeback);

        $this->submitResponse($chargeback);

        $this->updateOrderStatus($chargeback);

        $this->notifyParties($chargeback);

        $this->logger->info('Chargeback workflow completed', ['chargeback_id' => $chargebackId]);
    }

    private function validateChargeback(Chargeback $chargeback): void
    {
        if ($chargeback->getStatus() !== 'pending') {
            throw new \RuntimeException("Chargeback is not in pending status");
        }

        if ($chargeback->getAmount() <= 0) {
            throw new \RuntimeException("Chargeback amount must be positive");
        }

        $this->logger->debug('Chargeback validation passed', ['chargeback_id' => $chargeback->getId()->toString()]);
    }

    private function analyzeChargeback(Chargeback $chargeback): void
    {
        $analysis = $this->chargebackService->analyzeChargeback($chargeback);

        $chargeback->setAnalysis([
            'reason_code' => $analysis->getReasonCode(),
            'reason_description' => $analysis->getReasonDescription(),
            'is_prevention_possible' => $analysis->isPreventionPossible(),
            'evidence_required' => $analysis->getEvidenceRequired(),
        ]);

        $this->chargebackRepository->save($chargeback);

        $this->logger->debug('Chargeback analyzed', [
            'chargeback_id' => $chargeback->getId()->toString(),
            'reason_code' => $analysis->getReasonCode(),
        ]);
    }

    private function assessFraudIndicator(Chargeback $chargeback): void
    {
        $fraudCheck = $this->chargebackService->getFraudCheckForChargeback($chargeback);
        $isFraudulent = false;

        if ($fraudCheck !== null) {
            $isFraudulent = $fraudCheck->getRiskLevel() === 'high' ||
                           $fraudCheck->getManualReviewDecision() === 'reject';
        }

        $details = $chargeback->getAnalysis();
        $details['is_fraud_indicator'] = $isFraudulent;
        $details['related_fraud_check_id'] = $fraudCheck?->getId()->toString();
        $chargeback->setAnalysis($details);

        $chargeback->setFraudIndicator($isFraudulent);
        $this->chargebackRepository->save($chargeback);

        $this->logger->debug('Fraud indicator assessed', [
            'chargeback_id' => $chargeback->getId()->toString(),
            'is_fraudulent' => $isFraudulent,
        ]);
    }

    private function determineResponseStrategy(Chargeback $chargeback): void
    {
        $details = $chargeback->getAnalysis();
        $reasonCode = $details['reason_code'];
        $isFraudulent = $chargeback->isFraudIndicator();

        $strategy = match (true) {
            $isFraudulent => 'accept',
            $reasonCode === 'duplicate' => 'accept',
            $reasonCode === 'fraud' && !$isFraudulent => 'contest_with_proof',
            default => 'contest_with_proof',
        };

        $chargeback->setResponseStrategy($strategy);
        $this->chargebackRepository->save($chargeback);

        $this->logger->debug('Response strategy determined', [
            'chargeback_id' => $chargeback->getId()->toString(),
            'strategy' => $strategy,
        ]);
    }

    private function prepareEvidence(Chargeback $chargeback): void
    {
        $evidence = $this->chargebackService->prepareEvidence($chargeback);

        $chargeback->setEvidence($evidence);
        $this->chargebackRepository->save($chargeback);

        $this->logger->debug('Evidence prepared', [
            'chargeback_id' => $chargeback->getId()->toString(),
            'evidence_count' => count($evidence),
        ]);
    }

    private function submitResponse(Chargeback $chargeback): void
    {
        $result = $this->chargebackService->submitResponse($chargeback);

        if ($result->isSuccessful()) {
            $chargeback->setStatus('response_submitted');
            $chargeback->setSubmittedAt(new \DateTimeImmutable());
        } else {
            $chargeback->setStatus('response_failed');
            $this->logger->warning('Response submission failed', [
                'chargeback_id' => $chargeback->getId()->toString(),
                'error' => $result->getError(),
            ]);
        }

        $this->chargebackRepository->save($chargeback);

        $this->logger->debug('Response submitted', [
            'chargeback_id' => $chargeback->getId()->toString(),
            'successful' => $result->isSuccessful(),
        ]);
    }

    private function updateOrderStatus(Chargeback $chargeback): void
    {
        $this->orderService->updateForChargeback(
            $chargeback->getOrderId()->toString(),
            $chargeback->getStatus()
        );

        $this->logger->debug('Order status updated', ['chargeback_id' => $chargeback->getId()->toString()]);
    }

    private function notifyParties(Chargeback $chargeback): void
    {
        $notifications = [];

        if ($chargeback->isFraudIndicator()) {
            $notifications[] = [
                'type' => 'fraud_team',
                'message' => 'Potential fraud chargeback received',
            ];
        }

        $notifications[] = [
            'type' => 'customer_service',
            'message' => 'Chargeback response submitted',
        ];

        foreach ($notifications as $notification) {
            $this->notificationService->send(
                $notification['type'],
                $notification['message'],
                ['chargeback_id' => $chargeback->getId()->toString()]
            );
        }

        $this->logger->debug('Parties notified', ['chargeback_id' => $chargeback->getId()->toString()]);
    }
}
