<?php
declare(strict_types=1);

namespace App\Payment;

final class PaymentSucceeded
{
    public function __construct(
        public readonly string $paymentId,
        public readonly int $amountCents,
        public readonly string $currencyIso,
        public readonly ?string $customerRef,
        public readonly array $metadata,
        public readonly \DateTimeImmutable $occurredAt,
        public readonly ?string $chargeRef,
    ) {
        if ($paymentId === '' || $amountCents <= 0 || strlen($currencyIso) !== 3) {
            throw new \InvalidArgumentException('Invalid payment fields');
        }
    }

    public static function fromStripe(array $event): self
    {
        if (($event['type'] ?? '') !== 'payment_intent.succeeded') {
            throw new \InvalidArgumentException('Wrong event type');
        }
        $obj = $event['data']['object'] ?? [];
        return new self(
            (string)$obj['id'],
            (int)($obj['amount_received'] ?? 0),
            strtoupper((string)($obj['currency'] ?? 'usd')),
            isset($obj['customer']) ? (string)$obj['customer'] : null,
            is_array($obj['metadata'] ?? null) ? $obj['metadata'] : [],
            (new \DateTimeImmutable())->setTimestamp((int)($obj['created'] ?? time())),
            isset($obj['latest_charge']) ? (string)$obj['latest_charge'] : null,
        );
    }

    public function idempotencyKey(): string
    {
        return hash('sha256', $this->paymentId . '|' . $this->amountCents);
    }

    public function toQueuePayload(): array
    {
        return [
            'payment_ref' => $this->paymentId,
            'amount' => $this->amountCents,
            'currency' => $this->currencyIso,
            'customer' => $this->customerRef,
            'metadata' => $this->metadata,
            'occurred_at' => $this->occurredAt->format(\DateTimeInterface::ATOM),
            'idempotency_key' => $this->idempotencyKey(),
        ];
    }
}
