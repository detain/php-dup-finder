<?php

declare(strict_types=1);

namespace App\Infrastructure\Billing\Gateway;

/**
 * Payment gateway subscription plan schemas.
 * These schemas are duplicated from:
 * - Doctrine entity: SubscriptionPlan
 * - Database table: subscription_plans
 * - API documentation
 * - Billing service configurations
 */
class SubscriptionPlanGatewaySchema
{
    /**
     * Stripe subscription plan creation schema.
     */
    public static function getStripePlanSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['product_data', 'unit_amount', 'currency', 'recurring'],
            'properties' => [
                'product_data' => [
                    'type' => 'object',
                    'required' => ['name'],
                    'properties' => [
                        'name' => ['type' => 'string'],
                        'description' => ['type' => 'string'],
                        'metadata' => ['type' => 'object'],
                    ],
                ],
                'unit_amount' => [
                    'type' => 'integer',
                    'description' => 'Amount in smallest currency unit (cents)',
                ],
                'currency' => [
                    'type' => 'string',
                    'pattern' => '^[a-z]{3}$',
                ],
                'recurring' => [
                    'type' => 'object',
                    'required' => ['interval'],
                    'properties' => [
                        'interval' => [
                            'type' => 'string',
                            'enum' => ['day', 'week', 'month', 'year'],
                        ],
                        'interval_count' => [
                            'type' => 'integer',
                            'description' => 'Number of intervals (e.g., 3 months)',
                        ],
                        'usage_type' => [
                            'type' => 'string',
                            'enum' => ['licensed', 'metered'],
                            'default' => 'licensed',
                        ],
                    ],
                ],
                'metadata' => [
                    'type' => 'object',
                    'additionalProperties' => 'string',
                ],
            ],
        ];
    }

    /**
     * PayPal subscription plan schema.
     */
    public static function getPayPalPlanSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['product_id', 'name', 'billing_cycles', 'payment_preferences'],
            'properties' => [
                'product_id' => [
                    'type' => 'string',
                    'description' => 'PayPal product ID',
                ],
                'name' => [
                    'type' => 'string',
                    'maxLength' => 128,
                ],
                'description' => [
                    'type' => 'string',
                    'maxLength' => 256,
                ],
                'status' => [
                    'type' => 'string',
                    'enum' => ['ACTIVE', 'INACTIVE', 'CREATED'],
                ],
                'billing_cycles' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'frequency' => [
                                'type' => 'object',
                                'properties' => [
                                    'interval_unit' => ['type' => 'string', 'enum' => ['DAY', 'WEEK', 'MONTH', 'YEAR']],
                                    'interval_count' => ['type' => 'integer'],
                                ],
                            ],
                            'tenure_type' => ['type' => 'string', 'enum' => ['REGULAR', 'TRIAL', 'INFINITE']],
                            'sequence' => ['type' => 'integer'],
                            'total_cycles' => ['type' => 'integer'],
                        ],
                    ],
                ],
                'payment_preferences' => [
                    'type' => 'object',
                    'properties' => [
                        'auto_bill_outstanding' => ['type' => 'boolean'],
                        'setup_fee' => ['type' => 'object'],
                        'setup_fee_failure_action' => ['type' => 'string', 'enum' => ['CONTINUE', 'CANCEL']],
                        'payment_failure_threshold' => ['type' => 'integer'],
                    ],
                ],
            ],
        ];
    }

    /**
     * Generic subscription plan API schema.
     */
    public static function getApiSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['slug', 'name', 'price', 'billing_interval'],
            'properties' => [
                'id' => ['type' => 'string'],
                'slug' => [
                    'type' => 'string',
                    'pattern' => '^[a-z0-9-]+$',
                ],
                'name' => [
                    'type' => 'string',
                    'maxLength' => 255,
                ],
                'description' => [
                    'type' => 'string',
                    'maxLength' => 1000,
                ],
                'price' => [
                    'type' => 'number',
                    'minimum' => 0,
                ],
                'currency' => [
                    'type' => 'string',
                    'pattern' => '^[A-Z]{3}$',
                    'default' => 'USD',
                ],
                'billing_interval' => [
                    'type' => 'string',
                    'enum' => ['day', 'week', 'month', 'year'],
                ],
                'trial_days' => [
                    'type' => 'integer',
                    'minimum' => 0,
                ],
                'grace_period_days' => [
                    'type' => 'integer',
                    'minimum' => 0,
                ],
                'tier' => [
                    'type' => 'string',
                    'enum' => ['free', 'starter', 'standard', 'professional', 'enterprise'],
                ],
                'features' => [
                    'type' => 'object',
                    'additionalProperties' => ['type' => 'boolean'],
                ],
                'usage_limits' => [
                    'type' => 'object',
                    'properties' => [
                        'max_users' => ['type' => 'integer'],
                        'max_storage_gb' => ['type' => 'integer'],
                        'max_api_calls' => ['type' => 'integer'],
                        'max_projects' => ['type' => 'integer'],
                    ],
                ],
                'is_active' => ['type' => 'boolean'],
                'is_public' => ['type' => 'boolean'],
                'max_users' => ['type' => 'integer', 'nullable' => true],
                'max_storage_gb' => ['type' => 'integer', 'nullable' => true],
                'max_api_calls' => ['type' => 'integer', 'nullable' => true],
                'available_from' => ['type' => 'string', 'format' => 'date-time'],
                'available_until' => ['type' => 'string', 'format' => 'date-time'],
            ],
        ];
    }
}
