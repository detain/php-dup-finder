<?php
declare(strict_types=1);

namespace EventBus\Handlers;

use Psr\Log\LoggerInterface;

final class OrderCreatedEventHandler
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function handle(array $eventData): HandlerResult
    {
        $this->logger->info('Handling order created event', [
            'order_id' => $eventData['order_id'] ?? 'unknown',
        ]);

        $this->validateEventData($eventData);

        $transformedEvent = $this->transformEvent($eventData);

        $this->dispatchToSubscribers($transformedEvent);
        $this->updateReadModels($transformedEvent);
        $this->triggerWebhooks($transformedEvent);

        return new HandlerResult(
            success: true,
            eventType: 'order.created',
            processedAt: new \DateTimeImmutable(),
            metadata: ['order_id' => $transformedEvent['order_id']]
        );
    }

    private function validateEventData(array $eventData): void
    {
        if (!isset($eventData['order_id'])) {
            throw new \InvalidArgumentException('Missing required field: order_id');
        }

        if (!isset($eventData['customer_id'])) {
            throw new \InvalidArgumentException('Missing required field: customer_id');
        }

        if (!isset($eventData['total_amount'])) {
            throw new \InvalidArgumentException('Missing required field: total_amount');
        }

        if (!is_numeric($eventData['total_amount'])) {
            throw new \InvalidArgumentException('Field total_amount must be numeric');
        }

        if (isset($eventData['items']) && !is_array($eventData['items'])) {
            throw new \InvalidArgumentException('Field items must be an array');
        }
    }

    private function transformEvent(array $eventData): array
    {
        return [
            'event_id' => $eventData['event_id'] ?? $this->generateEventId(),
            'event_type' => 'order.created',
            'order_id' => $eventData['order_id'],
            'customer_id' => $eventData['customer_id'],
            'total_amount' => (float)$eventData['total_amount'],
            'currency' => $eventData['currency'] ?? 'USD',
            'items' => $this->transformItems($eventData['items'] ?? []),
            'shipping_address' => $this->transformAddress($eventData['shipping_address'] ?? []),
            'occurred_at' => $this->parseTimestamp($eventData['occurred_at'] ?? null),
            'metadata' => $this->extractMetadata($eventData),
            'version' => $eventData['version'] ?? '1.0',
        ];
    }

    private function transformItems(array $items): array
    {
        return array_map(function ($item) {
            return [
                'product_id' => $item['product_id'] ?? null,
                'sku' => $item['sku'] ?? null,
                'quantity' => (int)($item['quantity'] ?? 1),
                'unit_price' => (float)($item['unit_price'] ?? 0),
            ];
        }, $items);
    }

    private function transformAddress(array $address): array
    {
        return [
            'street' => trim($address['street'] ?? ''),
            'city' => trim($address['city'] ?? ''),
            'state' => trim($address['state'] ?? ''),
            'postal_code' => trim($address['postal_code'] ?? ''),
            'country' => trim($address['country'] ?? ''),
        ];
    }

    private function extractMetadata(array $eventData): array
    {
        return [
            'source' => $eventData['source'] ?? 'unknown',
            'correlation_id' => $eventData['correlation_id'] ?? null,
            'causation_id' => $eventData['causation_id'] ?? null,
            'processed_at' => date('c'),
        ];
    }

    private function parseTimestamp(?string $timestamp): \DateTimeImmutable
    {
        if ($timestamp === null) {
            return new \DateTimeImmutable();
        }

        try {
            return new \DateTimeImmutable($timestamp);
        } catch (\Exception $e) {
            return new \DateTimeImmutable();
        }
    }

    private function dispatchToSubscribers(array $event): void
    {
        $this->logger->debug('Dispatching to subscribers', [
            'event_type' => $event['event_type'],
        ]);
    }

    private function updateReadModels(array $event): void
    {
        $this->logger->debug('Updating read models', [
            'order_id' => $event['order_id'],
        ]);
    }

    private function triggerWebhooks(array $event): void
    {
        $this->logger->debug('Triggering webhooks', [
            'order_id' => $event['order_id'],
        ]);
    }

    private function generateEventId(): string
    {
        return 'evt_' . bin2hex(random_bytes(16));
    }
}
