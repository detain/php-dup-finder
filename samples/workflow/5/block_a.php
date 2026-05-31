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

final readonly class CreditCardPaymentWorkflow
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

        $this->logger->info('Starting credit card payment workflow', ['payment_id' => $paymentId]);

        $this->validatePayment($payment);

        $this->runFraudChecks($payment);

        $this->authorizeCard($payment);

        $this->capturePayment($payment);

        $this->recordInLedger($payment);

        $this->sendReceipt($payment);

        $this->updatePaymentStatus($payment, 'completed');

        $this->recordAuditEvent($payment, 'payment_completed');

        $this->logger->info('Credit card payment workflow completed', ['payment_id' => $paymentId]);
    }

    private function validatePayment(Payment $payment): void
    {
        if ($payment->getAmount() <= 0) {
            throw new \RuntimeException("Payment amount must be positive");
        }

        if ($payment->getPaymentMethod() !== 'credit_card') {
            throw new \RuntimeException("Invalid payment method for this workflow");
        }

        if ($payment->getCardToken() === null) {
            throw new \RuntimeException("Card token is required");
        }

        $this->logger->debug('Payment validation passed', ['payment_id' => $payment->getId()->toString()]);
    }

    private function runFraudChecks(Payment $payment): void
    {
        $fraudResult = $this->fraudService->analyze($payment, [
            'amount' => $payment->getAmount(),
            'customer_id' => $payment->getCustomerId()->toString(),
            'card_token' => $payment->getCardToken(),
            'billing_address' => $payment->getBillingAddress(),
        ]);

        if ($fraudResult->isRejected()) {
            $this->recordAuditEvent($payment, 'fraud_rejected', [
                'reason' => $fraudResult->getReason(),
                'score' => $fraudResult->getScore(),
            ]);
            throw new \RuntimeException("Payment rejected by fraud detection: {$fraudResult->getReason()}");
        }

        if ($fraudResult->isFlagged()) {
            $this->recordAuditEvent($payment, 'fraud_flagged', [
                'reason' => $fraudResult->getReason(),
                'score' => $fraudResult->getScore(),
            ]);
        }

        $payment->setFraudScore($fraudResult->getScore());
        $this->logger->debug('Fraud checks passed', [
            'payment_id' => $payment->getId()->toString(),
            'score' => $fraudResult->getScore(),
        ]);
    }

    private function authorizeCard(Payment $payment): void
    {
        $authResult = $this->paymentGateway->authorize([
            'card_token' => $payment->getCardToken(),
            'amount' => $payment->getAmount(),
            'currency' => $payment->getCurrency(),
            'customer_id' => $payment->getCustomerId()->toString(),
            'billing_address' => $payment->getBillingAddress(),
        ]);

        if (!$authResult->isSuccessful()) {
            $this->recordAuditEvent($payment, 'authorization_failed', [
                'error' => $authResult->getErrorMessage(),
                'error_code' => $authResult->getErrorCode(),
            ]);
            throw new \RuntimeException("Card authorization failed: {$authResult->getErrorMessage()}");
        }

        $payment->setAuthorizationCode($authResult->getAuthorizationCode());
        $payment->setGatewayTransactionId($authResult->getTransactionId());
        $this->paymentRepository->save($payment);

        $this->recordAuditEvent($payment, 'card_authorized', [
            'authorization_code' => $authResult->getAuthorizationCode(),
            'gateway_transaction_id' => $authResult->getTransactionId(),
        ]);

        $this->logger->debug('Card authorized', [
            'payment_id' => $payment->getId()->toString(),
            'auth_code' => $authResult->getAuthorizationCode(),
        ]);
    }

    private function capturePayment(Payment $payment): void
    {
        $captureResult = $this->paymentGateway->capture([
            'transaction_id' => $payment->getGatewayTransactionId(),
            'amount' => $payment->getAmount(),
        ]);

        if (!$captureResult->isSuccessful()) {
            $this->paymentGateway->void(['transaction_id' => $payment->getGatewayTransactionId()]);
            $this->recordAuditEvent($payment, 'capture_failed', [
                'error' => $captureResult->getErrorMessage(),
            ]);
            throw new \RuntimeException("Payment capture failed: {$captureResult->getErrorMessage()}");
        }

        $this->recordAuditEvent($payment, 'payment_captured', [
            'capture_id' => $captureResult->getCaptureId(),
        ]);

        $this->logger->debug('Payment captured', ['payment_id' => $payment->getId()->toString()]);
    }

    private function recordInLedger(Payment $payment): void
    {
        $this->ledgerService->recordPayment([
            'payment_id' => $payment->getId()->toString(),
            'customer_id' => $payment->getCustomerId()->toString(),
            'amount' => $payment->getAmount(),
            'currency' => $payment->getCurrency(),
            'type' => 'charge',
            'reference' => $payment->getGatewayTransactionId(),
        ]);

        $this->recordAuditEvent($payment, 'ledger_recorded');
        $this->logger->debug('Payment recorded in ledger', ['payment_id' => $payment->getId()->toString()]);
    }

    private function sendReceipt(Payment $payment): void
    {
        $this->notificationService->send(
            $payment->getCustomerId(),
            'payment_receipt',
            [
                'payment_id' => $payment->getId()->toString(),
                'amount' => $payment->getAmount(),
                'currency' => $payment->getCurrency(),
                'authorization_code' => $payment->getAuthorizationCode(),
            ]
        );

        $this->recordAuditEvent($payment, 'receipt_sent');
        $this->logger->debug('Receipt sent', ['payment_id' => $payment->getId()->toString()]);
    }

    private function updatePaymentStatus(Payment $payment, string $status): void
    {
        $payment->setStatus($status);
        $payment->setCompletedAt(new \DateTimeImmutable());
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
