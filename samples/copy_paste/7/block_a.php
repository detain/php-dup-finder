<?php
declare(strict_types=1);

namespace Acme\Events\Orders;

final class OrderPlacedPublisher
{
    public function __construct(private readonly MessageBus $bus, private readonly TraceContext $trace)
    {
    }

    public function publish(int $orderId, string $customerEmail, float $total): void
    {
        if ($orderId <= 0) {
            throw new \InvalidArgumentException("orderId must be positive");
        }

        $data = [
            'order_id' => $orderId,
            'customer_email' => $customerEmail,
            'total' => $total,
        ];

        // ---- BEGIN copy-pasted event envelope builder ----
        $envelope = [
            'event_id' => bin2hex(random_bytes(16)),
            'event_type' => 'order.placed',
            'event_version' => '1.0',
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
        // ---- END copy-pasted event envelope builder ----

        $this->bus->dispatch('orders.events', $envelope);
    }
}
