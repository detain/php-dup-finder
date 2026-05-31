<?php

declare(strict_types=1);

namespace App\Application\Security;

use App\Infrastructure\Auth\AuthenticationServiceInterface;

/**
 * Base authorization service with shared authentication logic.
 * Centralizes AuthenticationServiceInterface injection.
 */
abstract class BaseAuthorizationService
{
    protected AuthenticationServiceInterface $authService;

    public function __construct(AuthenticationServiceInterface $authService)
    {
        $this->authService = $authService;
    }

    protected function getCurrentUser(?string $userId)
    {
        if ($userId === null) {
            return null;
        }

        return $this->authService->getUser($userId);
    }

    protected function isAdmin(?string $userId): bool
    {
        $user = $this->getCurrentUser($userId);
        return $user !== null && $user->isAdmin();
    }

    protected function hasPermission(?string $userId, string $permission): bool
    {
        $user = $this->getCurrentUser($userId);
        return $user !== null && $user->hasPermission($permission);
    }
}

class AdminAuthorizationService extends BaseAuthorizationService
{
    public function canManageUsers(string $userId): bool
    {
        return $this->hasPermission($userId, 'admin:users');
    }
}
