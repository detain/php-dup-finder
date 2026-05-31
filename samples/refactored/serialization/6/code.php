<?php

declare(strict_types=1);

namespace App\Api\Hal;

abstract class HalBuilder
{
    protected string $baseUrl;
    protected string $resourceType;

    public function __construct(string $baseUrl, string $resourceType)
    {
        $this->baseUrl = $baseUrl;
        $this->resourceType = $resourceType;
    }

    abstract public function build(mixed $entity): array;
    abstract public function buildCollection(array $entities, int $total, int $page, int $perPage): array;

    protected function buildPaginationLinks(int $total, int $page, int $perPage): array
    {
        $links = [];
        $lastPage = (int)ceil($total / $perPage);

        $links['self'] = ['href' => $this->buildCollectionLink($page, $perPage)];

        if ($page > 1) {
            $links['prev'] = ['href' => $this->buildCollectionLink($page - 1, $perPage)];
        }

        if ($page * $perPage < $total) {
            $links['next'] = ['href' => $this->buildCollectionLink($page + 1, $perPage)];
        }

        $links['first'] = ['href' => $this->buildCollectionLink(1, $perPage)];
        $links['last'] = ['href' => $this->buildCollectionLink($lastPage, $perPage)];

        return $links;
    }

    protected function buildCuriesLink(): array
    {
        return [
            [
                'name' => 'api',
                'href' => $this->baseUrl . '/docs/rels/{rel}',
                'templated' => true
            ]
        ];
    }

    protected function buildSelfLink(string $id): string
    {
        return $this->baseUrl . '/' . $this->resourceType . '/' . $id;
    }

    protected function buildEditLink(string $id): string
    {
        return $this->baseUrl . '/' . $this->resourceType . '/' . $id . '/edit';
    }

    protected function buildCollectionLink(int $page, int $perPage): string
    {
        return $this->baseUrl . '/' . $this->resourceType . '?page=' . $page . '&per_page=' . $perPage;
    }

    protected function buildResourceCollection(string $embeddedKey, array $entities): array
    {
        return [
            '_embedded' => [
                $embeddedKey => array_map(fn($entity) => $this->build($entity), $entities)
            ]
        ];
    }
}

class UserHalBuilder extends HalBuilder
{
    public function __construct(string $baseUrl)
    {
        parent::__construct($baseUrl, 'users');
    }

    public function build(mixed $entity): array
    {
        $hal = [
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'name' => $user->getName(),
            'avatar_url' => $user->getAvatarUrl(),
            'is_active' => $user->isActive(),
            'roles' => $user->getRoles(),
            'created_at' => $user->getCreatedAt()->format('c'),
            'updated_at' => $user->getUpdatedAt()?->format('c'),
            '_links' => [
                'self' => ['href' => $this->buildSelfLink($user->getId())],
                'edit' => ['href' => $this->buildEditLink($user->getId())],
                'avatar' => ['href' => $user->getAvatarUrl()]
            ]
        ];

        $hal['_links']['curies'] = $this->buildCuriesLink();

        return $hal;
    }

    public function buildCollection(array $users, int $total, int $page, int $perPage): array
    {
        return array_merge([
            'count' => count($users),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            '_links' => $this->buildPaginationLinks($total, $page, $perPage),
            '_embedded' => [
                'users' => array_map(fn(User $u) => $this->build($u), $users)
            ]
        ], ['_links' => array_merge(
            $this->buildPaginationLinks($total, $page, $perPage),
            ['curies' => $this->buildCuriesLink()]
        )]);
    }
}

class HalBuilderRegistry
{
    private array $builders = [];

    public function register(string $resourceType, HalBuilder $builder): void
    {
        $this->builders[$resourceType] = $builder;
    }

    public function getBuilder(string $resourceType): ?HalBuilder
    {
        return $this->builders[$resourceType] ?? null;
    }

    public function build(string $resourceType, mixed $entity): array
    {
        $builder = $this->getBuilder($resourceType);

        if ($builder === null) {
            throw new \InvalidArgumentException("No HAL builder for: {$resourceType}");
        }

        return $builder->build($entity);
    }
}
