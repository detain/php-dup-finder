<?php

declare(strict_types=1);

namespace App\PaymentProcessing;

use App\Entity\Payment;
use App\Repository\PaymentRepository;
use App\Service\FraudDetector;
use App\Event\PaymentProcessedEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class PaymentProcessingService
{
    public function __construct(
        private readonly PaymentRepository $paymentRepository,
        private readonly FraudDetector $fraudDetector,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly LoggerInterface $logger,
    ) {}

    public function processPayment(int $paymentId): Payment
    {
        $payment = $this->paymentRepository->findById($paymentId);

        if ($payment === null) {
            throw new \RuntimeException('Payment not found');
        }

        if ($payment->getStatus() !== 'pending') {
            throw new \InvalidArgumentException('Payment has already been processed');
        }

        if ($payment->getAmount() <= 0) {
            throw new \InvalidArgumentException('Payment amount must be positive');
        }

        if ($payment->getAmount() > 1000000) {
            throw new \InvalidArgumentException('Payment amount exceeds maximum limit');
        }

        if ($payment->getCurrency() === null) {
            throw new \InvalidArgumentException('Payment currency is required');
        }

        $validCurrencies = ['USD', 'EUR', 'GBP', 'JPY', 'CAD', 'AUD'];
        if (!in_array($payment->getCurrency(), $validCurrencies, true)) {
            throw new \InvalidArgumentException('Unsupported currency');
        }

        if ($payment->getCustomerId() === null) {
            throw new \InvalidArgumentException('Payment must have a customer');
        }

        $customer = $this->loadCustomer($payment->getCustomerId());
        if ($customer === null) {
            throw new \RuntimeException('Customer not found');
        }

        if ($customer->getStatus() !== 'active') {
            throw new \InvalidArgumentException('Customer account is not active');
        }

        if ($customer->isBlocked()) {
            throw new \InvalidArgumentException('Customer is blocked from making payments');
        }

        $fraudScore = $this->fraudDetector->calculateScore($payment);

        if ($fraudScore > 80) {
            $payment->setStatus('rejected');
            $payment->setRejectionReason('High fraud risk detected');
            $this->paymentRepository->save($payment);

            $this->logger->warning('Payment rejected due to fraud risk', [
                'payment_id' => $paymentId,
                'fraud_score' => $fraudScore,
            ]);

            throw new \InvalidArgumentException('Payment cannot be processed due to fraud risk');
        }

        if ($fraudScore > 50) {
            $payment->setRequiresManualReview(true);
            $this->logger->info('Payment flagged for manual review', [
                'payment_id' => $paymentId,
                'fraud_score' => $fraudScore,
            ]);
        }

        $this->processPaymentThroughGateway($payment);

        $payment->setStatus('processing');
        $payment->setProcessedAt(new \DateTimeImmutable());
        $this->paymentRepository->save($payment);

        $this->eventDispatcher->dispatch(
            new PaymentProcessedEvent($payment),
            PaymentProcessedEvent::NAME
        );

        $this->logger->info('Payment processing started', [
            'payment_id' => $paymentId,
            'amount' => $payment->getAmount(),
        ]);

        return $payment;
    }

    public function completePayment(int $paymentId, string $gatewayReference): Payment
    {
        $payment = $this->paymentRepository->findById($paymentId);

        if ($payment === null) {
            throw new \RuntimeException('Payment not found');
        }

        if ($payment->getStatus() !== 'processing') {
            throw new \InvalidArgumentException('Payment is not in processing state');
        }

        if ($payment->requiresManualReview()) {
            throw new \InvalidArgumentException('Payment is under manual review');
        }

        $payment->setStatus('completed');
        $payment->setGatewayReference($gatewayReference);
        $payment->setCompletedAt(new \DateTimeImmutable());

        $this->paymentRepository->save($payment);

        $this->eventDispatcher->dispatch(
            new PaymentProcessedEvent($payment),
            PaymentProcessedEvent::NAME
        );

        $this->logger->info('Payment completed', [
            'payment_id' => $paymentId,
            'gateway_reference' => $gatewayReference,
        ]);

        return $payment;
    }

    public function refundPayment(int $paymentId, int $amount): Payment
    {
        $payment = $this->paymentRepository->findById($paymentId);

        if ($payment === null) {
            throw new \RuntimeException('Payment not found');
        }

        if ($payment->getStatus() !== 'completed') {
            throw new \InvalidArgumentException('Only completed payments can be refunded');
        }

        if ($amount <= 0) {
            throw new \InvalidArgumentException('Refund amount must be positive');
        }

        if ($amount > $payment->getAmount()) {
            throw new \InvalidArgumentException('Refund amount cannot exceed payment amount');
        }

        $alreadyRefunded = $payment->getRefundedAmount();
        if ($alreadyRefunded + $amount > $payment->getAmount()) {
            throw new \InvalidArgumentException('Total refund would exceed payment amount');
        }

        $payment->setRefundedAmount($alreadyRefunded + $amount);

        if ($payment->getRefundedAmount() >= $payment->getAmount()) {
            $payment->setStatus('refunded');
        }

        $this->paymentRepository->save($payment);

        $this->logger->info('Payment refunded', [
            'payment_id' => $paymentId,
            'refund_amount' => $amount,
        ]);

        return $payment;
    }

    private function loadCustomer(int $customerId): ?Customer
    {
        return $this->customerRepository->findById($customerId);
    }

    private function processPaymentThroughGateway(Payment $payment): void
    {
    }
}
