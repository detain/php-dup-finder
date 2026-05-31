<?php
declare(strict_types=1);

namespace EventBus\Shared;

interface EventHandlerInterface
{
    public function handle(array $eventData): HandlerResult;
    public function getEventType(): string;
}

abstract class BaseEventHandler implements EventHandlerInterface
{
    protected LoggerInterface $logger;

    public function handle(array $eventData): HandlerResult
    {
        $this->logger->info('Handling event', [
            'event_type' => $this->getEventType(),
            'entity_id' => $this->getEntityId($eventData),
        ]);

        $this->validateEventData($eventData);
        $transformedEvent = $this->transformEvent($eventData);

        $this->dispatchToSubscribers($transformedEvent);
        $this->updateReadModels($transformedEvent);
        $this->triggerWebhooks($transformedEvent);

        return new HandlerResult(
            success: true,
            eventType: $this->getEventType(),
            processedAt: new \DateTimeImmutable(),
            metadata: $this->getMetadata($transformedEvent)
        );
    }

    abstract protected function getEventType(): string;
    abstract protected function getEntityId(array $eventData): ?string;
    abstract protected function validateEventData(array $eventData): void;
    abstract protected function transformEvent(array $eventData): array;

    protected function getMetadata(array $event): array
    {
        return [$this->getEventType() . '_id' => $event[$this->getEventType() . '_id'] ?? null];
    }

    protected function transformAddress(array $address): array
    {
        return [
            'street' => trim($address['street'] ?? ''),
            'city' => trim($address['city'] ?? ''),
            'state' => trim($address['state'] ?? ''),
            'postal_code' => trim($address['postal_code'] ?? ''),
            'country' => trim($address['country'] ?? ''),
        ];
    }

    protected function extractCommonMetadata(array $eventData): array
    {
        return [
            'source' => $eventData['source'] ?? 'unknown',
            'correlation_id' => $eventData['correlation_id'] ?? null,
            'causation_id' => $eventData['causation_id'] ?? null,
            'processed_at' => date('c'),
        ];
    }

    protected function parseTimestamp(?string $timestamp): \DateTimeImmutable
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

    protected function dispatchToSubscribers(array $event): void
    {
        $this->logger->debug('Dispatching to subscribers', ['event_type' => $event['event_type']]);
    }

    protected function updateReadModels(array $event): void
    {
        $this->logger->debug('Updating read models', ['entity_id' => $this->getEntityId($event)]);
    }

    protected function triggerWebhooks(array $event): void
    {
        $this->logger->debug('Triggering webhooks', ['entity_id' => $this->getEntityId($event)]);
    }

    protected function generateEventId(): string
    {
        return 'evt_' . bin2hex(random_bytes(16));
    }
}

final class OrderCreatedEventHandler extends BaseEventHandler
{
    protected function getEventType(): string
    {
        return 'order.created';
    }

    protected function getEntityId(array $eventData): ?string
    {
        return $eventData['order_id'] ?? null;
    }

    protected function validateEventData(array $eventData): void
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
    }

    protected function transformEvent(array $eventData): array
    {
        return [
            'event_id' => $eventData['event_id'] ?? $this->generateEventId(),
            'event_type' => $this->getEventType(),
            'order_id' => $eventData['order_id'],
            'customer_id' => $eventData['customer_id'],
            'total_amount' => (float)$eventData['total_amount'],
            'currency' => $eventData['currency'] ?? 'USD',
            'items' => $eventData['items'] ?? [],
            'shipping_address' => $this->transformAddress($eventData['shipping_address'] ?? []),
            'occurred_at' => $this->parseTimestamp($eventData['occurred_at'] ?? null),
            'metadata' => $this->extractCommonMetadata($eventData),
            'version' => $eventData['version'] ?? '1.0',
        ];
    }
}
