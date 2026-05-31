<?php
declare(strict_types=1);

namespace App\Payment\Model;

use App\Payment\Entity\Payment;

final class PaymentModel
{
    public function __construct(
        public readonly string $id,
        public readonly string $orderId,
        public readonly string $customerId,
        public readonly float $amount,
        public readonly string $currency,
        public readonly string $status,
        public readonly ?string $providerReference = null,
        public readonly ?string $failureReason = null,
        public readonly \DateTimeImmutable $createdAt = new \DateTimeImmutable()
    ) {}

    public static function fromEntity(Payment $payment): self
    {
        return new self(
            id: $payment->getId(),
            orderId: $payment->getOrderId(),
            customerId: $payment->getCustomerId(),
            amount: $payment->getAmount(),
            currency: $payment->getCurrency(),
            status: $payment->getStatus(),
            providerReference: $payment->getProviderReference(),
            failureReason: $payment->getFailureReason(),
            createdAt: $payment->getCreatedAt()
        );
    }

    public function getAmountInCents(): int
    {
        return (int) round($this->amount * 100);
    }

    public function isSuccessful(): bool
    {
        return $this->status === 'succeeded';
    }

    public function isPending(): bool
    {
        return in_array($this->status, ['pending', 'processing'], true);
    }

    public function toRequestArray(): array
    {
        return [
            'payment_id' => $this->id,
            'order_id' => $this->orderId,
            'customer_id' => $this->customerId,
            'amount' => $this->amount,
            'currency' => $this->currency
        ];
    }

    public function toHistoryArray(): array
    {
        return [
            'payment_id' => $this->id,
            'order_id' => $this->orderId,
            'event' => 'payment.' . $this->status,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'status' => $this->status,
            'provider_reference' => $this->providerReference,
            'occurred_at' => $this->createdAt->format('c')
        ];
    }
}
