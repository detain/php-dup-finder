<?php

declare(strict_types=1);

namespace App\Infrastructure\Authorization\Config;

/**
 * Authorization configuration schema.
 * This configuration is duplicated from:
 * - Doctrine entities: Role, Permission
 * - Database tables
 * - Admin dashboard
 * - API schemas
 */
class AuthorizationConfigSchema
{
    /**
     * Role definition schema.
     */
    public static function getRoleSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['name', 'slug'],
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'minLength' => 2,
                    'maxLength' => 100,
                    'description' => 'Human-readable role name',
                ],
                'slug' => [
                    'type' => 'string',
                    'pattern' => '^[a-z][a-z0-9_]*$',
                    'maxLength' => 100,
                    'description' => 'URL-safe role identifier',
                ],
                'description' => [
                    'type' => 'string',
                    'maxLength' => 500,
                ],
                'role_type' => [
                    'type' => 'string',
                    'enum' => ['system', 'custom', 'dynamic'],
                    'default' => 'custom',
                ],
                'priority' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'maximum' => 1000,
                    'default' => 0,
                ],
                'is_system' => [
                    'type' => 'boolean',
                    'default' => false,
                    'description' => 'System roles cannot be deleted',
                ],
                'permissions' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Permission slugs assigned to this role',
                ],
                'constraints' => [
                    'type' => 'object',
                    'description' => 'Conditional constraints for role assignment',
                    'properties' => [
                        'require_mfa' => ['type' => 'boolean'],
                        'require_org' => ['type' => 'boolean'],
                        'max_sessions' => ['type' => 'integer'],
                        'allowed_ips' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                ],
            ],
        ];
    }

    /**
     * Permission definition schema.
     */
    public static function getPermissionSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['name', 'slug', 'category'],
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'minLength' => 2,
                    'maxLength' => 100,
                ],
                'slug' => [
                    'type' => 'string',
                    'pattern' => '^[a-z][a-z0-9_]*$',
                    'maxLength' => 100,
                ],
                'description' => [
                    'type' => 'string',
                    'maxLength' => 500,
                ],
                'category' => [
                    'type' => 'string',
                    'enum' => [
                        'user_management', 'content', 'orders', 'payments',
                        'reports', 'settings', 'admin', 'api', 'custom',
                    ],
                ],
                'resource' => [
                    'type' => 'string',
                    'description' => 'Resource this permission applies to',
                ],
                'action' => [
                    'type' => 'string',
                    'enum' => ['create', 'read', 'update', 'delete', 'execute', 'manage'],
                ],
            ],
        ];
    }

    /**
     * Default roles and permissions configuration.
     */
    public static function getDefaultRolesConfig(): array
    {
        return [
            'super_admin' => [
                'name' => 'Super Administrator',
                'slug' => 'super_admin',
                'description' => 'Full system access with all permissions',
                'role_type' => 'system',
                'priority' => 100,
                'is_system' => true,
                'permissions' => ['*'],
            ],
            'admin' => [
                'name' => 'Administrator',
                'slug' => 'admin',
                'description' => 'Administrative access to manage users and settings',
                'role_type' => 'system',
                'priority' => 90,
                'is_system' => true,
                'permissions' => [
                    'users:read', 'users:create', 'users:update', 'users:delete',
                    'roles:read', 'roles:create', 'roles:update', 'roles:delete',
                    'settings:read', 'settings:update',
                    'reports:read', 'reports:export',
                ],
            ],
            'manager' => [
                'name' => 'Manager',
                'slug' => 'manager',
                'description' => 'Management access for day-to-day operations',
                'role_type' => 'system',
                'priority' => 70,
                'is_system' => true,
                'permissions' => [
                    'users:read',
                    'orders:read', 'orders:update',
                    'reports:read',
                ],
            ],
            'user' => [
                'name' => 'Standard User',
                'slug' => 'user',
                'description' => 'Basic user access',
                'role_type' => 'system',
                'priority' => 50,
                'is_system' => true,
                'permissions' => [
                    'profile:read', 'profile:update',
                    'orders:read',
                ],
            ],
        ];
    }
}
