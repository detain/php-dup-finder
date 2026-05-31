<?php

declare(strict_types=1);

namespace App\Api\Transform;

class UserApiTransformer
{
    public function transform(User $user, string $format = 'default'): array
    {
        $base = [
            'type' => 'user',
            'id' => $user->getId(),
            'attributes' => [
                'email' => $user->getEmail(),
                'name' => $user->getName(),
                'avatar_url' => $user->getAvatarUrl(),
                'is_active' => $user->isActive(),
                'created_at' => $user->getCreatedAt()->format('c'),
                'updated_at' => $user->getUpdatedAt()?->format('c')
            ],
            'relationships' => [
                'roles' => [
                    'data' => array_map(fn($role) => [
                        'type' => 'role',
                        'id' => $role
                    ], $user->getRoles())
                ]
            ]
        ];

        if ($format === 'compact') {
            return [
                'type' => 'user',
                'id' => $user->getId(),
                'attributes' => [
                    'name' => $user->getName(),
                    'avatar_url' => $user->getAvatarUrl()
                ]
            ];
        }

        if ($format === 'detailed') {
            $base['attributes']['roles'] = $user->getRoles();
            $base['meta'] = [
                'created_timestamp' => $user->getCreatedAt()->getTimestamp(),
                'last_modified' => $user->getUpdatedAt()?->getTimestamp()
            ];
        }

        return $base;
    }

    public function transformMany(array $users, string $format = 'default'): array
    {
        return [
            'data' => array_map(fn(User $user) => $this->transform($user, $format), $users),
            'meta' => [
                'count' => count($users),
                'format' => $format
            ]
        ];
    }

    public function transformForIndex(array $users): array
    {
        return [
            'data' => array_map(function (User $user) {
                return [
                    'type' => 'user',
                    'id' => $user->getId(),
                    'attributes' => [
                        'name' => $user->getName(),
                        'email' => $user->getEmail(),
                        'is_active' => $user->isActive()
                    ]
                ];
            }, $users),
            'meta' => [
                'count' => count($users)
            ]
        ];
    }

    public function transformForShow(User $user): array
    {
        return [
            'data' => $this->transform($user, 'detailed'),
            'meta' => [
                'timestamp' => time()
            ]
        ];
    }

    public function transformForCreate(User $user): array
    {
        return [
            'data' => [
                'type' => 'user',
                'id' => $user->getId(),
                'attributes' => [
                    'email' => $user->getEmail(),
                    'name' => $user->getName(),
                    'is_active' => $user->isActive(),
                    'created_at' => $user->getCreatedAt()->format('c')
                ]
            ]
        ];
    }

    public function transformForUpdate(User $user): array
    {
        return [
            'data' => [
                'type' => 'user',
                'id' => $user->getId(),
                'attributes' => [
                    'name' => $user->getName(),
                    'email' => $user->getEmail(),
                    'updated_at' => $user->getUpdatedAt()?->format('c')
                ]
            ]
        ];
    }

    public function addPagination(array $users, int $total, int $page, int $perPage): array
    {
        $lastPage = (int)ceil($total / $perPage);

        return [
            'data' => array_map(fn(User $user) => $this->transform($user), $users),
            'meta' => [
                'count' => count($users),
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'last_page' => $lastPage
            ],
            'links' => [
                'self' => '/users?page=' . $page,
                'first' => '/users?page=1',
                'prev' => $page > 1 ? '/users?page=' . ($page - 1) : null,
                'next' => $page < $lastPage ? '/users?page=' . ($page + 1) : null,
                'last' => '/users?page=' . $lastPage
            ]
        ];
    }
}
