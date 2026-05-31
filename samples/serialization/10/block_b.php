<?php

declare(strict_types=1);

namespace App\Domain\Event;

class ProductPriceChangedEvent
{
    private string $eventId;
    private string $productId;
    private float $oldPrice;
    private float $newPrice;
    private string $currency;
    private ?string $reason;
    private DateTimeImmutable $occurredAt;
    private int $version = 1;

    public function __construct(
        string $eventId,
        string $productId,
        float $oldPrice,
        float $newPrice,
        string $currency,
        ?string $reason,
        DateTimeImmutable $occurredAt
    ) {
        $this->eventId = $eventId;
        $this->productId = $productId;
        $this->oldPrice = $oldPrice;
        $this->newPrice = $newPrice;
        $this->currency = $currency;
        $this->reason = $reason;
        $this->occurredAt = $occurredAt;
    }

    public function toArray(): array
    {
        return [
            'event_type' => 'product.price_changed',
            'event_id' => $this->eventId,
            'occurred_at' => $this->occurredAt->format('c'),
            'version' => $this->version,
            'payload' => [
                'product_id' => $this->productId,
                'old_price' => $this->oldPrice,
                'new_price' => $this->newPrice,
                'currency' => $this->currency,
                'reason' => $this->reason
            ],
            'metadata' => [
                'timestamp' => $this->occurredAt->getTimestamp(),
                'type' => 'product.price_changed'
            ]
        ];
    }

    public static function fromArray(array $data): self
    {
        $payload = $data['payload'];

        return new self(
            $data['event_id'],
            $payload['product_id'],
            (float)$payload['old_price'],
            (float)$payload['new_price'],
            $payload['currency'],
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
            throw new \InvalidArgumentException('Invalid JSON for ProductPriceChangedEvent');
        }

        return self::fromArray($data);
    }

    public function getEventId(): string
    {
        return $this->eventId;
    }

    public function getProductId(): string
    {
        return $this->productId;
    }

    public function getOldPrice(): float
    {
        return $this->oldPrice;
    }

    public function getNewPrice(): float
    {
        return $this->newPrice;
    }

    public function getCurrency(): string
    {
        return $this->currency;
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
        return 'product.price_changed';
    }
}
