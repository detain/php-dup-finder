<?php
declare(strict_types=1);

namespace App\Payment\Processing;

use App\Domain\Entity\Payment;
use App\Domain\Entity\PaymentTransaction;
use App\Domain\Repository\PaymentRepositoryInterface;
use App\Domain\Service\PaymentGatewayInterface;
use App\Domain\Service\FraudDetectionServiceInterface;
use App\Domain\Service\NotificationServiceInterface;
use App\Domain\Service\LedgerServiceInterface;
use Psr\Log\LoggerInterface;

final readonly class AchPaymentWorkflow
{
    public function __construct(
        private PaymentRepositoryInterface $paymentRepository,
        private PaymentGatewayInterface $paymentGateway,
        private FraudDetectionServiceInterface $fraudService,
        private NotificationServiceInterface $notificationService,
        private LedgerServiceInterface $ledgerService,
        private LoggerInterface $logger,
    ) {}

    public function processPayment(string $paymentId): void
    {
        $payment = $this->paymentRepository->findById($paymentId);
        if ($payment === null) {
            throw new \RuntimeException("Payment not found: {$paymentId}");
        }

        $this->logger->info('Starting ACH payment workflow', ['payment_id' => $paymentId]);

        $this->validatePayment($payment);

        $this->runFraudChecks($payment);

        $this->initiateAchTransfer($payment);

        $this->sendPendingNotification($payment);

        $this->updatePaymentStatus($payment, 'pending_verification');

        $this->recordAuditEvent($payment, 'ach_initiated');

        $this->logger->info('ACH payment workflow completed', ['payment_id' => $paymentId]);
    }

    private function validatePayment(Payment $payment): void
    {
        if ($payment->getAmount() <= 0) {
            throw new \RuntimeException("Payment amount must be positive");
        }

        if ($payment->getPaymentMethod() !== 'ach') {
            throw new \RuntimeException("Invalid payment method for this workflow");
        }

        if ($payment->getBankAccountToken() === null) {
            throw new \RuntimeException("Bank account token is required");
        }

        $this->logger->debug('Payment validation passed', ['payment_id' => $payment->getId()->toString()]);
    }

    private function runFraudChecks(Payment $payment): void
    {
        $fraudResult = $this->fraudService->analyze($payment, [
            'amount' => $payment->getAmount(),
            'customer_id' => $payment->getCustomerId()->toString(),
            'bank_account_token' => $payment->getBankAccountToken(),
            'billing_address' => $payment->getBillingAddress(),
        ]);

        if ($fraudResult->isRejected()) {
            $this->recordAuditEvent($payment, 'fraud_rejected', [
                'reason' => $fraudResult->getReason(),
                'score' => $fraudResult->getScore(),
            ]);
            throw new \RuntimeException("Payment rejected by fraud detection: {$fraudResult->getReason()}");
        }

        $payment->setFraudScore($fraudResult->getScore());
        $this->logger->debug('Fraud checks passed', [
            'payment_id' => $payment->getId()->toString(),
            'score' => $fraudResult->getScore(),
        ]);
    }

    private function initiateAchTransfer(Payment $payment): void
    {
        $transferResult = $this->paymentGateway-> initiateAchTransfer([
            'bank_account_token' => $payment->getBankAccountToken(),
            'amount' => $payment->getAmount(),
            'currency' => $payment->getCurrency(),
            'customer_id' => $payment->getCustomerId()->toString(),
            'billing_address' => $payment->getBillingAddress(),
        ]);

        if (!$transferResult->isSuccessful()) {
            $this->recordAuditEvent($payment, 'ach_initiation_failed', [
                'error' => $transferResult->getErrorMessage(),
            ]);
            throw new \RuntimeException("ACH transfer initiation failed: {$transferResult->getErrorMessage()}");
        }

        $payment->setGatewayTransactionId($transferResult->getTransactionId());
        $payment->setTraceNumber($transferResult->getTraceNumber());
        $this->paymentRepository->save($payment);

        $this->recordAuditEvent($payment, 'ach_transfer_initiated', [
            'gateway_transaction_id' => $transferResult->getTransactionId(),
            'trace_number' => $transferResult->getTraceNumber(),
        ]);

        $this->logger->debug('ACH transfer initiated', [
            'payment_id' => $payment->getId()->toString(),
            'trace_number' => $transferResult->getTraceNumber(),
        ]);
    }

    private function sendPendingNotification(Payment $payment): void
    {
        $this->notificationService->send(
            $payment->getCustomerId(),
            'ach_pending',
            [
                'payment_id' => $payment->getId()->toString(),
                'amount' => $payment->getAmount(),
                'currency' => $payment->getCurrency(),
                'trace_number' => $payment->getTraceNumber(),
            ]
        );

        $this->recordAuditEvent($payment, 'pending_notification_sent');
        $this->logger->debug('Pending notification sent', ['payment_id' => $payment->getId()->toString()]);
    }

    private function updatePaymentStatus(Payment $payment, string $status): void
    {
        $payment->setStatus($status);
        $this->paymentRepository->save($payment);
    }

    private function recordAuditEvent(Payment $payment, string $event, array $data = []): void
    {
        $this->logger->info('Audit event', array_merge([
            'payment_id' => $payment->getId()->toString(),
            'event' => $event,
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ], $data));
    }
}
