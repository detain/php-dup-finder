<?php

declare(strict_types=1);

namespace App\Helpers;

class OrderPermissionHelper
{
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

    public static function canUserCancelOrder(array $currentUser, array $order): bool
    {
        // Admin can cancel any order
        if (self::isAdmin($currentUser)) {
            return true;
        }

        // Users can cancel their own pending/processing orders
        if ($currentUser['id'] === $order['customer_id'] &&
            in_array($order['status'], ['pending', 'processing'])) {
            return true;
        }

        return false;
    }

    public static function canUserRefundOrder(array $currentUser, array $order): bool
    {
        // Admin can refund any order
        if (self::isAdmin($currentUser)) {
            return true;
        }

        // Finance can refund orders
        if (self::hasRole($currentUser, 'finance')) {
            return true;
        }

        return false;
    }

    public static function canUserViewInvoice(array $currentUser, array $invoice): bool
    {
        // Admin can view any invoice
        if (self::isAdmin($currentUser)) {
            return true;
        }

        // Users can view their own invoices
        if ($currentUser['id'] === $invoice['customer_id']) {
            return true;
        }

        // Organization admins can view invoices in their org
        if (self::isOrgAdmin($currentUser) &&
            isset($invoice['organization_id']) &&
            $currentUser['organization_id'] === $invoice['organization_id']) {
            return true;
        }

        return false;
    }

    public static function canUserVoidInvoice(array $currentUser, array $invoice): bool
    {
        // Admin can void any invoice
        if (self::isAdmin($currentUser)) {
            return true;
        }

        // Finance can void unpaid invoices
        if (self::hasRole($currentUser, 'finance') &&
            $invoice['status'] === 'unpaid') {
            return true;
        }

        return false;
    }

    public static function canUserViewShipment(array $currentUser, array $shipment): bool
    {
        // Admin can view any shipment
        if (self::isAdmin($currentUser)) {
            return true;
        }

        // Users can view their own shipments
        if ($currentUser['id'] === $shipment['customer_id']) {
            return true;
        }

        // Organization admins can view shipments in their org
        if (self::isOrgAdmin($currentUser) &&
            isset($shipment['organization_id']) &&
            $currentUser['organization_id'] === $shipment['organization_id']) {
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
