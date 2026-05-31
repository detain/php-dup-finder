<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Entity\Resource;
use App\Repository\RoleRepository;
use Psr\Log\LoggerInterface;

final class RoleBasedAccessControl
{
    public function __construct(
        private readonly RoleRepository $roleRepository,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Determines if a user has access to a resource based on role hierarchy.
     *
     * This implementation uses explicit role comparison where higher privilege
     * roles automatically include permissions of lower privilege roles.
     */
    public function canAccess(User $user, Resource $resource): bool
    {
        $userRole = $this->roleRepository->getUserRole($user);
        $requiredRole = $resource->getRequiredRole();

        if ($userRole === null) {
            $this->logger->debug('Access denied - user has no role', [
                'user_id' => $user->getId(),
                'resource_id' => $resource->getId(),
            ]);
            return false;
        }

        $roleHierarchy = $this->buildRoleHierarchy();

        $userRoleLevel = $roleHierarchy[$userRole->getName()] ?? 0;
        $requiredRoleLevel = $roleHierarchy[$requiredRole] ?? PHP_INT_MAX;

        $hasAccess = $userRoleLevel >= $requiredRoleLevel;

        if (!$hasAccess) {
            $this->logger->debug('Access denied - insufficient role level', [
                'user_id' => $user->getId(),
                'user_role' => $userRole->getName(),
                'required_role' => $requiredRole,
            ]);
        }

        return $hasAccess;
    }

    /**
     * Builds a numeric hierarchy where higher numbers = more privilege.
     *
     * @return array<string, int>
     */
    private function buildRoleHierarchy(): array
    {
        return [
            'guest' => 0,
            'user' => 10,
            'moderator' => 20,
            'admin' => 30,
            'super_admin' => 40,
        ];
    }
}
