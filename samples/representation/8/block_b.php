<?php
declare(strict_types=1);

namespace App\Events;

final class PaymentSucceededEvent
{
    public function __construct(
        public readonly string $paymentId,
        public readonly int $amountCents,
        public readonly string $currencyCode,
        public readonly ?string $customerRef,
        public readonly array $metadata,
        public readonly \DateTimeImmutable $occurredAt,
        public readonly ?string $chargeRef,
    ) {
        if ($paymentId === '') {
            throw new \InvalidArgumentException('Payment id required');
        }
        if ($amountCents <= 0) {
            throw new \InvalidArgumentException('Amount must be positive');
        }
        if (strlen($currencyCode) !== 3) {
            throw new \InvalidArgumentException('Currency must be ISO-4217');
        }
    }

    public static function fromStripe(array $stripeEvent): self
    {
        $obj = $stripeEvent['data']['object'];
        return new self(
            paymentId: (string)$obj['id'],
            amountCents: (int)$obj['amount_received'],
            currencyCode: strtoupper((string)$obj['currency']),
            customerRef: isset($obj['customer']) ? (string)$obj['customer'] : null,
            metadata: is_array($obj['metadata'] ?? null) ? $obj['metadata'] : [],
            occurredAt: (new \DateTimeImmutable())->setTimestamp((int)$obj['created']),
            chargeRef: isset($obj['latest_charge']) ? (string)$obj['latest_charge'] : null,
        );
    }
}

final class EventBus
{
    public function publish(PaymentSucceededEvent $event): void
    {
        // dispatch to subscribers
    }
}
