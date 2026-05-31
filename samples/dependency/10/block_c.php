<?php

declare(strict_types=1);

namespace App\Application\Security;

use App\Infrastructure\Auth\AuthenticationServiceInterface;

/**
 * Content access authorization service.
 * The AuthenticationServiceInterface is manually injected here, duplicated from
 * AdminAuthorizationService, ApiAuthorizationService, and other services.
 */
class ContentAuthorizationService
{
    private AuthenticationServiceInterface $authService;

    public function __construct(AuthenticationServiceInterface $authService)
    {
        $this->authService = $authService;
    }

    public function canViewContent(string $userId, Content $content): bool
    {
        $user = $this->authService->getUser($userId);

        if ($user === null) {
            return false;
        }

        if ($content->isPublic()) {
            return true;
        }

        if ($content->isPrivate() && $content->getOwnerId() === $userId) {
            return true;
        }

        if ($content->isSharedWithOrganization()) {
            $userOrg = $this->authService->getUserOrganization($userId);
            return $content->getOrganizationId() === $userOrg;
        }

        if ($content->hasAccessList()) {
            return $content->isAccessibleTo($userId);
        }

        return false;
    }

    public function canEditContent(string $userId, Content $content): bool
    {
        $user = $this->authService->getUser($userId);

        if ($user === null) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        if ($content->getOwnerId() === $userId) {
            return true;
        }

        if ($content->isSharedWithOrganization()) {
            $userOrg = $this->authService->getUserOrganization($userId);
            if ($content->getOrganizationId() === $userOrg) {
                $orgRole = $this->authService->getUserOrgRole($userId);
                return in_array($orgRole, ['admin', 'editor']);
            }
        }

        return false;
    }

    public function canDeleteContent(string $userId, Content $content): bool
    {
        $user = $this->authService->getUser($userId);

        if ($user === null) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        if ($content->getOwnerId() === $userId) {
            return true;
        }

        return false;
    }

    public function canShareContent(string $userId, Content $content, string $shareType): bool
    {
        $user = $this->authService->getUser($userId);

        if ($user === null) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        if ($content->getOwnerId() !== $userId) {
            return false;
        }

        if ($shareType === 'organization' && !$content->isOrganizationShareable()) {
            return false;
        }

        if ($shareType === 'public' && !$content->isPublicShareable()) {
            return false;
        }

        return true;
    }

    public function filterViewableContent(string $userId, array $contents): array
    {
        $user = $this->authService->getUser($userId);

        if ($user === null) {
            return [];
        }

        if ($user->isAdmin()) {
            return $contents;
        }

        $userOrg = $this->authService->getUserOrganization($userId);

        return array_filter($contents, function ($content) use ($userId, $userOrg) {
            if ($content->isPublic()) {
                return true;
            }

            if ($content->getOwnerId() === $userId) {
                return true;
            }

            if ($content->isSharedWithOrganization() && $content->getOrganizationId() === $userOrg) {
                return true;
            }

            if ($content->hasAccessList() && $content->isAccessibleTo($userId)) {
                return true;
            }

            return false;
        });
    }
}
