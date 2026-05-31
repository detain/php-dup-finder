<?php

declare(strict_types=1);

namespace App\Api\Schema;

/**
 * OpenAPI schema for feature flag management API.
 * This API schema is duplicated from:
 * - Doctrine entity: FeatureFlag
 * - Database table: feature_flags
 * - Configuration files
 * - External feature flag service
 */
class FeatureFlagApiSchema
{
    /**
     * OpenAPI schema for feature flag creation/update request.
     */
    public static function getRequestSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['flag_key', 'name', 'type'],
            'properties' => [
                'flag_key' => [
                    'type' => 'string',
                    'pattern' => '^[a-z][a-z0-9_]*$',
                    'maxLength' => 100,
                    'description' => 'Unique flag identifier (kebab-case)',
                    'example' => 'new-checkout-flow',
                ],
                'name' => [
                    'type' => 'string',
                    'maxLength' => 255,
                    'description' => 'Human-readable flag name',
                    'example' => 'New Checkout Flow',
                ],
                'description' => [
                    'type' => 'string',
                    'maxLength' => 1000,
                    'description' => 'Detailed description of the feature flag',
                ],
                'type' => [
                    'type' => 'string',
                    'enum' => ['boolean', 'string', 'number', 'json', 'percentage'],
                    'description' => 'Type of the flag value',
                ],
                'is_enabled' => [
                    'type' => 'boolean',
                    'description' => 'Whether the flag is enabled globally',
                ],
                'environment' => [
                    'type' => 'string',
                    'enum' => ['development', 'staging', 'production'],
                    'description' => 'Target environment',
                ],
                'default_value' => [
                    'description' => 'Default value when no rules match',
                ],
                'percentage_rollout' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'maximum' => 100,
                    'description' => 'Percentage of users to enable the flag for',
                ],
                'targeting_rules' => [
                    'type' => 'array',
                    'description' => 'User-targeting rules',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'attribute' => ['type' => 'string'],
                            'operator' => [
                                'type' => 'string',
                                'enum' => ['eq', 'neq', 'in', 'not_in', 'gt', 'gte', 'lt', 'lte'],
                            ],
                            'value' => [],
                            'rollout_percentage' => ['type' => 'integer'],
                        ],
                    ],
                ],
                'user_segments' => [
                    'type' => 'array',
                    'description' => 'User segments to target',
                    'items' => ['type' => 'string'],
                ],
                'metadata' => [
                    'type' => 'object',
                    'description' => 'Additional metadata',
                ],
            ],
        ];
    }

    /**
     * OpenAPI schema for feature flag response.
     */
    public static function getResponseSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'string'],
                'flag_key' => ['type' => 'string'],
                'name' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'type' => ['type' => 'string'],
                'is_enabled' => ['type' => 'boolean'],
                'environment' => ['type' => 'string'],
                'default_value' => [],
                'percentage_rollout' => ['type' => 'integer'],
                'targeting_rules' => ['type' => 'array'],
                'user_segments' => ['type' => 'array'],
                'metadata' => ['type' => 'object'],
                'created_at' => ['type' => 'string', 'format' => 'date-time'],
                'updated_at' => ['type' => 'string', 'format' => 'date-time'],
                'created_by' => ['type' => 'string'],
            ],
        ];
    }
}
