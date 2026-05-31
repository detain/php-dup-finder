<?php

declare(strict_types=1);

namespace App\Dto;

interface ArrayConverterInterface
{
    public function fromEntity(mixed $entity): array;
    public function toEntity(array $data): mixed;
    public function fromEntityCompact(mixed $entity): array;
    public function fromEntitySummary(mixed $entity): array;
    public function fromEntities(array $entities): array;
}

abstract class AbstractArrayConverter implements ArrayConverterInterface
{
    abstract protected function getEntityClass(): string;

    public function fromEntities(array $entities): array
    {
        return array_map(fn($entity) => $this->fromEntity($entity), $entities);
    }

    protected function formatDateTime(\DateTimeInterface $dateTime): string
    {
        return $dateTime->format('Y-m-d H:i:s');
    }

    protected function formatNullableDateTime(?\DateTimeInterface $dateTime): ?string
    {
        return $dateTime?->format('Y-m-d H:i:s');
    }

    protected function parseDateTime(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value);
    }
}

class UserArrayConverter extends AbstractArrayConverter
{
    protected function getEntityClass(): string
    {
        return User::class;
    }

    public function fromEntity(User $user): array
    {
        return [
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'name' => $user->getName(),
            'avatar_url' => $user->getAvatarUrl(),
            'is_active' => $user->isActive(),
            'created_at' => $this->formatDateTime($user->getCreatedAt()),
            'updated_at' => $this->formatNullableDateTime($user->getUpdatedAt()),
            'roles' => $user->getRoles()
        ];
    }

    public function toEntity(array $data): User
    {
        return new User(
            $data['id'],
            $data['email'],
            $data['name'],
            $data['avatar_url'] ?? null,
            $this->parseDateTime($data['created_at']),
            isset($data['updated_at']) ? $this->parseDateTime($data['updated_at']) : null,
            $data['is_active'] ?? true,
            $data['roles'] ?? []
        );
    }

    public function fromEntityCompact(User $user): array
    {
        return [
            'id' => $user->getId(),
            'name' => $user->getName(),
            'avatar_url' => $user->getAvatarUrl(),
            'is_active' => $user->isActive()
        ];
    }

    public function fromEntitySummary(User $user): array
    {
        return [
            'id' => $user->getId(),
            'name' => $user->getName(),
            'is_active' => $user->isActive()
        ];
    }
}

class ArrayConverterRegistry
{
    private array $converters = [];

    public function register(string $entityType, ArrayConverterInterface $converter): void
    {
        $this->converters[$entityType] = $converter;
    }

    public function getConverter(string $entityType): ?ArrayConverterInterface
    {
        return $this->converters[$entityType] ?? null;
    }

    public function convert(string $entityType, mixed $entity): array
    {
        $converter = $this->getConverter($entityType);

        if ($converter === null) {
            throw new \InvalidArgumentException("No converter for type: {$entityType}");
        }

        return $converter->fromEntity($entity);
    }

    public function convertMany(string $entityType, array $entities): array
    {
        $converter = $this->getConverter($entityType);

        if ($converter === null) {
            throw new \InvalidArgumentException("No converter for type: {$entityType}");
        }

        return $converter->fromEntities($entities);
    }
}
