<?php
declare(strict_types=1);

namespace App\Payment\History;

final class PaymentHistoryRecord
{
    public function __construct(
        public readonly string $transactionId,
        public readonly string $paymentId,
        public readonly string $orderId,
        public readonly string $customerId,
        public readonly string $event,
        public readonly float $amount,
        public readonly string $currency,
        public readonly string $status,
        public readonly ?string $amountRefunded,
        public readonly ?string $providerReference,
        public readonly \DateTimeImmutable $occurredAt,
        public readonly array $context = []
    ) {}

    public static function fromPaymentAndEvent(
        Payment $payment,
        string $event,
        array $context = []
    ): self {
        return new self(
            transactionId: uniqid('txn_'),
            paymentId: $payment->getId(),
            orderId: $payment->getOrderId(),
            customerId: $payment->getCustomerId(),
            event: $event,
            amount: $payment->getAmount(),
            currency: $payment->getCurrency(),
            status: $payment->getStatus(),
            amountRefunded: null,
            providerReference: $payment->getProviderReference(),
            occurredAt: new \DateTimeImmutable(),
            context: $context
        );
    }

    public function isRefund(): bool
    {
        return str_starts_with($this->event, 'refund');
    }

    public function getFormattedAmount(): string
    {
        return number_format($this->amount, 2) . ' ' . $this->currency;
    }

    public function getFormattedRefundedAmount(): string
    {
        if ($this->amountRefunded === null) {
            return 'N/A';
        }
        return number_format((float) $this->amountRefunded, 2) . ' ' . $this->currency;
    }

    public function getEventLabel(): string
    {
        return match ($this->event) {
            'payment.created' => 'Payment Created',
            'payment.processing' => 'Processing Started',
            'payment.succeeded' => 'Payment Successful',
            'payment.failed' => 'Payment Failed',
            'payment.cancelled' => 'Payment Cancelled',
            'payment.refunded' => 'Payment Refunded',
            'payment.refund_initiated' => 'Refund Initiated',
            'payment.refund_completed' => 'Refund Completed',
            default => ucfirst(str_replace('_', ' ', $this->event))
        };
    }

    public function toArray(): array
    {
        return [
            'transaction_id' => $this->transactionId,
            'payment_id' => $this->paymentId,
            'order_id' => $this->orderId,
            'customer_id' => $this->customerId,
            'event' => $this->event,
            'event_label' => $this->getEventLabel(),
            'amount' => $this->amount,
            'currency' => $this->currency,
            'status' => $this->status,
            'amount_refunded' => $this->amountRefunded,
            'provider_reference' => $this->providerReference,
            'occurred_at' => $this->occurredAt->format('c'),
            'context' => $this->context
        ];
    }

    public function toSummary(): array
    {
        return [
            'transaction_id' => $this->transactionId,
            'event' => $this->getEventLabel(),
            'amount' => $this->getFormattedAmount(),
            'status' => $this->status,
            'occurred_at' => $this->occurredAt->format('Y-m-d H:i:s')
        ];
    }
}
