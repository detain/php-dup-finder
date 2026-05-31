<?php

declare(strict_types=1);

namespace App\Permissions;

use App\Entity\Role;
use App\Repository\RoleRepository;
use App\Service\PermissionStore;
use Psr\Log\LoggerInterface;

final class PermissionService
{
    public function __construct(
        private readonly RoleRepository $roleRepository,
        private readonly PermissionStore $permissionStore,
        private readonly LoggerInterface $logger,
    ) {}

    public function hasPermission(int $userId, string $permission): bool
    {
        $user = $this->loadUser($userId);

        if ($user === null) {
            return false;
        }

        if ($user->getStatus() !== 'active') {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        $roles = $this->roleRepository->findByUser($userId);

        foreach ($roles as $role) {
            if ($this->roleHasPermission($role, $permission)) {
                return true;
            }
        }

        $userPermissions = $this->permissionStore->getUserPermissions($userId);
        if (in_array($permission, $userPermissions, true)) {
            return true;
        }

        $deniedPermissions = $this->permissionStore->getDeniedPermissions($userId);
        if (in_array($permission, $deniedPermissions, true)) {
            return false;
        }

        return false;
    }

    public function hasAnyPermission(int $userId, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->hasPermission($userId, $permission)) {
                return true;
            }
        }

        return false;
    }

    public function hasAllPermissions(int $userId, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (!$this->hasPermission($userId, $permission)) {
                return false;
            }
        }

        return true;
    }

    public function getUserPermissions(int $userId): array
    {
        $user = $this->loadUser($userId);

        if ($user === null || $user->getStatus() !== 'active') {
            return [];
        }

        if ($user->isSuperAdmin()) {
            return $this->permissionStore->getAllPermissions();
        }

        $permissions = [];
        $roles = $this->roleRepository->findByUser($userId);

        foreach ($roles as $role) {
            $permissions = array_merge($permissions, $role->getPermissions());
        }

        $userPermissions = $this->permissionStore->getUserPermissions($userId);
        $permissions = array_merge($permissions, $userPermissions);

        $deniedPermissions = $this->permissionStore->getDeniedPermissions($userId);
        $permissions = array_diff($permissions, $deniedPermissions);

        return array_unique($permissions);
    }

    private function loadUser(int $userId): ?User
    {
        return $this->userRepository->findById($userId);
    }

    private function roleHasPermission(Role $role, string $permission): bool
    {
        $rolePermissions = $role->getPermissions();

        return in_array($permission, $rolePermissions, true);
    }
}
