<?php
declare(strict_types=1);

namespace Acme\Events;

final class EventEnvelopeFactory
{
    public function __construct(private readonly TraceContext $trace)
    {
    }

    public function build(string $type, array $data, string $version = '1.0'): array
    {
        return [
            'event_id' => bin2hex(random_bytes(16)),
            'event_type' => $type,
            'event_version' => $version,
            'occurred_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::RFC3339_EXTENDED),
            'producer' => 'acme-checkout',
            'trace' => [
                'trace_id' => $this->trace->traceId(),
                'span_id' => $this->trace->newSpanId(),
                'parent_span_id' => $this->trace->currentSpanId(),
            ],
            'data' => $data,
            'meta' => [
                'schema' => 'https://schemas.acme.io/events/v1',
                'environment' => getenv('APP_ENV') ?: 'production',
            ],
        ];
    }
}

final class OrderPlacedPublisher
{
    public function __construct(
        private readonly MessageBus $bus,
        private readonly EventEnvelopeFactory $envelopes,
    ) {
    }

    public function publish(int $orderId, string $customerEmail, float $total): void
    {
        if ($orderId <= 0) {
            throw new \InvalidArgumentException("orderId must be positive");
        }
        $this->bus->dispatch('orders.events', $this->envelopes->build('order.placed', [
            'order_id' => $orderId,
            'customer_email' => $customerEmail,
            'total' => $total,
        ]));
    }
}
