<?php

declare(strict_types=1);

namespace App\Cms\Security;

use App\Entity\CmsUser;
use App\Entity\ContentPermission;
use App\Repository\PermissionRepository;
use Psr\Log\LoggerInterface;

final class ContentPermissionService
{
    public function __construct(
        private readonly PermissionRepository $permissionRepository,
        private readonly LoggerInterface $logger,
    ) {}

    public function canCreateContent(CmsUser $user): bool
    {
        if (!$user->isActive()) {
            $this->logger->debug('Permission denied: user inactive', ['user_id' => $user->getId()]);
            return false;
        }

        $userRole = $user->getRole();
        if ($userRole === 'administrator' || $userRole === 'editor') {
            return true;
        }

        if ($userRole === 'author') {
            $permissions = $this->getUserPermissions($user);
            return in_array('content:create', $permissions, true);
        }

        $this->logger->debug('Permission denied: insufficient role', ['user_id' => $user->getId()]);
        return false;
    }

    public function canEditContent(CmsUser $user, int $contentAuthorId): bool
    {
        if (!$user->isActive()) {
            return false;
        }

        $userRole = $user->getRole();
        if ($userRole === 'administrator') {
            return true;
        }

        if ($userRole === 'editor') {
            return true;
        }

        if ($userRole === 'author' && $user->getId() === $contentAuthorId) {
            $permissions = $this->getUserPermissions($user);
            return in_array('content:edit:own', $permissions, true);
        }

        return false;
    }

    public function canPublishContent(CmsUser $user): bool
    {
        if (!$user->isActive()) {
            return false;
        }

        $userRole = $user->getRole();
        if ($userRole === 'administrator') {
            return true;
        }

        if ($userRole === 'editor') {
            $permissions = $this->getUserPermissions($user);
            return in_array('content:publish', $permissions, true);
        }

        $this->logger->debug('Permission denied: cannot publish', ['user_id' => $user->getId()]);
        return false;
    }

    public function canManageCategories(CmsUser $user): bool
    {
        if (!$user->isActive()) {
            return false;
        }

        $userRole = $user->getRole();
        if ($userRole === 'administrator' || $userRole === 'editor') {
            $permissions = $this->getUserPermissions($user);
            return in_array('categories:manage', $permissions, true);
        }

        return false;
    }

    public function canAccessMediaLibrary(CmsUser $user): bool
    {
        if (!$user->isActive()) {
            return false;
        }

        $userRole = $user->getRole();
        if (in_array($userRole, ['administrator', 'editor', 'author'], true)) {
            return true;
        }

        $permissions = $this->getUserPermissions($user);
        return in_array('media:read', $permissions, true);
    }

    private function getUserPermissions(CmsUser $user): array
    {
        $permissions = [];

        $userPermissions = $this->permissionRepository->findByUserId($user->getId());
        foreach ($userPermissions as $perm) {
            $permissions[] = $perm->getName();
        }

        return array_unique($permissions);
    }
}
