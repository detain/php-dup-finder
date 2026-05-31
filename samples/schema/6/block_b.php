<?php

declare(strict_types=1);

namespace App\Infrastructure\Config;

/**
 * Feature flag configuration schema.
 * This configuration is duplicated from:
 * - Doctrine entity: FeatureFlag
 * - Database table: feature_flags
 * - External feature flag service
 * - Admin dashboard schema
 */
class FeatureFlagConfigSchema
{
    public const CONFIG_FILE_PATH = 'config/feature_flags.php';

    /**
     * Configuration array schema for feature flags.
     */
    public static function getConfigSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => [
                'type' => 'object',
                'required' => ['key', 'type'],
                'properties' => [
                    'key' => ['type' => 'string'],
                    'name' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                    'type' => [
                        'type' => 'string',
                        'enum' => ['boolean', 'string', 'number', 'json', 'percentage'],
                    ],
                    'enabled' => ['type' => 'boolean'],
                    'default_value' => ['type' => ['boolean', 'string', 'number', 'null']],
                    'environments' => [
                        'type' => 'object',
                        'additionalProperties' => [
                            'type' => 'object',
                            'properties' => [
                                'enabled' => ['type' => 'boolean'],
                                'percentage_rollout' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                                'targeting_rules' => ['type' => 'array'],
                                'user_segments' => ['type' => 'array'],
                            ],
                        ],
                    ],
                    'targeting_rules' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'attribute' => ['type' => 'string'],
                                'operator' => [
                                    'type' => 'string',
                                    'enum' => ['eq', 'neq', 'in', 'not_in', 'gt', 'gte', 'lt', 'lte', 'contains', 'starts_with'],
                                ],
                                'value' => ['type' => ['string', 'number', 'boolean', 'array']],
                            ],
                        ],
                    ],
                    'metadata' => ['type' => 'object'],
                ],
            ],
        ];
    }

    /**
     * Example configuration structure.
     */
    public static function getExampleConfig(): array
    {
        return [
            'new_checkout_flow' => [
                'key' => 'new_checkout_flow',
                'name' => 'New Checkout Flow',
                'description' => 'Enable the new redesigned checkout experience',
                'type' => 'boolean',
                'enabled' => false,
                'default_value' => false,
                'environments' => [
                    'development' => ['enabled' => true, 'percentage_rollout' => 100],
                    'staging' => ['enabled' => true, 'percentage_rollout' => 50],
                    'production' => ['enabled' => true, 'percentage_rollout' => 10],
                ],
                'targeting_rules' => [
                    [
                        'attribute' => 'user_tier',
                        'operator' => 'in',
                        'value' => ['premium', 'enterprise'],
                    ],
                ],
            ],
            'max_items_per_order' => [
                'key' => 'max_items_per_order',
                'name' => 'Maximum Items Per Order',
                'description' => 'Limit on maximum items allowed in a single order',
                'type' => 'number',
                'enabled' => true,
                'default_value' => 100,
                'environments' => [
                    'production' => ['enabled' => true, 'default_value' => 50],
                ],
            ],
            'enabled_payment_methods' => [
                'key' => 'enabled_payment_methods',
                'name' => 'Enabled Payment Methods',
                'description' => 'List of payment methods to display',
                'type' => 'json',
                'enabled' => true,
                'default_value' => ['credit_card', 'paypal'],
                'environments' => [
                    'production' => [
                        'enabled' => true,
                        'default_value' => ['credit_card', 'debit_card', 'paypal', 'apple_pay'],
                    ],
                ],
            ],
        ];
    }
}
