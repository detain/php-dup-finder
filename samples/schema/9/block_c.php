<?php

declare(strict_types=1);

namespace App\Infrastructure\Authorization\Database;

/**
 * Database schema for roles and permissions.
 * This schema is duplicated from:
 * - Doctrine entities: Role, Permission
 * - Authorization service config
 * - Admin dashboard
 * - API schemas
 */
class AuthorizationDatabaseSchema
{
    public const TABLE_ROLES = 'roles';
    public const TABLE_PERMISSIONS = 'permissions';
    public const TABLE_ROLE_PERMISSIONS = 'role_permissions';
    public const TABLE_USER_ROLES = 'user_roles';

    public static function getCreateTableSQL(): string
    {
        return <<<SQL
CREATE TABLE roles (
    id CHAR(36) NOT NULL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(100) NOT NULL UNIQUE,
    description TEXT NULL,
    role_type VARCHAR(50) NOT NULL DEFAULT 'custom',
    priority INT NOT NULL DEFAULT 0,
    is_system BOOLEAN NOT NULL DEFAULT FALSE,
    constraints JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_slug (slug),
    INDEX idx_role_type (role_type),
    INDEX idx_priority (priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE permissions (
    id CHAR(36) NOT NULL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(100) NOT NULL UNIQUE,
    description TEXT NULL,
    category VARCHAR(50) NOT NULL,
    resource VARCHAR(100) NULL,
    action VARCHAR(50) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_slug (slug),
    INDEX idx_category (category),
    INDEX idx_resource (resource)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE role_permissions (
    role_id CHAR(36) NOT NULL,
    permission_id CHAR(36) NOT NULL,
    granted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (role_id, permission_id),
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_roles (
    user_id CHAR(36) NOT NULL,
    role_id CHAR(36) NOT NULL,
    granted_by CHAR(36) NULL,
    granted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NULL,

    PRIMARY KEY (user_id, role_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    FOREIGN KEY (granted_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE permission_groups (
    id CHAR(36) NOT NULL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(100) NOT NULL UNIQUE,
    description TEXT NULL,
    parent_id CHAR(36) NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_slug (slug),
    INDEX idx_parent (parent_id),
    FOREIGN KEY (parent_id) REFERENCES permission_groups(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;
    }

    /**
     * Permission categories.
     */
    public static function getPermissionCategories(): array
    {
        return [
            'user_management' => [
                'name' => 'User Management',
                'description' => 'Permissions for managing users',
                'permissions' => [
                    'users:read', 'users:create', 'users:update', 'users:delete',
                    'users:export', 'users:impersonate',
                ],
            ],
            'content' => [
                'name' => 'Content',
                'description' => 'Permissions for content management',
                'permissions' => [
                    'content:read', 'content:create', 'content:update', 'content:delete',
                    'content:publish', 'content:archive',
                ],
            ],
            'orders' => [
                'name' => 'Orders',
                'description' => 'Permissions for order management',
                'permissions' => [
                    'orders:read', 'orders:update', 'orders:cancel', 'orders:refund',
                ],
            ],
            'payments' => [
                'name' => 'Payments',
                'description' => 'Permissions for payment operations',
                'permissions' => [
                    'payments:read', 'payments:process', 'payments:refund', 'payments:void',
                ],
            ],
            'reports' => [
                'name' => 'Reports',
                'description' => 'Permissions for reporting',
                'permissions' => [
                    'reports:read', 'reports:create', 'reports:export', 'reports:schedule',
                ],
            ],
            'settings' => [
                'name' => 'Settings',
                'description' => 'Permissions for system settings',
                'permissions' => [
                    'settings:read', 'settings:update', 'settings:advanced',
                ],
            ],
        ];
    }
}
