<?php

declare(strict_types=1);

namespace App\Payment;

use App\Entity\Payment;
use App\Repository\PaymentRepository;
use App\Service\PaymentConfig;
use App\Service\AmountNormalizer;
use Psr\Log\LoggerInterface;

interface PaymentProcessorInterface
{
    public function process(Payment $payment): Payment;
    public function supports(Payment $payment): bool;
}

abstract class AbstractPaymentProcessor
{
    public function __construct(
        protected readonly PaymentRepository $paymentRepository,
        protected readonly PaymentConfig $config,
        protected readonly AmountNormalizer $amountNormalizer,
        protected readonly LoggerInterface $logger,
    ) {}

    public function processPayment(int $paymentId): Payment
    {
        $payment = $this->paymentRepository->findById($paymentId);

        if ($payment === null) {
            throw new \RuntimeException("Payment {$paymentId} not found");
        }

        if ($payment->getStatus() !== 'pending') {
            throw new \RuntimeException("Payment {$paymentId} has already been processed");
        }

        if (!$this->validatePayment($payment)) {
            throw new \InvalidArgumentException('Validation failed for this payment');
        }

        return $this->executePayment($payment);
    }

    protected function validatePayment(Payment $payment): bool
    {
        return true;
    }

    abstract protected function executePayment(Payment $payment): Payment;

    protected function normalizeAmount(Payment $payment): int
    {
        return $this->amountNormalizer->normalize(
            $payment->getAmount(),
            $payment->getCurrency()
        );
    }

    protected function markPaymentSucceeded(Payment $payment, string $externalReference): void
    {
        $payment->setStatus('succeeded');
        $payment->setExternalReference($externalReference);
        $payment->setProcessedAt(new \DateTimeImmutable());
        $this->paymentRepository->save($payment);
    }

    protected function markPaymentFailed(Payment $payment, string $reason): void
    {
        $payment->setStatus('failed');
        $payment->setFailureReason($reason);
        $this->paymentRepository->save($payment);

        $this->logger->error('Payment processing failed', [
            'payment_id' => $payment->getId(),
            'reason' => $reason,
        ]);
    }
}

final class StripePaymentProcessor extends AbstractPaymentProcessor implements PaymentProcessorInterface
{
    public function supports(Payment $payment): bool
    {
        return $payment->getProvider() === 'stripe';
    }

    protected function validatePayment(Payment $payment): bool
    {
        $cardData = $payment->getCardData();

        if (empty($cardData['number']) || empty($cardData['exp_month']) || empty($cardData['exp_year'])) {
            return false;
        }

        if (!preg_match('/^\d{16}$/', str_replace(' ', '', $cardData['number']))) {
            return false;
        }

        if ($cardData['exp_month'] < 1 || $cardData['exp_month'] > 12) {
            return false;
        }

        $currentYear = (int) date('Y');
        $expiryYear = (int) $cardData['exp_year'];
        if ($expiryYear < $currentYear) {
            return false;
        }

        return !($expiryYear === $currentYear && $cardData['exp_month'] < (int) date('n'));
    }

    protected function executePayment(Payment $payment): Payment
    {
        $stripe = new \Stripe\StripeClient($this->config->getStripeSecretKey());
        $normalizedAmount = $this->normalizeAmount($payment);

        try {
            $charge = $stripe->paymentIntents->create([
                'amount' => $normalizedAmount,
                'currency' => strtolower($payment->getCurrency()),
                'payment_method' => $payment->getPaymentMethodId(),
                'confirm' => true,
                'return_url' => $this->config->getReturnUrl(),
            ]);

            $this->markPaymentSucceeded($payment, $charge->id);
            return $payment;

        } catch (\Exception $e) {
            $this->markPaymentFailed($payment, $e->getMessage());
            throw $e;
        }
    }
}

final class PaymentOrchestrator
{
    /** @var array<int, PaymentProcessorInterface> */
    private array $processors = [];

    public function registerProcessor(PaymentProcessorInterface $processor): void
    {
        $this->processors[] = $processor;
    }

    public function processPayment(int $paymentId): Payment
    {
        $payment = $this->paymentRepository->findById($paymentId);

        foreach ($this->processors as $processor) {
            if ($processor->supports($payment)) {
                return $processor->process($payment);
            }
        }

        throw new \RuntimeException('No suitable processor found for payment');
    }
}
