<?php
declare(strict_types=1);

namespace WebhookHandler\Processors;

use Psr\Log\LoggerInterface;

final class PaymentWebhookProcessor
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function process(array $payload): ProcessedEvent
    {
        $this->logger->info('Processing payment webhook', [
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
            processor: 'payment',
            data: $this->transformPaymentData($payload['data'] ?? []),
            metadata: $this->extractMetadata($payload),
        );

        $this->logger->info('Payment webhook processed', [
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

        if (isset($payload['data']['amount']) && !is_numeric($payload['data']['amount'])) {
            throw new \InvalidArgumentException('Field data.amount must be numeric');
        }

        if (isset($payload['data']['amount']) && $payload['data']['amount'] < 0) {
            throw new \InvalidArgumentException('Field data.amount cannot be negative');
        }
    }

    private function transformPaymentData(array $data): array
    {
        return [
            'payment_id' => $data['payment_id'] ?? null,
            'order_id' => $data['order_id'] ?? null,
            'customer_id' => $data['customer_id'] ?? null,
            'amount' => isset($data['amount']) ? (float)$data['amount'] : null,
            'currency' => $data['currency'] ?? 'USD',
            'status' => $this->normalizePaymentStatus($data['status'] ?? 'pending'),
            'payment_method' => $this->normalizePaymentMethod($data['payment_method'] ?? null),
            'transaction_id' => $data['transaction_id'] ?? null,
            'authorization_code' => $data['authorization_code'] ?? null,
            'captured_at' => $this->parseTimestamp($data['captured_at'] ?? null),
            'metadata' => $this->normalizeMetadata($data['metadata'] ?? []),
        ];
    }

    private function normalizePaymentStatus(string $status): string
    {
        $validStatuses = ['pending', 'processing', 'succeeded', 'failed', 'cancelled', 'refunded'];
        $normalized = strtolower(trim($status));
        return in_array($normalized, $validStatuses) ? $normalized : 'pending';
    }

    private function normalizePaymentMethod(?string $method): ?string
    {
        if ($method === null) {
            return null;
        }

        $validMethods = ['card', 'bank_transfer', 'wallet', 'cash', 'check'];
        $normalized = strtolower(trim($method));
        return in_array($normalized, $validMethods) ? $normalized : 'card';
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
            'processor' => 'payment',
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
