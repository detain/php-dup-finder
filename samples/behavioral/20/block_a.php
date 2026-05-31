<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Entity\Permission;
use App\Repository\RoleRepository;
use Psr\Log\LoggerInterface;

final class AdminPermissionService
{
    public function __construct(
        private readonly RoleRepository $roleRepository,
        private readonly LoggerInterface $logger,
    ) {}

    public function canManageUsers(User $user): bool
    {
        if (!$user->isActive()) {
            $this->logger->debug('Permission denied: user inactive', ['user_id' => $user->getId()]);
            return false;
        }

        $roles = $user->getRoles();
        if (in_array('ROLE_SUPER_ADMIN', $roles, true)) {
            return true;
        }

        if (in_array('ROLE_ADMIN', $roles, true)) {
            $permissions = $this->getUserPermissions($user);

            if (!in_array('users:write', $permissions, true) && !in_array('users:manage', $permissions, true)) {
                $this->logger->debug('Permission denied: insufficient privileges', [
                    'user_id' => $user->getId(),
                    'required' => 'users:write or users:manage',
                ]);
                return false;
            }

            return true;
        }

        $this->logger->debug('Permission denied: insufficient role', ['user_id' => $user->getId()]);
        return false;
    }

    public function canViewReports(User $user): bool
    {
        if (!$user->isActive()) {
            return false;
        }

        $roles = $user->getRoles();
        if (in_array('ROLE_SUPER_ADMIN', $roles, true)) {
            return true;
        }

        if (in_array('ROLE_ADMIN', $roles, true) || in_array('ROLE_MANAGER', $roles, true)) {
            $permissions = $this->getUserPermissions($user);

            if (!in_array('reports:read', $permissions, true)) {
                $this->logger->debug('Permission denied: no reports:read', ['user_id' => $user->getId()]);
                return false;
            }

            return true;
        }

        return false;
    }

    public function canDeleteContent(User $user, int $contentOwnerId): bool
    {
        if (!$user->isActive()) {
            return false;
        }

        $roles = $user->getRoles();
        if (in_array('ROLE_SUPER_ADMIN', $roles, true)) {
            return true;
        }

        if (in_array('ROLE_ADMIN', $roles, true)) {
            $permissions = $this->getUserPermissions($user);

            if (in_array('content:delete:all', $permissions, true)) {
                return true;
            }

            if (in_array('content:delete:own', $permissions, true) && $user->getId() === $contentOwnerId) {
                return true;
            }

            return false;
        }

        return false;
    }

    public function canAccessSettings(User $user): bool
    {
        if (!$user->isActive()) {
            return false;
        }

        $roles = $user->getRoles();
        if (in_array('ROLE_SUPER_ADMIN', $roles, true)) {
            return true;
        }

        if (in_array('ROLE_ADMIN', $roles, true)) {
            $permissions = $this->getUserPermissions($user);
            return in_array('settings:manage', $permissions, true);
        }

        return false;
    }

    private function getUserPermissions(User $user): array
    {
        $permissions = [];

        foreach ($user->getRoles() as $roleName) {
            $role = $this->roleRepository->findOneByName($roleName);
            if ($role !== null) {
                $permissions = array_merge($permissions, $role->getPermissions());
            }
        }

        return array_unique($permissions);
    }
}
