<?php

declare(strict_types=1);

namespace App\Domain\Event;

class OrderStatusChangedEvent
{
    private string $eventId;
    private string $orderId;
    private string $oldStatus;
    private string $newStatus;
    private ?string $changedBy;
    private ?string $reason;
    private DateTimeImmutable $occurredAt;
    private int $version = 1;

    public function __construct(
        string $eventId,
        string $orderId,
        string $oldStatus,
        string $newStatus,
        ?string $changedBy,
        ?string $reason,
        DateTimeImmutable $occurredAt
    ) {
        $this->eventId = $eventId;
        $this->orderId = $orderId;
        $this->oldStatus = $oldStatus;
        $this->newStatus = $newStatus;
        $this->changedBy = $changedBy;
        $this->reason = $reason;
        $this->occurredAt = $occurredAt;
    }

    public function toArray(): array
    {
        return [
            'event_type' => 'order.status_changed',
            'event_id' => $this->eventId,
            'occurred_at' => $this->occurredAt->format('c'),
            'version' => $this->version,
            'payload' => [
                'order_id' => $this->orderId,
                'old_status' => $this->oldStatus,
                'new_status' => $this->newStatus,
                'changed_by' => $this->changedBy,
                'reason' => $this->reason
            ],
            'metadata' => [
                'timestamp' => $this->occurredAt->getTimestamp(),
                'type' => 'order.status_changed'
            ]
        ];
    }

    public static function fromArray(array $data): self
    {
        $payload = $data['payload'];

        return new self(
            $data['event_id'],
            $payload['order_id'],
            $payload['old_status'],
            $payload['new_status'],
            $payload['changed_by'] ?? null,
            $payload['reason'] ?? null,
            new DateTimeImmutable($data['occurred_at'])
        );
    }

    public function toJson(): string
    {
        return json_encode($this->toArray());
    }

    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true);

        if ($data === null) {
            throw new \InvalidArgumentException('Invalid JSON for OrderStatusChangedEvent');
        }

        return self::fromArray($data);
    }

    public function getEventId(): string
    {
        return $this->eventId;
    }

    public function getOrderId(): string
    {
        return $this->orderId;
    }

    public function getOldStatus(): string
    {
        return $this->oldStatus;
    }

    public function getNewStatus(): string
    {
        return $this->newStatus;
    }

    public function getChangedBy(): ?string
    {
        return $this->changedBy;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function getOccurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getEventType(): string
    {
        return 'order.status_changed';
    }
}
