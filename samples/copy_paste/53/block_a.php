<?php

declare(strict_types=1);

namespace App\Helpers;

class PermissionHelper
{
    public static function canUserViewUser(array $currentUser, array $targetUser): bool
    {
        // Admin can view anyone
        if (self::isAdmin($currentUser)) {
            return true;
        }

        // Users can view themselves
        if ($currentUser['id'] === $targetUser['id']) {
            return true;
        }

        // Organization admins can view users in their org
        if (self::isOrgAdmin($currentUser) &&
            $currentUser['organization_id'] === $targetUser['organization_id']) {
            return true;
        }

        return false;
    }

    public static function canUserEditUser(array $currentUser, array $targetUser): bool
    {
        // Admin can edit anyone
        if (self::isAdmin($currentUser)) {
            return true;
        }

        // Users can edit themselves
        if ($currentUser['id'] === $targetUser['id']) {
            return true;
        }

        // Organization admins can edit users in their org
        if (self::isOrgAdmin($currentUser) &&
            $currentUser['organization_id'] === $targetUser['organization_id']) {
            return true;
        }

        return false;
    }

    public static function canUserDeleteUser(array $currentUser, array $targetUser): bool
    {
        // Admin can delete anyone
        if (self::isAdmin($currentUser)) {
            return true;
        }

        // Users cannot delete themselves
        if ($currentUser['id'] === $targetUser['id']) {
            return false;
        }

        // Organization admins can delete users in their org (except other admins)
        if (self::isOrgAdmin($currentUser) &&
            $currentUser['organization_id'] === $targetUser['organization_id'] &&
            !self::isAdmin($targetUser) &&
            !self::isOrgAdmin($targetUser)) {
            return true;
        }

        return false;
    }

    public static function canUserViewOrder(array $currentUser, array $order): bool
    {
        // Admin can view any order
        if (self::isAdmin($currentUser)) {
            return true;
        }

        // Users can view their own orders
        if ($currentUser['id'] === $order['customer_id']) {
            return true;
        }

        // Organization admins can view orders in their org
        if (self::isOrgAdmin($currentUser) &&
            isset($order['organization_id']) &&
            $currentUser['organization_id'] === $order['organization_id']) {
            return true;
        }

        return false;
    }

    public static function canUserEditOrder(array $currentUser, array $order): bool
    {
        // Admin can edit any order
        if (self::isAdmin($currentUser)) {
            return true;
        }

        // Users can edit their own pending orders
        if ($currentUser['id'] === $order['customer_id'] &&
            $order['status'] === 'pending') {
            return true;
        }

        return false;
    }

    public static function canUserDeleteOrder(array $currentUser, array $order): bool
    {
        // Admin can delete any order
        if (self::isAdmin($currentUser)) {
            return true;
        }

        // Users cannot delete orders
        return false;
    }

    public static function canUserViewProduct(array $currentUser, array $product): bool
    {
        // Everyone can view active products
        if ($product['status'] === 'active') {
            return true;
        }

        // Admins can view any product
        if (self::isAdmin($currentUser)) {
            return true;
        }

        return false;
    }

    public static function canUserEditProduct(array $currentUser, array $product): bool
    {
        // Admin can edit any product
        if (self::isAdmin($currentUser)) {
            return true;
        }

        // Product managers can edit products
        if (self::hasRole($currentUser, 'product_manager')) {
            return true;
        }

        return false;
    }

    public static function canUserDeleteProduct(array $currentUser, array $product): bool
    {
        // Admin can delete any product
        if (self::isAdmin($currentUser)) {
            return true;
        }

        // Product managers can delete products
        if (self::hasRole($currentUser, 'product_manager')) {
            return true;
        }

        return false;
    }

    private static function isAdmin(array $user): bool
    {
        return $user['role'] === 'admin' || self::hasRole($user, 'admin');
    }

    private static function isOrgAdmin(array $user): bool
    {
        return $user['role'] === 'org_admin' || self::hasRole($user, 'org_admin');
    }

    private static function hasRole(array $user, string $role): bool
    {
        if (isset($user['roles']) && is_array($user['roles'])) {
            return in_array($role, $user['roles']);
        }

        if (isset($user['role'])) {
            return $user['role'] === $role;
        }

        return false;
    }
}
