<?php

declare(strict_types=1);

namespace App\Domain\Shared\Schema;

use App\Domain\User\Entity\User;

/**
 * Single source of truth for User schema.
 * All representations (DB, ORM, API, JSON) should be derived from this.
 */
final class UserSchemaRegistry
{
    public const TABLE_NAME = 'users';
    public const ENTITY_CLASS = User::class;
    public const API_SCHEMA = 'UserRegistration';
    public const JSON_SCHEMA_PATH = 'schemas/user-registration.json';

    public static function getColumns(): array
    {
        return [
            'id' => ['type' => 'string', 'length' => 36, 'primary' => true],
            'email' => ['type' => 'string', 'length' => 255, 'unique' => true],
            'password_hash' => ['type' => 'string', 'length' => 255],
            'first_name' => ['type' => 'string', 'length' => 100],
            'last_name' => ['type' => 'string', 'length' => 100],
            'phone_number' => ['type' => 'string', 'length' => 20, 'nullable' => true],
            'country_code' => ['type' => 'string', 'length' => 2, 'default' => 'US'],
            'created_at' => ['type' => 'datetime_immutable'],
            'email_verified_at' => ['type' => 'datetime_immutable', 'nullable' => true],
            'status' => ['type' => 'string', 'length' => 20, 'default' => 'pending'],
            'referral_code' => ['type' => 'string', 'length' => 36, 'nullable' => true],
            'preferences' => ['type' => 'json', 'nullable' => true],
            'organization_id' => ['type' => 'string', 'length' => 36, 'nullable' => true],
        ];
    }

    public static function getDDL(): string
    {
        $columns = [];
        foreach (self::getColumns() as $name => $config) {
            $type = match ($config['type']) {
                'string' => "VARCHAR({$config['length']})",
                'datetime_immutable' => 'DATETIME',
                'json' => 'JSON',
                default => 'VARCHAR(255)',
            };

            $nullable = $config['nullable'] ?? false ? ' NULL' : ' NOT NULL';
            $default = isset($config['default']) ? " DEFAULT '{$config['default']}'" : '';
            $unique = ($config['unique'] ?? false) ? ' UNIQUE' : '';

            $columns[] = "{$name} {$type}{$nullable}{$default}{$unique}";
        }

        return "CREATE TABLE " . self::TABLE_NAME . " (\n    " . implode(",\n    ", $columns) . "\n)";
    }
}
