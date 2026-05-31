<?php

declare(strict_types=1);

namespace App\Api\Transform;

abstract class ApiTransformer
{
    abstract protected function getResourceType(): string;
    abstract protected function transformAttributes(mixed $entity, string $format): array;
    abstract protected function transformRelationships(mixed $entity): array;

    public function transform(mixed $entity, string $format = 'default'): array
    {
        $base = [
            'type' => $this->getResourceType(),
            'id' => $this->getEntityId($entity),
            'attributes' => $this->transformAttributes($entity, $format),
            'relationships' => $this->transformRelationships($entity)
        ];

        if ($format === 'compact') {
            return $this->transformCompact($entity);
        }

        if ($format === 'detailed') {
            $base['meta'] = $this->getMeta($entity);
        }

        return $base;
    }

    public function transformMany(array $entities, string $format = 'default'): array
    {
        return [
            'data' => array_map(fn($entity) => $this->transform($entity, $format), $entities),
            'meta' => [
                'count' => count($entities),
                'format' => $format
            ]
        ];
    }

    public function transformForIndex(array $entities): array
    {
        return [
            'data' => array_map(fn($entity) => $this->transformIndex($entity), $entities),
            'meta' => ['count' => count($entities)]
        ];
    }

    public function transformForShow(mixed $entity): array
    {
        return [
            'data' => $this->transform($entity, 'detailed'),
            'meta' => ['timestamp' => time()]
        ];
    }

    public function transformForCreate(mixed $entity): array
    {
        return [
            'data' => [
                'type' => $this->getResourceType(),
                'id' => $this->getEntityId($entity),
                'attributes' => $this->transformCreate($entity)
            ]
        ];
    }

    public function transformForUpdate(mixed $entity): array
    {
        return [
            'data' => [
                'type' => $this->getResourceType(),
                'id' => $this->getEntityId($entity),
                'attributes' => $this->transformUpdate($entity)
            ]
        ];
    }

    public function addPagination(array $entities, int $total, int $page, int $perPage): array
    {
        $lastPage = (int)ceil($total / $perPage);

        return [
            'data' => array_map(fn($entity) => $this->transform($entity), $entities),
            'meta' => [
                'count' => count($entities),
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'last_page' => $lastPage
            ],
            'links' => $this->buildPaginationLinks($page, $perPage, $lastPage)
        ];
    }

    protected function buildPaginationLinks(int $page, int $perPage, int $lastPage): array
    {
        $resource = $this->getResourceType();

        return [
            'self' => "/{$resource}?page={$page}",
            'first' => "/{$resource}?page=1",
            'prev' => $page > 1 ? "/{$resource}?page=" . ($page - 1) : null,
            'next' => $page < $lastPage ? "/{$resource}?page=" . ($page + 1) : null,
            'last' => "/{$resource}?page={$lastPage}"
        ];
    }

    protected function transformCompact(mixed $entity): array
    {
        return [
            'type' => $this->getResourceType(),
            'id' => $this->getEntityId($entity),
            'attributes' => $this->getCompactAttributes($entity)
        ];
    }

    abstract protected function getEntityId(mixed $entity): string;
    abstract protected function getMeta(mixed $entity): array;
    abstract protected function getCompactAttributes(mixed $entity): array;
    abstract protected function transformCreate(mixed $entity): array;
    abstract protected function transformUpdate(mixed $entity): array;
    abstract protected function transformIndex(mixed $entity): array;
}

class UserApiTransformer extends ApiTransformer
{
    protected function getResourceType(): string
    {
        return 'user';
    }

    protected function getEntityId(mixed $entity): string
    {
        return $entity->getId();
    }

    protected function transformAttributes(mixed $entity, string $format): array
    {
        return [
            'email' => $entity->getEmail(),
            'name' => $entity->getName(),
            'avatar_url' => $entity->getAvatarUrl(),
            'is_active' => $entity->isActive(),
            'created_at' => $entity->getCreatedAt()->format('c'),
            'updated_at' => $entity->getUpdatedAt()?->format('c')
        ];
    }

    protected function transformRelationships(mixed $entity): array
    {
        return [
            'roles' => [
                'data' => array_map(fn($role) => ['type' => 'role', 'id' => $role], $entity->getRoles())
            ]
        ];
    }

    protected function getCompactAttributes(mixed $entity): array
    {
        return [
            'name' => $entity->getName(),
            'avatar_url' => $entity->getAvatarUrl()
        ];
    }

    protected function getMeta(mixed $entity): array
    {
        return [
            'created_timestamp' => $entity->getCreatedAt()->getTimestamp(),
            'last_modified' => $entity->getUpdatedAt()?->getTimestamp()
        ];
    }

    protected function transformCreate(mixed $entity): array
    {
        return [
            'email' => $entity->getEmail(),
            'name' => $entity->getName(),
            'is_active' => $entity->isActive(),
            'created_at' => $entity->getCreatedAt()->format('c')
        ];
    }

    protected function transformUpdate(mixed $entity): array
    {
        return [
            'name' => $entity->getName(),
            'email' => $entity->getEmail(),
            'updated_at' => $entity->getUpdatedAt()?->format('c')
        ];
    }

    protected function transformIndex(mixed $entity): array
    {
        return [
            'type' => 'user',
            'id' => $entity->getId(),
            'attributes' => [
                'name' => $entity->getName(),
                'email' => $entity->getEmail(),
                'is_active' => $entity->isActive()
            ]
        ];
    }
}

class ApiTransformerRegistry
{
    private array $transformers = [];

    public function register(string $resourceType, ApiTransformer $transformer): void
    {
        $this->transformers[$resourceType] = $transformer;
    }

    public function getTransformer(string $resourceType): ?ApiTransformer
    {
        return $this->transformers[$resourceType] ?? null;
    }

    public function transform(string $resourceType, mixed $entity, string $format = 'default'): array
    {
        $transformer = $this->getTransformer($resourceType);

        if ($transformer === null) {
            throw new \InvalidArgumentException("No transformer for: {$resourceType}");
        }

        return $transformer->transform($entity, $format);
    }
}
