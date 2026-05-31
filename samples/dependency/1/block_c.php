<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use Psr\Log\LoggerInterface;
use App\Domain\Payment\Entity\PaymentTransaction;
use App\Domain\Payment\Repository\PaymentRepositoryInterface;
use App\Domain\Payment\Processor\PaymentProcessorInterface;
use App\Domain\Payment\Event\PaymentProcessedEvent;
use App\Infrastructure\Messaging\EventDispatcher;

/**
 * Payment processing service handling payment transactions.
 * The LoggerInterface is manually injected here, duplicated from
 * OrderService, UserService, and other services.
 */
class PaymentService
{
    private LoggerInterface $logger;
    private PaymentRepositoryInterface $paymentRepository;
    private PaymentProcessorInterface $paymentProcessor;
    private EventDispatcher $eventDispatcher;

    public function __construct(
        PaymentRepositoryInterface $paymentRepository,
        PaymentProcessorInterface $paymentProcessor,
        EventDispatcher $eventDispatcher,
        LoggerInterface $logger
    ) {
        $this->paymentRepository = $paymentRepository;
        $this->paymentProcessor = $paymentProcessor;
        $this->eventDispatcher = $eventDispatcher;
        $this->logger = $logger;
    }

    public function processPayment(array $paymentData): PaymentTransaction
    {
        $this->logger->info('Processing payment', [
            'order_id' => $paymentData['order_id'],
            'amount' => $paymentData['amount'],
            'currency' => $paymentData['currency'] ?? 'USD',
        ]);

        try {
            $transaction = new PaymentTransaction(
                orderId: $paymentData['order_id'],
                amount: $paymentData['amount'],
                currency: $paymentData['currency'] ?? 'USD',
                paymentMethod: $paymentData['payment_method'],
            );

            $result = $this->paymentProcessor->charge($transaction);

            if (!$result->isSuccessful()) {
                $this->logger->warning('Payment declined', [
                    'order_id' => $paymentData['order_id'],
                    'decline_code' => $result->getDeclineCode(),
                    'decline_message' => $result->getDeclineMessage(),
                ]);

                throw new PaymentDeclinedException(
                    $result->getDeclineMessage(),
                    $result->getDeclineCode()
                );
            }

            $transaction->markSuccessful($result->getGatewayTransactionId());
            $savedTransaction = $this->paymentRepository->save($transaction);

            $this->eventDispatcher->dispatch(
                new PaymentProcessedEvent($savedTransaction)
            );

            $this->logger->info('Payment processed successfully', [
                'transaction_id' => $savedTransaction->getId()->toString(),
                'order_id' => $paymentData['order_id'],
            ]);

            return $savedTransaction;

        } catch (\Exception $e) {
            $this->logger->error('Payment processing failed', [
                'order_id' => $paymentData['order_id'],
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    public function refundPayment(string $transactionId, float $amount): PaymentTransaction
    {
        $this->logger->info('Processing payment refund', [
            'transaction_id' => $transactionId,
            'refund_amount' => $amount,
        ]);

        $transaction = $this->paymentRepository->findById($transactionId);

        if (!$transaction->isRefundable()) {
            throw new TransactionNotRefundableException(
                'This transaction is not eligible for refund'
            );
        }

        if ($amount > $transaction->getAmount()) {
            throw new RefundAmountExceedsTransactionException(
                'Refund amount exceeds original transaction amount'
            );
        }

        $result = $this->paymentProcessor->refund($transaction, $amount);

        if (!$result->isSuccessful()) {
            $this->logger->error('Refund failed', [
                'transaction_id' => $transactionId,
                'error' => $result->getErrorMessage(),
            ]);

            throw new RefundFailedException($result->getErrorMessage());
        }

        $transaction->addRefund($amount, $result->getGatewayRefundId());
        $savedTransaction = $this->paymentRepository->save($transaction);

        $this->logger->info('Refund processed successfully', [
            'transaction_id' => $transactionId,
            'refund_amount' => $amount,
        ]);

        return $savedTransaction;
    }
}
