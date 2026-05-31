<?php
declare(strict_types=1);

namespace WebhookHandler\Processors;

use Psr\Log\LoggerInterface;

final class SubscriptionWebhookProcessor
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function process(array $payload): ProcessedEvent
    {
        $this->logger->info('Processing subscription webhook', [
            'event_type' => $payload['event_type'] ?? 'unknown',
        ]);

        $this->validatePayloadStructure($payload);

        $eventType = $payload['event_type'];
        $eventId = $payload['event_id'] ?? $this->generateEventId();
        $occurredAt = $this->parseTimestamp($payload['occurred_at'] ?? null);

        $processedEvent = new ProcessedEvent(
            eventId: $eventId,
            eventType: $eventType,
            occurredAt: $occurredAt,
            processor: 'subscription',
            data: $this->transformSubscriptionData($payload['data'] ?? []),
            metadata: $this->extractMetadata($payload),
        );

        $this->logger->info('Subscription webhook processed', [
            'event_id' => $eventId,
            'event_type' => $eventType,
        ]);

        return $processedEvent;
    }

    private function validatePayloadStructure(array $payload): void
    {
        if (!isset($payload['event_type'])) {
            throw new \InvalidArgumentException('Missing required field: event_type');
        }

        if (!isset($payload['data']) || !is_array($payload['data'])) {
            throw new \InvalidArgumentException('Missing or invalid required field: data');
        }

        if (isset($payload['data']['plan_id']) && !is_string($payload['data']['plan_id'])) {
            throw new \InvalidArgumentException('Field data.plan_id must be a string');
        }

        if (isset($payload['data']['quantity']) && (!is_int($payload['data']['quantity']) || $payload['data']['quantity'] < 1)) {
            throw new \InvalidArgumentException('Field data.quantity must be a positive integer');
        }
    }

    private function transformSubscriptionData(array $data): array
    {
        return [
            'subscription_id' => $data['subscription_id'] ?? null,
            'customer_id' => $data['customer_id'] ?? null,
            'plan_id' => $data['plan_id'] ?? null,
            'status' => $this->normalizeSubscriptionStatus($data['status'] ?? 'active'),
            'quantity' => isset($data['quantity']) ? (int)$data['quantity'] : 1,
            'current_period_start' => $this->parseTimestamp($data['current_period_start'] ?? null),
            'current_period_end' => $this->parseTimestamp($data['current_period_end'] ?? null),
            'cancel_at_period_end' => (bool)($data['cancel_at_period_end'] ?? false),
            'canceled_at' => $this->parseTimestamp($data['canceled_at'] ?? null),
            'trial_start' => $this->parseTimestamp($data['trial_start'] ?? null),
            'trial_end' => $this->parseTimestamp($data['trial_end'] ?? null),
            'metadata' => $this->normalizeMetadata($data['metadata'] ?? []),
        ];
    }

    private function normalizeSubscriptionStatus(string $status): string
    {
        $validStatuses = ['active', 'past_due', 'canceled', 'trialing', 'unpaid', 'paused'];
        $normalized = strtolower(trim($status));
        return in_array($normalized, $validStatuses) ? $normalized : 'active';
    }

    private function normalizeMetadata(array $metadata): array
    {
        $normalized = [];
        foreach ($metadata as $key => $value) {
            if (is_scalar($value)) {
                $normalized[$key] = (string)$value;
            } else {
                $normalized[$key] = json_encode($value);
            }
        }
        return $normalized;
    }

    private function parseTimestamp(?string $timestamp): ?\DateTimeImmutable
    {
        if ($timestamp === null) {
            return null;
        }

        try {
            return new \DateTimeImmutable($timestamp);
        } catch (\Exception $e) {
            return null;
        }
    }

    private function extractMetadata(array $payload): array
    {
        return [
            'processor' => 'subscription',
            'received_at' => date('c'),
            'api_version' => $payload['api_version'] ?? 'v1',
            'raw_event_type' => $payload['event_type'] ?? null,
        ];
    }

    private function generateEventId(): string
    {
        return 'evt_' . bin2hex(random_bytes(16));
    }
}
