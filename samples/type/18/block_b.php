<?php
declare(strict_types=1);

namespace EventBus\Handlers;

use Psr\Log\LoggerInterface;

final class PaymentReceivedEventHandler
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function handle(array $eventData): HandlerResult
    {
        $this->logger->info('Handling payment received event', [
            'payment_id' => $eventData['payment_id'] ?? 'unknown',
        ]);

        $this->validateEventData($eventData);

        $transformedEvent = $this->transformEvent($eventData);

        $this->dispatchToSubscribers($transformedEvent);
        $this->updateReadModels($transformedEvent);
        $this->triggerWebhooks($transformedEvent);

        return new HandlerResult(
            success: true,
            eventType: 'payment.received',
            processedAt: new \DateTimeImmutable(),
            metadata: ['payment_id' => $transformedEvent['payment_id']]
        );
    }

    private function validateEventData(array $eventData): void
    {
        if (!isset($eventData['payment_id'])) {
            throw new \InvalidArgumentException('Missing required field: payment_id');
        }

        if (!isset($eventData['order_id'])) {
            throw new \InvalidArgumentException('Missing required field: order_id');
        }

        if (!isset($eventData['amount'])) {
            throw new \InvalidArgumentException('Missing required field: amount');
        }

        if (!is_numeric($eventData['amount'])) {
            throw new \InvalidArgumentException('Field amount must be numeric');
        }

        if (isset($eventData['transactions']) && !is_array($eventData['transactions'])) {
            throw new \InvalidArgumentException('Field transactions must be an array');
        }
    }

    private function transformEvent(array $eventData): array
    {
        return [
            'event_id' => $eventData['event_id'] ?? $this->generateEventId(),
            'event_type' => 'payment.received',
            'payment_id' => $eventData['payment_id'],
            'order_id' => $eventData['order_id'],
            'amount' => (float)$eventData['amount'],
            'currency' => $eventData['currency'] ?? 'USD',
            'transactions' => $this->transformTransactions($eventData['transactions'] ?? []),
            'payment_method' => $eventData['payment_method'] ?? 'card',
            'occurred_at' => $this->parseTimestamp($eventData['occurred_at'] ?? null),
            'metadata' => $this->extractMetadata($eventData),
            'version' => $eventData['version'] ?? '1.0',
        ];
    }

    private function transformTransactions(array $transactions): array
    {
        return array_map(function ($txn) {
            return [
                'transaction_id' => $txn['transaction_id'] ?? null,
                'type' => $txn['type'] ?? 'charge',
                'status' => $txn['status'] ?? 'pending',
                'amount' => (float)($txn['amount'] ?? 0),
            ];
        }, $transactions);
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
            'payment_id' => $event['payment_id'],
        ]);
    }

    private function triggerWebhooks(array $event): void
    {
        $this->logger->debug('Triggering webhooks', [
            'payment_id' => $event['payment_id'],
        ]);
    }

    private function generateEventId(): string
    {
        return 'evt_' . bin2hex(random_bytes(16));
    }
}
