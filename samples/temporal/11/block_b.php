<?php
declare(strict_types=1);

namespace Stripe\Billing\Handler;

use Stripe\Billing\Entity\PaymentIntent;
use Stripe\Billing\Entity\Charge;
use Stripe\Billing\Entity\Receipt;
use Stripe\Billing\Repository\PaymentRepository;
use Stripe\Billing\Repository\CustomerRepository;
use Stripe\Billing\Service\FraudDetectionService;
use Stripe\Core\Database\TransactionManager;
use Stripe\Core\Logging\AuditLogger;
use Stripe\Core\Exception\PaymentDeclinedException;

final class PaymentProcessingHandler
{
    private PaymentRepository $paymentRepository;
    private CustomerRepository $customerRepository;
    private FraudDetectionService $fraudService;
    private TransactionManager $txManager;
    private AuditLogger $auditLogger;

    public function __construct(
        PaymentRepository $paymentRepository,
        CustomerRepository $customerRepository,
        FraudDetectionService $fraudService,
        TransactionManager $txManager,
        AuditLogger $auditLogger
    ) {
        $this->paymentRepository = $paymentRepository;
        $this->customerRepository = $customerRepository;
        $this->fraudService = $fraudService;
        $this->txManager = $txManager;
        $this->auditLogger = $auditLogger;
    }

    public function processPayment(string $customerId, string $paymentMethodId, int $amountInCents, string $currency): PaymentResult
    {
        $this->auditLogger->log('payment_attempt', [
            'customer_id' => $customerId,
            'amount' => $amountInCents,
            'currency' => $currency
        ]);

        $customer = $this->customerRepository->findOrFail($customerId);

        $fraudScore = $this->fraudService->evaluateTransaction(
            $customerId,
            $amountInCents,
            $paymentMethodId
        );

        if ($fraudScore > 0.85) {
            $this->auditLogger->log('payment_blocked_fraud', [
                'customer_id' => $customerId,
                'fraud_score' => $fraudScore
            ]);
            throw new PaymentDeclinedException(
                'Transaction flagged by fraud detection',
                'fraud_block'
            );
        }

        $paymentIntent = $this->paymentRepository->createPaymentIntent(
            $customerId,
            $amountInCents,
            $currency
        );

        try {
            $charge = $this->paymentRepository->charge(
                $paymentIntent->getId(),
                $paymentMethodId
            );

            $this->paymentRepository->updatePaymentIntentStatus(
                $paymentIntent->getId(),
                'succeeded'
            );

            $receipt = new Receipt([
                'customer_id' => $customerId,
                'charge_id' => $charge->getId(),
                'amount' => $amountInCents,
                'currency' => $currency,
                'status' => 'issued',
                'issued_at' => new \DateTimeImmutable()
            ]);
            $this->paymentRepository->saveReceipt($receipt);

            $this->customerRepository->updateLifetimeValue(
                $customerId,
                $amountInCents
            );

            $this->auditLogger->log('payment_success', [
                'customer_id' => $customerId,
                'charge_id' => $charge->getId(),
                'amount' => $amountInCents
            ]);

            return new PaymentResult([
                'success' => true,
                'charge_id' => $charge->getId(),
                'receipt_url' => $receipt->getUrl()
            ]);

        } catch (\Throwable $e) {
            $this->paymentRepository->updatePaymentIntentStatus(
                $paymentIntent->getId(),
                'failed',
                $e->getMessage()
            );

            $this->auditLogger->log('payment_failed', [
                'customer_id' => $customerId,
                'intent_id' => $paymentIntent->getId(),
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }
}
