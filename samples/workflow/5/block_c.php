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

final readonly class WireTransferPaymentWorkflow
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

        $this->logger->info('Starting wire transfer payment workflow', ['payment_id' => $paymentId]);

        $this->validatePayment($payment);

        $this->runFraudChecks($payment);

        $this->initiateWireTransfer($payment);

        $this->sendWireInstructions($payment);

        $this->updatePaymentStatus($payment, 'awaiting_funds');

        $this->recordAuditEvent($payment, 'wire_initiated');

        $this->logger->info('Wire transfer payment workflow completed', ['payment_id' => $paymentId]);
    }

    private function validatePayment(Payment $payment): void
    {
        if ($payment->getAmount() < 1000) {
            throw new \RuntimeException("Wire transfers require minimum amount of 1000");
        }

        if ($payment->getPaymentMethod() !== 'wire') {
            throw new \RuntimeException("Invalid payment method for this workflow");
        }

        if ($payment->getBeneficiaryDetails() === null) {
            throw new \RuntimeException("Beneficiary details are required for wire transfer");
        }

        $this->logger->debug('Payment validation passed', ['payment_id' => $payment->getId()->toString()]);
    }

    private function runFraudChecks(Payment $payment): void
    {
        $fraudResult = $this->fraudService->analyze($payment, [
            'amount' => $payment->getAmount(),
            'customer_id' => $payment->getCustomerId()->toString(),
            'beneficiary' => $payment->getBeneficiaryDetails(),
        ]);

        if ($fraudResult->isRejected()) {
            $this->recordAuditEvent($payment, 'fraud_rejected', [
                'reason' => $fraudResult->getReason(),
            ]);
            throw new \RuntimeException("Payment rejected by fraud detection: {$fraudResult->getReason()}");
        }

        $payment->setFraudScore($fraudResult->getScore());
        $this->logger->debug('Fraud checks passed', ['payment_id' => $payment->getId()->toString()]);
    }

    private function initiateWireTransfer(Payment $payment): void
    {
        $transferResult = $this->paymentGateway->initiateWireTransfer([
            'amount' => $payment->getAmount(),
            'currency' => $payment->getCurrency(),
            'customer_id' => $payment->getCustomerId()->toString(),
            'beneficiary' => $payment->getBeneficiaryDetails(),
        ]);

        if (!$transferResult->isSuccessful()) {
            $this->recordAuditEvent($payment, 'wire_initiation_failed', [
                'error' => $transferResult->getErrorMessage(),
            ]);
            throw new \RuntimeException("Wire transfer initiation failed: {$transferResult->getErrorMessage()}");
        }

        $payment->setGatewayTransactionId($transferResult->getTransactionId());
        $payment->setReferenceNumber($transferResult->getReferenceNumber());
        $this->paymentRepository->save($payment);

        $this->recordAuditEvent($payment, 'wire_transfer_initiated', [
            'gateway_transaction_id' => $transferResult->getTransactionId(),
            'reference_number' => $transferResult->getReferenceNumber(),
        ]);

        $this->logger->debug('Wire transfer initiated', [
            'payment_id' => $payment->getId()->toString(),
            'reference' => $transferResult->getReferenceNumber(),
        ]);
    }

    private function sendWireInstructions(Payment $payment): void
    {
        $this->notificationService->send(
            $payment->getCustomerId(),
            'wire_instructions',
            [
                'payment_id' => $payment->getId()->toString(),
                'amount' => $payment->getAmount(),
                'currency' => $payment->getCurrency(),
                'reference_number' => $payment->getReferenceNumber(),
                'bank_instructions' => $this->getBankInstructions(),
            ]
        );

        $this->recordAuditEvent($payment, 'wire_instructions_sent');
        $this->logger->debug('Wire instructions sent', ['payment_id' => $payment->getId()->toString()]);
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

    private function getBankInstructions(): array
    {
        return [
            'bank_name' => 'Example Bank',
            'account_number' => '1234567890',
            'routing_number' => '021000021',
            'swift_code' => 'EXAMPLEUS33',
        ];
    }
}
