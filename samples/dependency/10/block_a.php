<?php

declare(strict_types=1);

namespace App\Application\Security;

use App\Infrastructure\Auth\AuthenticationServiceInterface;

/**
 * Admin panel authorization service.
 * The AuthenticationServiceInterface is manually injected here, duplicated across
 * all services that perform authorization.
 */
class AdminAuthorizationService
{
    private const PERMISSION_ADMIN_USERS = 'admin:users';
    private const PERMISSION_ADMIN_ROLES = 'admin:roles';
    private const PERMISSION_ADMIN_SETTINGS = 'admin:settings';
    private const PERMISSION_ADMIN_BILLING = 'admin:billing';
    private const PERMISSION_ADMIN_ANALYTICS = 'admin:analytics';

    private AuthenticationServiceInterface $authService;

    public function __construct(AuthenticationServiceInterface $authService)
    {
        $this->authService = $authService;
    }

    public function canManageUsers(string $userId): bool
    {
        $user = $this->authService->getUser($userId);

        if ($user === null) {
            return false;
        }

        return $user->hasPermission(self::PERMISSION_ADMIN_USERS);
    }

    public function canManageRoles(string $userId): bool
    {
        $user = $this->authService->getUser($userId);

        if ($user === null) {
            return false;
        }

        return $user->hasPermission(self::PERMISSION_ADMIN_ROLES);
    }

    public function canManageSettings(string $userId): bool
    {
        $user = $this->authService->getUser($userId);

        if ($user === null) {
            return false;
        }

        return $user->hasPermission(self::PERMISSION_ADMIN_SETTINGS);
    }

    public function canAccessBilling(string $userId): bool
    {
        $user = $this->authService->getUser($userId);

        if ($user === null) {
            return false;
        }

        return $user->hasPermission(self::PERMISSION_ADMIN_BILLING);
    }

    public function canAccessAnalytics(string $userId): bool
    {
        $user = $this->authService->getUser($userId);

        if ($user === null) {
            return false;
        }

        return $user->hasPermission(self::PERMISSION_ADMIN_ANALYTICS);
    }

    public function getUserPermissions(string $userId): array
    {
        $user = $this->authService->getUser($userId);

        if ($user === null) {
            return [];
        }

        return $user->getPermissions();
    }

    public function hasAnyPermission(string $userId, array $permissions): bool
    {
        $user = $this->authService->getUser($userId);

        if ($user === null) {
            return false;
        }

        foreach ($permissions as $permission) {
            if ($user->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    public function hasAllPermissions(string $userId, array $permissions): bool
    {
        $user = $this->authService->getUser($userId);

        if ($user === null) {
            return false;
        }

        foreach ($permissions as $permission) {
            if (!$user->hasPermission($permission)) {
                return false;
            }
        }

        return true;
    }
}
