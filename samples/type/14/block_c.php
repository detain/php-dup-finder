<?php
declare(strict_types=1);

namespace WebhookHandler\Processors;

use Psr\Log\LoggerInterface;

final class CustomerWebhookProcessor
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function process(array $payload): ProcessedEvent
    {
        $this->logger->info('Processing customer webhook', [
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
            processor: 'customer',
            data: $this->transformCustomerData($payload['data'] ?? []),
            metadata: $this->extractMetadata($payload),
        );

        $this->logger->info('Customer webhook processed', [
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

        if (isset($payload['data']['email']) && !filter_var($payload['data']['email'], FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Field data.email must be a valid email');
        }

        if (isset($payload['data']['credit_limit']) && (!is_numeric($payload['data']['credit_limit']) || $payload['data']['credit_limit'] < 0)) {
            throw new \InvalidArgumentException('Field data.credit_limit must be a non-negative number');
        }
    }

    private function transformCustomerData(array $data): array
    {
        return [
            'customer_id' => $data['customer_id'] ?? null,
            'email' => $data['email'] ?? null,
            'name' => $data['name'] ?? null,
            'phone' => $data['phone'] ?? null,
            'status' => $this->normalizeCustomerStatus($data['status'] ?? 'active'),
            'credit_limit' => isset($data['credit_limit']) ? (float)$data['credit_limit'] : null,
            'currency' => $data['currency'] ?? 'USD',
            'loyalty_tier' => $data['loyalty_tier'] ?? 'bronze',
            'created_at' => $this->parseTimestamp($data['created_at'] ?? null),
            'updated_at' => $this->parseTimestamp($data['updated_at'] ?? null),
            'deleted_at' => $this->parseTimestamp($data['deleted_at'] ?? null),
            'metadata' => $this->normalizeMetadata($data['metadata'] ?? []),
        ];
    }

    private function normalizeCustomerStatus(string $status): string
    {
        $validStatuses = ['active', 'inactive', 'suspended', 'deleted', 'pending'];
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
            'processor' => 'customer',
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
