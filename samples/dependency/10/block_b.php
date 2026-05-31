<?php

declare(strict_types=1);

namespace App\Application\Security;

use App\Infrastructure\Auth\AuthenticationServiceInterface;

/**
 * API endpoint authorization service.
 * The AuthenticationServiceInterface is manually injected here, duplicated from
 * AdminAuthorizationService and other authorization services.
 */
class ApiAuthorizationService
{
    private AuthenticationServiceInterface $authService;

    public function __construct(AuthenticationServiceInterface $authService)
    {
        $this->authService = $authService;
    }

    public function canAccessEndpoint(string $userId, string $endpoint, string $method): bool
    {
        $user = $this->authService->getUser($userId);

        if ($user === null) {
            return false;
        }

        $endpointPermission = $this->getEndpointPermission($endpoint, $method);

        if ($endpointPermission === null) {
            return true;
        }

        return $user->hasPermission($endpointPermission);
    }

    public function canAccessResource(string $userId, object $resource): bool
    {
        $user = $this->authService->getUser($userId);

        if ($user === null) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        if (method_exists($resource, 'getOwnerId')) {
            return $resource->getOwnerId() === $userId;
        }

        return true;
    }

    public function canModifyResource(string $userId, object $resource): bool
    {
        $user = $this->authService->getUser($userId);

        if ($user === null) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        if (method_exists($resource, 'getOwnerId')) {
            return $resource->getOwnerId() === $userId;
        }

        return false;
    }

    public function canDeleteResource(string $userId, object $resource): bool
    {
        $user = $this->authService->getUser($userId);

        if ($user === null) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        if (method_exists($resource, 'getOwnerId')) {
            return $resource->getOwnerId() === $userId;
        }

        if (method_exists($resource, 'isDeletable')) {
            return $resource->isDeletable();
        }

        return false;
    }

    public function filterAccessibleResources(string $userId, array $resources): array
    {
        $user = $this->authService->getUser($userId);

        if ($user === null) {
            return [];
        }

        if ($user->isAdmin()) {
            return $resources;
        }

        return array_filter($resources, function ($resource) use ($userId) {
            if (method_exists($resource, 'getOwnerId')) {
                return $resource->getOwnerId() === $userId;
            }
            return true;
        });
    }

    private function getEndpointPermission(string $endpoint, string $method): ?string
    {
        $permissions = [
            'GET /api/admin/users' => 'admin:users:read',
            'POST /api/admin/users' => 'admin:users:write',
            'PUT /api/admin/users' => 'admin:users:write',
            'DELETE /api/admin/users' => 'admin:users:delete',
            'GET /api/admin/roles' => 'admin:roles:read',
            'POST /api/admin/roles' => 'admin:roles:write',
            'PUT /api/admin/roles' => 'admin:roles:write',
            'DELETE /api/admin/roles' => 'admin:roles:delete',
        ];

        $key = "{$method} {$endpoint}";

        return $permissions[$key] ?? null;
    }
}
