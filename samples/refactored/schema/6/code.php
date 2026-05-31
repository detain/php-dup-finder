<?php

declare(strict_types=1);

namespace App\Domain\Shared\Schema;

/**
 * Centralized Feature Flag schema registry.
 * Single source of truth for all feature flag schema definitions.
 */
final class FeatureFlagSchemaRegistry
{
    public const TABLE_NAME = 'feature_flags';

    public static function getColumns(): array
    {
        return [
            'id' => ['type' => 'char', 'length' => 36],
            'flag_key' => ['type' => 'varchar', 'length' => 100, 'unique' => true],
            'name' => ['type' => 'varchar', 'length' => 255],
            'description' => ['type' => 'text', 'nullable' => true],
            'flag_type' => ['type' => 'varchar', 'length' => 20],
            'is_enabled' => ['type' => 'boolean'],
            'environment' => ['type' => 'varchar', 'length' => 20],
            'targeting_rules' => ['type' => 'json', 'nullable' => true],
            'default_value' => ['type' => 'json', 'nullable' => true],
            'percentage_rollout' => ['type' => 'int', 'nullable' => true],
            'user_segments' => ['type' => 'json', 'nullable' => true],
            'metadata' => ['type' => 'json', 'nullable' => true],
            'created_at' => ['type' => 'datetime'],
            'updated_at' => ['type' => 'datetime'],
        ];
    }

    public static function getConfigSchema(): array
    {
        return [
            'type' => 'boolean',
            'string' => ['type' => 'string'],
            'number' => ['type' => 'number'],
            'json' => ['type' => 'object'],
        ];
    }
}
