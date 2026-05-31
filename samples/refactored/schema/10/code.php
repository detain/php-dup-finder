<?php

declare(strict_types=1);

namespace App\Domain\Shared\Schema;

/**
 * Centralized Subscription schema registry.
 * Single source of truth for all subscription schema definitions.
 */
final class SubscriptionSchemaRegistry
{
    public const PLAN_TABLE = 'subscription_plans';
    public const SUBSCRIPTION_TABLE = 'subscriptions';
    public const USAGE_TABLE = 'subscription_usage';

    public static function getPlanColumns(): array
    {
        return [
            'id' => ['type' => 'char', 'length' => 36],
            'slug' => ['type' => 'varchar', 'length' => 100, 'unique' => true],
            'name' => ['type' => 'varchar', 'length' => 255],
            'description' => ['type' => 'text', 'nullable' => true],
            'price' => ['type' => 'decimal', 'precision' => 10, 'scale' => 2],
            'currency' => ['type' => 'char', 'length' => 3],
            'billing_interval' => ['type' => 'varchar', 'length' => 20],
            'trial_days' => ['type' => 'int', 'nullable' => true],
            'grace_period_days' => ['type' => 'int', 'nullable' => true],
            'tier' => ['type' => 'varchar', 'length' => 20],
            'features' => ['type' => 'json'],
            'usage_limits' => ['type' => 'json', 'nullable' => true],
            'is_active' => ['type' => 'boolean'],
            'is_public' => ['type' => 'boolean'],
            'max_users' => ['type' => 'int', 'nullable' => true],
            'max_storage_gb' => ['type' => 'int', 'nullable' => true],
            'max_api_calls' => ['type' => 'int', 'nullable' => true],
            'created_at' => ['type' => 'datetime'],
            'updated_at' => ['type' => 'datetime'],
        ];
    }

    public static function getBillingIntervals(): array
    {
        return ['day', 'week', 'month', 'year'];
    }

    public static function getTiers(): array
    {
        return ['free', 'starter', 'standard', 'professional', 'enterprise'];
    }
}
