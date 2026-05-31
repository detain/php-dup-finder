<?php
declare(strict_types=1);

namespace App\Order\Event;

use Symfony\Component\Uid\Uuid;

final class OrderCreatedEvent
{
    public const EVENT_TYPE = 'order.created';
    public const EVENT_VERSION = '1.0';

    public function __construct(
        public readonly string $eventId,
        public readonly string $eventType,
        public readonly string $eventVersion,
        public readonly string $occurredAt,
        public readonly OrderCreatedPayload $payload
    ) {}

    public static function create(\App\Order\Entity\Order $order): self
    {
        $payload = new OrderCreatedPayload();
        $payload->order_id = $order->getId();
        $payload->order_number = $order->getOrderNumber();
        $payload->customer_id = $order->getCustomerId();
        $payload->customer_email = $order->getCustomer()->getEmail();
        $payload->currency = $order->getCurrency();
        $payload->total_amount = $order->getTotalAmount();
        $payload->item_count = $order->getTotalItemCount();

        foreach ($order->getLineItems() as $item) {
            $lineItem = new OrderLineItemPayload();
            $lineItem->sku = $item->getSku();
            $lineItem->quantity = $item->getQuantity();
            $lineItem->unit_price = $item->getUnitPrice();
            $payload->line_items[] = $lineItem;
        }

        return new self(
            eventId: Uuid::v4()->toRfc4122(),
            eventType: self::EVENT_TYPE,
            eventVersion: self::EVENT_VERSION,
            occurredAt: (new \DateTimeImmutable())->format('c'),
            payload: $payload
        );
    }

    public function toArray(): array
    {
        return [
            'event_id' => $this->eventId,
            'event_type' => $this->eventType,
            'event_version' => $this->eventVersion,
            'occurred_at' => $this->occurredAt,
            'payload' => $this->payload->toArray()
        ];
    }

    public function getAggregateId(): string
    {
        return $this->payload->order_id;
    }

    public function getRoutingKey(): string
    {
        return "order.created.{$this->payload->customer_id}";
    }
}

class OrderCreatedPayload
{
    public string $order_id;
    public string $order_number;
    public string $customer_id;
    public string $customer_email;
    public string $currency;
    public float $total_amount;
    public int $item_count;
    public array $line_items = [];

    public function toArray(): array
    {
        return [
            'order_id' => $this->order_id,
            'order_number' => $this->order_number,
            'customer_id' => $this->customer_id,
            'customer_email' => $this->customer_email,
            'currency' => $this->currency,
            'total_amount' => $this->total_amount,
            'item_count' => $this->item_count,
            'line_items' => array_map(fn($item) => $item->toArray(), $this->line_items)
        ];
    }
}

class OrderLineItemPayload
{
    public string $sku;
    public int $quantity;
    public float $unit_price;

    public function toArray(): array
    {
        return [
            'sku' => $this->sku,
            'quantity' => $this->quantity,
            'unit_price' => $this->unit_price
        ];
    }
}
