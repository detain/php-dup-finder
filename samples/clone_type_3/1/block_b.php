<?php

declare(strict_types=1);

namespace App\Payment\PayPal;

use App\Entity\Payment;
use App\Repository\PaymentRepository;
use App\Service\PaymentConfig;
use App\Service\AmountNormalizer;
use Psr\Log\LoggerInterface;
use PayPalCheckoutSdk\Orders\OrdersCreateRequest;
use PayPalCheckoutSdk\Orders\OrdersCaptureRequest;
use PayPalCheckoutSdk\Core\PayPalHttpClient;

final class PayPalPaymentProcessor
{
    private PayPalHttpClient $paypal;

    public function __construct(
        private readonly PaymentRepository $paymentRepository,
        private readonly PaymentConfig $config,
        private readonly AmountNormalizer $amountNormalizer,
        private readonly LoggerInterface $logger,
    ) {
        $this->paypal = new PayPalHttpClient($this->config->getPayPalEnvironment());
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

        $normalizedAmount = $this->amountNormalizer->normalize(
            $payment->getAmount(),
            $payment->getCurrency()
        );

        $request = new OrdersCreateRequest();
        $request->prefer('return=representation');
        $request->body = [
            'intent' => 'CAPTURE',
            'purchase_units' => [
                [
                    'amount' => [
                        'currency_code' => strtoupper($payment->getCurrency()),
                        'value' => (string) ($normalizedAmount / 100),
                    ],
                    'reference_id' => (string) $paymentId,
                ],
            ],
        ];

        try {
            $response = $this->paypal->execute($request);

            $payment->setStatus('authorized');
            $payment->setExternalReference($response->result->id);
            $payment->setProcessedAt(new \DateTimeImmutable());

            $this->paymentRepository->save($payment);

            $this->logger->info('PayPal order created successfully', [
                'payment_id' => $paymentId,
                'order_id' => $response->result->id,
            ]);

            return $payment;

        } catch (\Exception $e) {
            $payment->setStatus('failed');
            $payment->setFailureReason($e->getMessage());
            $this->paymentRepository->save($payment);

            $this->logger->error('PayPal payment failed', [
                'payment_id' => $paymentId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
