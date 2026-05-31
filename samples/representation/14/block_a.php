<?php
declare(strict_types=1);

namespace App\Payment\DTO;

final class PaymentRequestDTO
{
    public function __construct(
        public readonly string $paymentId,
        public readonly string $orderId,
        public readonly string $customerId,
        public readonly string $paymentMethodId,
        public readonly string $paymentMethodType,
        public readonly float $amount,
        public readonly string $currency,
        public readonly ?string $returnUrl,
        public readonly ?string $cancelUrl,
        public readonly array $metadata,
        public readonly string $createdAt
    ) {}

    public static function create(
        string $orderId,
        string $customerId,
        string $paymentMethodId,
        float $amount,
        string $currency
    ): self {
        return new self(
            paymentId: 'pay_' . bin2hex(random_bytes(12)),
            orderId: $orderId,
            customerId: $customerId,
            paymentMethodId: $paymentMethodId,
            paymentMethodType: 'card',
            amount: $amount,
            currency: $currency,
            returnUrl: null,
            cancelUrl: null,
            metadata: [],
            createdAt: (new \DateTimeImmutable())->format('c')
        );
    }

    public function withReturnUrl(string $returnUrl): self
    {
        return new self(
            paymentId: $this->paymentId,
            orderId: $this->orderId,
            customerId: $this->customerId,
            paymentMethodId: $this->paymentMethodId,
            paymentMethodType: $this->paymentMethodType,
            amount: $this->amount,
            currency: $this->currency,
            returnUrl: $returnUrl,
            cancelUrl: $this->cancelUrl,
            metadata: $this->metadata,
            createdAt: $this->createdAt
        );
    }

    public function withMetadata(string $key, string $value): self
    {
        $newMetadata = $this->metadata;
        $newMetadata[$key] = $value;

        return new self(
            paymentId: $this->paymentId,
            orderId: $this->orderId,
            customerId: $this->customerId,
            paymentMethodId: $this->paymentMethodId,
            paymentMethodType: $this->paymentMethodType,
            amount: $this->amount,
            currency: $this->currency,
            returnUrl: $this->returnUrl,
            cancelUrl: $this->cancelUrl,
            metadata: $newMetadata,
            createdAt: $this->createdAt
        );
    }

    public function getAmountInCents(): int
    {
        return (int) round($this->amount * 100);
    }

    public function toArray(): array
    {
        return [
            'payment_id' => $this->paymentId,
            'order_id' => $this->orderId,
            'customer_id' => $this->customerId,
            'payment_method_id' => $this->paymentMethodId,
            'payment_method_type' => $this->paymentMethodType,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'return_url' => $this->returnUrl,
            'cancel_url' => $this->cancelUrl,
            'metadata' => $this->metadata,
            'created_at' => $this->createdAt
        ];
    }
}
