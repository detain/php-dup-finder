<?php

declare(strict_types=1);

namespace App\Domain\Shared\Schema;

/**
 * Centralized Authorization schema registry.
 * Single source of truth for all authorization schema definitions.
 */
final class AuthorizationSchemaRegistry
{
    public const ROLE_TABLE = 'roles';
    public const PERMISSION_TABLE = 'permissions';
    public const ROLE_PERMISSION_TABLE = 'role_permissions';
    public const USER_ROLE_TABLE = 'user_roles';

    public static function getRoleColumns(): array
    {
        return [
            'id' => ['type' => 'char', 'length' => 36],
            'name' => ['type' => 'varchar', 'length' => 100],
            'slug' => ['type' => 'varchar', 'length' => 100, 'unique' => true],
            'description' => ['type' => 'text', 'nullable' => true],
            'role_type' => ['type' => 'varchar', 'length' => 50],
            'priority' => ['type' => 'int'],
            'is_system' => ['type' => 'boolean'],
            'constraints' => ['type' => 'json', 'nullable' => true],
            'created_at' => ['type' => 'datetime'],
            'updated_at' => ['type' => 'datetime'],
        ];
    }

    public static function getPermissionColumns(): array
    {
        return [
            'id' => ['type' => 'char', 'length' => 36],
            'name' => ['type' => 'varchar', 'length' => 100],
            'slug' => ['type' => 'varchar', 'length' => 100, 'unique' => true],
            'description' => ['type' => 'text', 'nullable' => true],
            'category' => ['type' => 'varchar', 'length' => 50],
            'resource' => ['type' => 'varchar', 'length' => 100, 'nullable' => true],
            'action' => ['type' => 'varchar', 'length' => 50, 'nullable' => true],
            'created_at' => ['type' => 'datetime'],
        ];
    }
}
