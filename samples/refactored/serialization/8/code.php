<?php

declare(strict_types=1);

namespace App\Repository\Mapper;

interface ResultMapperInterface
{
    public function map(array $row): ?object;
    public function mapMany(array $rows): array;
    public function mapToArray(object $entity): array;
}

abstract class AbstractResultMapper implements ResultMapperInterface
{
    abstract protected function getEntityClass(): string;
    abstract protected function getConstructorArguments(array $row): array;
    abstract protected function getArrayMapping(object $entity): array;

    public function map(array $row): ?object
    {
        if ($row === null || count($row) === 0) {
            return null;
        }

        $class = $this->getEntityClass();
        $args = $this->getConstructorArguments($row);

        return new $class(...$args);
    }

    public function mapMany(array $rows): array
    {
        return array_filter(array_map(fn(array $row) => $this->map($row), $rows));
    }

    public function mapToArray(object $entity): array
    {
        $data = $this->getArrayMapping($entity);
        return $this->serializeComplexFields($data);
    }

    protected function getString(array $row, string $key, string $default = ''): string
    {
        return isset($row[$key]) && $row[$key] !== null ? (string)$row[$key] : $default;
    }

    protected function getInt(array $row, string $key, int $default = 0): int
    {
        return isset($row[$key]) && $row[$key] !== null ? (int)$row[$key] : $default;
    }

    protected function getFloat(array $row, string $key, float $default = 0.0): float
    {
        return isset($row[$key]) && $row[$key] !== null ? (float)$row[$key] : $default;
    }

    protected function getBool(array $row, string $key, bool $default = true): bool
    {
        return isset($row[$key]) && $row[$key] !== null ? (bool)$row[$key] : $default;
    }

    protected function getDateTime(array $row, string $key, bool $required = false): ?\DateTimeImmutable
    {
        if (!isset($row[$key]) || $row[$key] === null) {
            return $required ? new \DateTimeImmutable() : null;
        }

        return new \DateTimeImmutable($row[$key]);
    }

    protected function getJsonArray(array $row, string $key): array
    {
        if (!isset($row[$key]) || empty($row[$key])) {
            return [];
        }

        $decoded = json_decode($row[$key], true);

        return is_array($decoded) ? $decoded : [];
    }

    protected function serializeJson(string $json): string
    {
        return json_encode(json_decode($json, true) ?? []);
    }

    private function serializeComplexFields(array $data): array
    {
        foreach ($data as $key => $value) {
            if ($value instanceof \DateTimeInterface) {
                $data[$key] = $value->format('Y-m-d H:i:s');
            } elseif (is_array($value)) {
                $data[$key] = json_encode($value);
            }
        }

        return $data;
    }
}

class UserResultMapper extends AbstractResultMapper
{
    protected function getEntityClass(): string
    {
        return User::class;
    }

    protected function getConstructorArguments(array $row): array
    {
        return [
            $this->getString($row, 'id'),
            $this->getString($row, 'email'),
            $this->getString($row, 'name'),
            $row['avatar_url'] ?? null,
            $this->getDateTime($row, 'created_at', true),
            $this->getDateTime($row, 'updated_at'),
            $this->getBool($row, 'is_active', true),
            $this->getJsonArray($row, 'roles')
        ];
    }

    protected function getArrayMapping(object $entity): array
    {
        return [
            'id' => $entity->getId(),
            'email' => $entity->getEmail(),
            'name' => $entity->getName(),
            'avatar_url' => $entity->getAvatarUrl(),
            'is_active' => $entity->isActive() ? 1 : 0,
            'roles' => $entity->getRoles(),
            'created_at' => $entity->getCreatedAt(),
            'updated_at' => $entity->getUpdatedAt()
        ];
    }
}

class ResultMapperRegistry
{
    private array $mappers = [];

    public function register(string $entityType, ResultMapperInterface $mapper): void
    {
        $this->mappers[$entityType] = $mapper;
    }

    public function getMapper(string $entityType): ?ResultMapperInterface
    {
        return $this->mappers[$entityType] ?? null;
    }

    public function map(string $entityType, array $row): ?object
    {
        $mapper = $this->getMapper($entityType);

        if ($mapper === null) {
            throw new \InvalidArgumentException("No mapper for type: {$entityType}");
        }

        return $mapper->map($row);
    }

    public function mapMany(string $entityType, array $rows): array
    {
        $mapper = $this->getMapper($entityType);

        if ($mapper === null) {
            throw new \InvalidArgumentException("No mapper for type: {$entityType}");
        }

        return $mapper->mapMany($rows);
    }
}
