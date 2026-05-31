<?php

declare(strict_types=1);

namespace App\Helpers;

trait PermissionCheckTrait
{
    protected static function isCurrentUserAdmin(array $user): bool
    {
        return $user['role'] === 'admin' || self::hasRole($user, 'admin');
    }

    protected static function isCurrentUserOrgAdmin(array $user): bool
    {
        return $user['role'] === 'org_admin' || self::hasRole($user, 'org_admin');
    }

    protected static function hasRole(array $user, string $role): bool
    {
        if (isset($user['roles']) && is_array($user['roles'])) {
            return in_array($role, $user['roles']);
        }

        if (isset($user['role'])) {
            return $user['role'] === $role;
        }

        return false;
    }

    protected static function canCurrentUserAccessOwn(array $currentUser, array $target, string $ownerField = 'customer_id'): bool
    {
        return $currentUser['id'] === $target[$ownerField];
    }

    protected static function canCurrentUserAccessOrg(array $currentUser, array $target): bool
    {
        return self::isCurrentUserOrgAdmin($currentUser) &&
               isset($target['organization_id']) &&
               $currentUser['organization_id'] === $target['organization_id'];
    }

    protected static function buildPermissionChecker(string $entityType): callable
    {
        return match ($entityType) {
            'user' => fn($current, $target) => self::checkUserPermission($current, $target),
            'order' => fn($current, $target) => self::checkOrderPermission($current, $target),
            'product' => fn($current, $target) => self::checkProductPermission($current, $target),
            default => throw new \InvalidArgumentException("Unknown entity type: {$entityType}"),
        };
    }

    private static function checkUserPermission(array $current, array $target): array
    {
        return [
            'view' => self::isCurrentUserAdmin($current) || self::canCurrentUserAccessOwn($current, $target) || self::canCurrentUserAccessOrg($current, $target),
            'edit' => self::isCurrentUserAdmin($current) || self::canCurrentUserAccessOwn($current, $target) || self::canCurrentUserAccessOrg($current, $target),
            'delete' => self::isCurrentUserAdmin($current) && !self::isCurrentUserAdmin($target),
        ];
    }

    private static function checkOrderPermission(array $current, array $target): array
    {
        return [
            'view' => self::isCurrentUserAdmin($current) || self::canCurrentUserAccessOwn($current, $target) || self::canCurrentUserAccessOrg($current, $target),
            'edit' => self::isCurrentUserAdmin($current) || (self::canCurrentUserAccessOwn($current, $target) && $target['status'] === 'pending'),
            'cancel' => self::isCurrentUserAdmin($current) || (self::canCurrentUserAccessOwn($current, $target) && in_array($target['status'], ['pending', 'processing'])),
        ];
    }

    private static function checkProductPermission(array $current, array $target): array
    {
        return [
            'view' => $target['status'] === 'active' || self::isCurrentUserAdmin($current),
            'edit' => self::isCurrentUserAdmin($current) || self::hasRole($current, 'product_manager'),
            'delete' => self::isCurrentUserAdmin($current) || self::hasRole($current, 'product_manager'),
        ];
    }
}

class PermissionHelper
{
    use PermissionCheckTrait;

    public static function canUserViewUser(array $currentUser, array $targetUser): bool
    {
        $permissions = self::checkUserPermission($currentUser, $targetUser);
        return $permissions['view'];
    }
}
