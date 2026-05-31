<?php

declare(strict_types=1);

namespace App\Payment\Stripe;

use App\Entity\Payment;
use App\Repository\PaymentRepository;
use App\Service\PaymentConfig;
use App\Service\AmountNormalizer;
use Psr\Log\LoggerInterface;
use Stripe\StripeClient;
use Stripe\Exception\CardException;

final class StripePaymentProcessor
{
    private StripeClient $stripe;

    public function __construct(
        private readonly PaymentRepository $paymentRepository,
        private readonly PaymentConfig $config,
        private readonly AmountNormalizer $amountNormalizer,
        private readonly LoggerInterface $logger,
    ) {
        $this->stripe = new StripeClient($this->config->getStripeSecretKey());
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

        if (!$this->validateCardData($payment)) {
            throw new \InvalidArgumentException('Invalid card data provided');
        }

        $normalizedAmount = $this->amountNormalizer->normalize(
            $payment->getAmount(),
            $payment->getCurrency()
        );

        try {
            $charge = $this->stripe->paymentIntents->create([
                'amount' => $normalizedAmount,
                'currency' => strtolower($payment->getCurrency()),
                'payment_method' => $payment->getPaymentMethodId(),
                'confirm' => true,
                'return_url' => $this->config->getReturnUrl(),
            ]);

            $payment->setStatus('succeeded');
            $payment->setExternalReference($charge->id);
            $payment->setProcessedAt(new \DateTimeImmutable());

            $this->paymentRepository->save($payment);

            $this->logger->info('Stripe payment processed successfully', [
                'payment_id' => $paymentId,
                'charge_id' => $charge->id,
            ]);

            return $payment;

        } catch (CardException $e) {
            $payment->setStatus('failed');
            $payment->setFailureReason($e->getMessage());
            $this->paymentRepository->save($payment);

            $this->logger->error('Stripe payment failed', [
                'payment_id' => $paymentId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function validateCardData(Payment $payment): bool
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

        if ($expiryYear === $currentYear && $cardData['exp_month'] < (int) date('n')) {
            return false;
        }

        return true;
    }
}
