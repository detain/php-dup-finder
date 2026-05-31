<?php

declare(strict_types=1);

namespace App\Api\Hal;

class UserHalBuilder
{
    private string $baseUrl;

    public function __construct(string $baseUrl)
    {
        $this->baseUrl = $baseUrl;
    }

    public function build(User $user): array
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
                'self' => [
                    'href' => $this->buildSelfLink($user->getId())
                ],
                'edit' => [
                    'href' => $this->buildEditLink($user->getId())
                ],
                'avatar' => [
                    'href' => $user->getAvatarUrl()
                ]
            ],
            '_embedded' => []
        ];

        foreach ($user->getRoles() as $role) {
            $hal['_embedded']['roles'][] = [
                'name' => $role,
                '_links' => [
                    'role' => [
                        'href' => $this->buildRoleLink($role)
                    ]
                ]
            ];
        }

        $hal['_links']['curies'] = [
            [
                'name' => 'api',
                'href' => $this->baseUrl . '/docs/rels/{rel}',
                'templated' => true
            ]
        ];

        return $hal;
    }

    public function buildCollection(array $users, int $total, int $page, int $perPage): array
    {
        $hal = [
            'count' => count($users),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            '_links' => [
                'self' => [
                    'href' => $this->buildCollectionLink($page, $perPage)
                ]
            ],
            '_embedded' => [
                'users' => array_map(fn(User $user) => $this->build($user), $users)
            ]
        ];

        if ($page > 1) {
            $hal['_links']['prev'] = [
                'href' => $this->buildCollectionLink($page - 1, $perPage)
            ];
        }

        if ($page * $perPage < $total) {
            $hal['_links']['next'] = [
                'href' => $this->buildCollectionLink($page + 1, $perPage)
            ];
        }

        $hal['_links']['first'] = [
            'href' => $this->buildCollectionLink(1, $perPage)
        ];

        $hal['_links']['last'] = [
            'href' => $this->buildCollectionLink((int)ceil($total / $perPage), $perPage)
        ];

        $hal['_links']['curies'] = [
            [
                'name' => 'api',
                'href' => $this->baseUrl . '/docs/rels/{rel}',
                'templated' => true
            ]
        ];

        return $hal;
    }

    private function buildSelfLink(string $id): string
    {
        return $this->baseUrl . '/users/' . $id;
    }

    private function buildEditLink(string $id): string
    {
        return $this->baseUrl . '/users/' . $id . '/edit';
    }

    private function buildRoleLink(string $role): string
    {
        return $this->baseUrl . '/roles/' . urlencode($role);
    }

    private function buildCollectionLink(int $page, int $perPage): string
    {
        return $this->baseUrl . '/users?page=' . $page . '&per_page=' . $perPage;
    }
}
