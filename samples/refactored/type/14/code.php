<?php
declare(strict_types=1);

namespace WebhookHandler\Shared;

abstract class BaseWebhookProcessor
{
    protected LoggerInterface $logger;

    public function process(array $payload): ProcessedEvent
    {
        $this->logger->info('Processing webhook', [
            'processor' => $this->getProcessorName(),
            'event_type' => $payload['event_type'] ?? 'unknown',
        ]);

        $this->validatePayloadStructure($payload);

        return new ProcessedEvent(
            eventId: $payload['event_id'] ?? $this->generateEventId(),
            eventType: $payload['event_type'],
            occurredAt: $this->parseTimestamp($payload['occurred_at'] ?? null),
            processor: $this->getProcessorName(),
            data: $this->transformData($payload['data'] ?? []),
            metadata: $this->extractMetadata($payload),
        );
    }

    abstract protected function getProcessorName(): string;
    abstract protected function transformData(array $data): array;

    protected function validatePayloadStructure(array $payload): void
    {
        if (!isset($payload['event_type'])) {
            throw new \InvalidArgumentException('Missing required field: event_type');
        }

        if (!isset($payload['data']) || !is_array($payload['data'])) {
            throw new \InvalidArgumentException('Missing or invalid required field: data');
        }
    }

    protected function normalizeStatus(string $status, array $validStatuses): string
    {
        $normalized = strtolower(trim($status));
        return in_array($normalized, $validStatuses) ? $normalized : $validStatuses[0];
    }

    protected function normalizeMetadata(array $metadata): array
    {
        $normalized = [];
        foreach ($metadata as $key => $value) {
            $normalized[$key] = is_scalar($value) ? (string)$value : json_encode($value);
        }
        return $normalized;
    }

    protected function parseTimestamp(?string $timestamp): ?\DateTimeImmutable
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

    protected function extractMetadata(array $payload): array
    {
        return [
            'processor' => $this->getProcessorName(),
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

final class PaymentWebhookProcessor extends BaseWebhookProcessor
{
    protected function getProcessorName(): string
    {
        return 'payment';
    }

    protected function transformData(array $data): array
    {
        return [
            'payment_id' => $data['payment_id'] ?? null,
            'order_id' => $data['order_id'] ?? null,
            'amount' => isset($data['amount']) ? (float)$data['amount'] : null,
            'currency' => $data['currency'] ?? 'USD',
            'status' => $this->normalizeStatus($data['status'] ?? 'pending', ['pending', 'processing', 'succeeded', 'failed', 'cancelled', 'refunded']),
            'transaction_id' => $data['transaction_id'] ?? null,
            'captured_at' => $this->parseTimestamp($data['captured_at'] ?? null),
            'metadata' => $this->normalizeMetadata($data['metadata'] ?? []),
        ];
    }
}
