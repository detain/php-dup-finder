<?php

declare(strict_types=1);

namespace App\Domain\Event;

interface DomainEventInterface
{
    public function toArray(): array;
    public static function fromArray(array $data): self;
    public function toJson(): string;
    public static function fromJson(string $json): self;
    public function getEventId(): string;
    public function getEventType(): string;
    public function getOccurredAt(): DateTimeImmutable;
    public function getVersion(): int;
}

abstract class AbstractDomainEvent implements DomainEventInterface
{
    protected string $eventId;
    protected DateTimeImmutable $occurredAt;
    protected int $version = 1;

    public function __construct(string $eventId, DateTimeImmutable $occurredAt)
    {
        $this->eventId = $eventId;
        $this->occurredAt = $occurredAt;
    }

    public function toJson(): string
    {
        return json_encode($this->toArray());
    }

    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true);

        if ($data === null) {
            throw new \InvalidArgumentException('Invalid JSON for ' . static::class);
        }

        return static::fromArray($data);
    }

    public function getEventId(): string
    {
        return $this->eventId;
    }

    public function getOccurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    protected function baseToArray(string $eventType): array
    {
        return [
            'event_type' => $eventType,
            'event_id' => $this->eventId,
            'occurred_at' => $this->occurredAt->format('c'),
            'version' => $this->version,
            'metadata' => [
                'timestamp' => $this->occurredAt->getTimestamp(),
                'type' => $eventType
            ]
        ];
    }

    abstract protected function getEventType(): string;
    abstract protected function getPayload(): array;
    abstract protected static function fromPayload(array $payload, string $eventId, DateTimeImmutable $occurredAt): self;

    public function toArray(): array
    {
        $base = $this->baseToArray($this->getEventType());
        $base['payload'] = $this->getPayload();
        return $base;
    }

    public static function fromArray(array $data): self
    {
        $payload = $data['payload'];
        $eventId = $data['event_id'];
        $occurredAt = new DateTimeImmutable($data['occurred_at']);

        return static::fromPayload($payload, $eventId, $occurredAt);
    }
}

class UserCreatedEvent extends AbstractDomainEvent
{
    private string $userId;
    private string $email;
    private string $name;
    private array $roles;

    public function __construct(
        string $eventId,
        string $userId,
        string $email,
        string $name,
        array $roles,
        DateTimeImmutable $occurredAt
    ) {
        parent::__construct($eventId, $occurredAt);
        $this->userId = $userId;
        $this->email = $email;
        $this->name = $name;
        $this->roles = $roles;
    }

    protected function getEventType(): string
    {
        return 'user.created';
    }

    protected function getPayload(): array
    {
        return [
            'user_id' => $this->userId,
            'email' => $this->email,
            'name' => $this->name,
            'roles' => $this->roles
        ];
    }

    protected static function fromPayload(array $payload, string $eventId, DateTimeImmutable $occurredAt): self
    {
        return new self(
            $eventId,
            $payload['user_id'],
            $payload['email'],
            $payload['name'],
            $payload['roles'],
            $occurredAt
        );
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getRoles(): array
    {
        return $this->roles;
    }
}

class ProductPriceChangedEvent extends AbstractDomainEvent
{
    private string $productId;
    private float $oldPrice;
    private float $newPrice;
    private string $currency;
    private ?string $reason;

    public function __construct(
        string $eventId,
        string $productId,
        float $oldPrice,
        float $newPrice,
        string $currency,
        ?string $reason,
        DateTimeImmutable $occurredAt
    ) {
        parent::__construct($eventId, $occurredAt);
        $this->productId = $productId;
        $this->oldPrice = $oldPrice;
        $this->newPrice = $newPrice;
        $this->currency = $currency;
        $this->reason = $reason;
    }

    protected function getEventType(): string
    {
        return 'product.price_changed';
    }

    protected function getPayload(): array
    {
        return [
            'product_id' => $this->productId,
            'old_price' => $this->oldPrice,
            'new_price' => $this->newPrice,
            'currency' => $this->currency,
            'reason' => $this->reason
        ];
    }

    protected static function fromPayload(array $payload, string $eventId, DateTimeImmutable $occurredAt): self
    {
        return new self(
            $eventId,
            $payload['product_id'],
            (float)$payload['old_price'],
            (float)$payload['new_price'],
            $payload['currency'],
            $payload['reason'] ?? null,
            $occurredAt
        );
    }
}

class OrderStatusChangedEvent extends AbstractDomainEvent
{
    private string $orderId;
    private string $oldStatus;
    private string $newStatus;
    private ?string $changedBy;
    private ?string $reason;

    public function __construct(
        string $eventId,
        string $orderId,
        string $oldStatus,
        string $newStatus,
        ?string $changedBy,
        ?string $reason,
        DateTimeImmutable $occurredAt
    ) {
        parent::__construct($eventId, $occurredAt);
        $this->orderId = $orderId;
        $this->oldStatus = $oldStatus;
        $this->newStatus = $newStatus;
        $this->changedBy = $changedBy;
        $this->reason = $reason;
    }

    protected function getEventType(): string
    {
        return 'order.status_changed';
    }

    protected function getPayload(): array
    {
        return [
            'order_id' => $this->orderId,
            'old_status' => $this->oldStatus,
            'new_status' => $this->newStatus,
            'changed_by' => $this->changedBy,
            'reason' => $this->reason
        ];
    }

    protected static function fromPayload(array $payload, string $eventId, DateTimeImmutable $occurredAt): self
    {
        return new self(
            $eventId,
            $payload['order_id'],
            $payload['old_status'],
            $payload['new_status'],
            $payload['changed_by'] ?? null,
            $payload['reason'] ?? null,
            $occurredAt
        );
    }
}

class EventSerializer
{
    private array $eventClasses = [];

    public function register(string $eventType, string $class): void
    {
        $this->eventClasses[$eventType] = $class;
    }

    public function serialize(DomainEventInterface $event): string
    {
        return $event->toJson();
    }

    public function deserialize(string $json): DomainEventInterface
    {
        $data = json_decode($json, true);

        if ($data === null || !isset($data['event_type'])) {
            throw new \InvalidArgumentException('Invalid event JSON');
        }

        $class = $this->eventClasses[$data['event_type']] ?? null;

        if ($class === null) {
            throw new \InvalidArgumentException("No class registered for event type: {$data['event_type']}");
        }

        return $class::fromJson($json);
    }
}
