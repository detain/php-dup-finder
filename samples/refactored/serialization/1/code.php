<?php

declare(strict_types=1);

namespace App\Serialization;

interface SerializationStrategy
{
    public function serialize(Entity $entity): array;
}

class DefaultSerializationStrategy implements SerializationStrategy
{
    public function serialize(Entity $entity): array
    {
        return array_merge(
            $entity->toArray(),
            [
                'meta' => [
                    'type' => $entity->getType(),
                    'serialized_at' => (new DateTimeImmutable())->format('c')
                ]
            ]
        );
    }
}

class CompactSerializationStrategy implements SerializationStrategy
{
    public function serialize(Entity $entity): array
    {
        return $entity->toCompactArray();
    }
}

class SummarySerializationStrategy implements SerializationStrategy
{
    public function serialize(Entity $entity): array
    {
        return $entity->toSummaryArray();
    }
}

class PublicSerializationStrategy implements SerializationStrategy
{
    public function serialize(Entity $entity): array
    {
        return $entity->toPublicArray();
    }
}

trait SerializationTrait
{
    abstract public function toArray(): array;
    abstract public function toCompactArray(): array;
    abstract public function toSummaryArray(): array;
    abstract public function toPublicArray(): array;
    abstract public function getType(): string;

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}

abstract class Entity
{
    use SerializationTrait;

    private string $id;
    private DateTimeImmutable $createdAt;
    private ?DateTimeImmutable $updatedAt;

    public function __construct(string $id, DateTimeImmutable $createdAt, ?DateTimeImmutable $updatedAt)
    {
        $this->id = $id;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    protected function baseToArray(): array
    {
        return [
            'id' => $this->id,
            'created_at' => $this->createdAt->format('c'),
            'updated_at' => $this->updatedAt?->format('c')
        ];
    }
}

class SerializationContext
{
    private SerializationStrategy $strategy;

    public function __construct(SerializationStrategy $strategy)
    {
        $this->strategy = $strategy;
    }

    public function serialize(Entity $entity): array
    {
        return $this->strategy->serialize($entity);
    }

    public function serializeMany(array $entities): array
    {
        return array_map(
            fn(Entity $entity) => $this->serialize($entity),
            $entities
        );
    }
}
