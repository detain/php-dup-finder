<?php

declare(strict_types=1);

namespace App\Payment\Sage;

use App\Entity\Payment;
use App\Repository\PaymentRepository;
use App\Service\PaymentConfig;
use App\Service\AmountNormalizer;
use Psr\Log\LoggerInterface;
use Sage\SagePay\Api\Client as SageClient;

final class SagePayPaymentProcessor
{
    private SageClient $sagepay;

    public function __construct(
        private readonly PaymentRepository $paymentRepository,
        private readonly PaymentConfig $config,
        private readonly AmountNormalizer $amountNormalizer,
        private readonly LoggerInterface $logger,
    ) {
        $this->sagepay = new SageClient($this->config->getSagePayVendorId());
    }

    public function processPayment(int $paymentId): Payment
    {
        $payment = $this->paymentRepository->findById($paymentId);

        if ($payment === null) {
            throw new \RuntimeException("Payment {$paymentId} not found");
        }

        if ($payment->getStatus() !== 'pending') {
            throw new \RuntimeException("Payment {$paymentId} has already been processed");
        }

        if (!$this->validateTransactionData($payment)) {
            throw new \InvalidArgumentException('Invalid transaction data provided');
        }

        $normalizedAmount = $this->amountNormalizer->normalize(
            $payment->getAmount(),
            $payment->getCurrency()
        );

        try {
            $transactionRef = $this->sagepay->registerTransaction([
                'vendorTxCode' => 'PAY-' . $paymentId . '-' . time(),
                'amount' => $normalizedAmount / 100,
                'currency' => $payment->getCurrency(),
                'description' => 'Payment for order ' . $paymentId,
                'customerEmail' => $payment->getCustomerEmail(),
                'billingAddress' => $payment->getBillingAddress(),
                'deliveryAddress' => $payment->getShippingAddress(),
            ]);

            $payment->setStatus('pending');
            $payment->setExternalReference($transactionRef);
            $payment->setProcessedAt(new \DateTimeImmutable());

            $this->paymentRepository->save($payment);

            $this->logger->info('SagePay transaction registered successfully', [
                'payment_id' => $paymentId,
                'vendor_tx_code' => $transactionRef,
            ]);

            return $payment;

        } catch (\Exception $e) {
            $payment->setStatus('failed');
            $payment->setFailureReason($e->getMessage());
            $this->paymentRepository->save($payment);

            $this->logger->error('SagePay payment failed', [
                'payment_id' => $paymentId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function validateTransactionData(Payment $payment): bool
    {
        if (empty($payment->getCustomerEmail())) {
            return false;
        }

        if (!filter_var($payment->getCustomerEmail(), FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        if (empty($payment->getBillingAddress())) {
            return false;
        }

        $billingAddress = $payment->getBillingAddress();
        if (empty($billingAddress['address1']) || empty($billingAddress['city']) || empty($billingAddress['postal_code'])) {
            return false;
        }

        return true;
    }
}
