<?php
declare(strict_types=1);

namespace Payment\Gateway;

use Doctrine\ORM\EntityManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Stripe\Stripe;
use Stripe\Charge as StripeCharge;
use Stripe\Exception\CardException;
use Stripe\Exception\ApiException;

final class PaymentGatewayHandler
{
    public function __construct(
        private readonly EntityManager $entityManager,
        private readonly LoggerInterface $logger,
        private readonly PaymentProcessor $processor,
        private readonly FraudDetectionService $fraudService,
        private readonly NotificationService $notifications
    ) {}

    public function processPayment(Request $request): PaymentResult
    {
        $orderId = $request->request->getInt('order_id');
        $paymentMethod = $request->request->get('payment_method_id');
        $amount = (int) ($request->request->get('amount', 0) * 100);

        $this->logger->info('Processing payment', [
            'order_id' => $orderId,
            'amount_cents' => $amount,
            'payment_method' => substr($paymentMethod, 0, 8) . '...'
        ]);

        try {
            $order = $this->entityManager->find(Order::class, $orderId);

            if ($order === null) {
                $this->logger->error('Order not found for payment', [
                    'order_id' => $orderId
                ]);
                return PaymentResult::failure('Order not found');
            }

            // Fraud detection check
            $fraudScore = $this->fraudService->evaluate($order);
            if ($fraudScore > 0.8) {
                $this->logger->warning('Payment blocked by fraud detection', [
                    'order_id' => $orderId,
                    'fraud_score' => $fraudScore
                ]);
                return PaymentResult::fraudBlocked();
            }

            // Process via Stripe
            Stripe::setApiKey($_ENV['STRIPE_SECRET_KEY']);

            $charge = StripeCharge::create([
                'amount' => $amount,
                'currency' => strtolower($order->getCurrency()),
                'customer' => $order->getCustomer()->getStripeCustomerId(),
                'payment_method' => $paymentMethod,
                'confirm' => true,
                'off_session' => true,
                'metadata' => [
                    'order_id' => $orderId,
                    'customer_email' => $order->getCustomer()->getEmail()
                ]
            ]);

            $this->logger->info('Payment successful', [
                'order_id' => $orderId,
                'charge_id' => $charge->id,
                'amount_cents' => $amount
            ]);

            // Record transaction
            $transaction = new PaymentTransaction();
            $transaction->setOrder($order);
            $transaction->setChargeId($charge->id);
            $transaction->setAmount($amount);
            $transaction->setCurrency($order->getCurrency());
            $transaction->setStatus('succeeded');
            $transaction->setProcessedAt(new \DateTimeImmutable());

            $this->entityManager->persist($transaction);
            $order->setStatus('paid');
            $this->entityManager->flush();

            // Send confirmation
            $this->notifications->sendPaymentConfirmation($order, $charge->id);

            return PaymentResult::success($charge->id);

        } catch (CardException $e) {
            $this->logger->warning('Card declined', [
                'order_id' => $orderId,
                'stripe_error' => $e->getMessage(),
                'stripe_code' => $e->getStripeCode()
            ]);
            return PaymentResult::cardDeclined($e->getMessage());

        } catch (ApiException $e) {
            $this->logger->error('Stripe API error', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
                'http_status' => $e->getHttpStatus()
            ]);
            return PaymentResult::failure('Payment processing error');

        } catch (\Exception $e) {
            $this->logger->critical('Unexpected error processing payment', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return PaymentResult::failure('An unexpected error occurred');
        }
    }

    public function refundPayment(int $transactionId, ?int $amount = null): RefundResult
    {
        $transaction = $this->entityManager->find(PaymentTransaction::class, $transactionId);

        if ($transaction === null) {
            $this->logger->error('Transaction not found for refund', [
                'transaction_id' => $transactionId
            ]);
            return RefundResult::failure('Transaction not found');
        }

        $this->logger->info('Processing refund', [
            'transaction_id' => $transactionId,
            'amount' => $amount ?? $transaction->getAmount()
        ]);

        try {
            Stripe::setApiKey($_ENV['STRIPE_SECRET_KEY']);

            $refundParams = ['charge' => $transaction->getChargeId()];
            if ($amount !== null) {
                $refundParams['amount'] = $amount;
            }

            $refund = \Stripe\Refund::create($refundParams);

            $this->logger->info('Refund successful', [
                'transaction_id' => $transactionId,
                'refund_id' => $refund->id
            ]);

            $transaction->setRefundedAt(new \DateTimeImmutable());
            $transaction->setRefundAmount($refund->amount);
            $this->entityManager->flush();

            return RefundResult::success($refund->id, $refund->amount);

        } catch (\Exception $e) {
            $this->logger->error('Refund failed', [
                'transaction_id' => $transactionId,
                'error' => $e->getMessage()
            ]);
            return RefundResult::failure($e->getMessage());
        }
    }
}
