<?php
declare(strict_types=1);

namespace EventBus\Handlers;

use Psr\Log\LoggerInterface;

final class CustomerRegisteredEventHandler
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function handle(array $eventData): HandlerResult
    {
        $this->logger->info('Handling customer registered event', [
            'customer_id' => $eventData['customer_id'] ?? 'unknown',
        ]);

        $this->validateEventData($eventData);

        $transformedEvent = $this->transformEvent($eventData);

        $this->dispatchToSubscribers($transformedEvent);
        $this->updateReadModels($transformedEvent);
        $this->triggerWebhooks($transformedEvent);

        return new HandlerResult(
            success: true,
            eventType: 'customer.registered',
            processedAt: new \DateTimeImmutable(),
            metadata: ['customer_id' => $transformedEvent['customer_id']]
        );
    }

    private function validateEventData(array $eventData): void
    {
        if (!isset($eventData['customer_id'])) {
            throw new \InvalidArgumentException('Missing required field: customer_id');
        }

        if (!isset($eventData['email'])) {
            throw new \InvalidArgumentException('Missing required field: email');
        }

        if (!isset($eventData['name'])) {
            throw new \InvalidArgumentException('Missing required field: name');
        }

        if (isset($eventData['email']) && !filter_var($eventData['email'], FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Field email must be a valid email');
        }

        if (isset($eventData['addresses']) && !is_array($eventData['addresses'])) {
            throw new \InvalidArgumentException('Field addresses must be an array');
        }
    }

    private function transformEvent(array $eventData): array
    {
        return [
            'event_id' => $eventData['event_id'] ?? $this->generateEventId(),
            'event_type' => 'customer.registered',
            'customer_id' => $eventData['customer_id'],
            'email' => strtolower(trim($eventData['email'])),
            'name' => trim($eventData['name']),
            'phone' => $eventData['phone'] ?? null,
            'addresses' => $this->transformAddresses($eventData['addresses'] ?? []),
            'loyalty_tier' => $eventData['loyalty_tier'] ?? 'bronze',
            'occurred_at' => $this->parseTimestamp($eventData['occurred_at'] ?? null),
            'metadata' => $this->extractMetadata($eventData),
            'version' => $eventData['version'] ?? '1.0',
        ];
    }

    private function transformAddresses(array $addresses): array
    {
        return array_map(function ($addr) {
            return [
                'type' => $addr['type'] ?? 'shipping',
                'street' => trim($addr['street'] ?? ''),
                'city' => trim($addr['city'] ?? ''),
                'state' => trim($addr['state'] ?? ''),
                'postal_code' => trim($addr['postal_code'] ?? ''),
                'country' => trim($addr['country'] ?? ''),
            ];
        }, $addresses);
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
            'customer_id' => $event['customer_id'],
        ]);
    }

    private function triggerWebhooks(array $event): void
    {
        $this->logger->debug('Triggering webhooks', [
            'customer_id' => $event['customer_id'],
        ]);
    }

    private function generateEventId(): string
    {
        return 'evt_' . bin2hex(random_bytes(16));
    }
}
