<?php

declare(strict_types=1);

namespace App\Infrastructure\Billing\Database;

/**
 * Database schema for subscription plans.
 * This schema is duplicated from:
 * - Doctrine entity: SubscriptionPlan
 * - Payment gateway schemas
 * - API schemas
 * - Billing service configurations
 */
class SubscriptionPlanDatabaseSchema
{
    public const TABLE_PLANS = 'subscription_plans';
    public const TABLE_SUBSCRIPTIONS = 'subscriptions';
    public const TABLE_SUBSCRIPTION_USAGE = 'subscription_usage';

    public static function getCreateTableSQL(): string
    {
        return <<<SQL
CREATE TABLE subscription_plans (
    id CHAR(36) NOT NULL PRIMARY KEY,
    slug VARCHAR(100) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    billing_interval VARCHAR(20) NOT NULL,
    price DECIMAL(10, 2) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'USD',
    trial_days INT NULL,
    grace_period_days INT NULL,
    tier VARCHAR(20) NOT NULL DEFAULT 'standard',
    features JSON NOT NULL,
    usage_limits JSON NULL,
    constraints JSON NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    is_public BOOLEAN NOT NULL DEFAULT TRUE,
    max_users INT NULL,
    max_storage_gb INT NULL,
    max_api_calls INT NULL,
    available_from DATETIME NULL,
    available_until DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_slug (slug),
    INDEX idx_tier (tier),
    INDEX idx_is_active (is_active),
    INDEX idx_available (available_from, available_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE subscriptions (
    id CHAR(36) NOT NULL PRIMARY KEY,
    customer_id CHAR(36) NOT NULL,
    plan_id CHAR(36) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    current_period_start DATETIME NOT NULL,
    current_period_end DATETIME NOT NULL,
    trial_start DATETIME NULL,
    trial_end DATETIME NULL,
    canceled_at DATETIME NULL,
    cancel_at_period_end BOOLEAN NOT NULL DEFAULT FALSE,
    external_subscription_id VARCHAR(255) NULL,
    external_customer_id VARCHAR(255) NULL,
    metadata JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_customer_id (customer_id),
    INDEX idx_plan_id (plan_id),
    INDEX idx_status (status),
    INDEX idx_external_subscription_id (external_subscription_id),
    INDEX idx_current_period_end (current_period_end),
    FOREIGN KEY (plan_id) REFERENCES subscription_plans(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE subscription_usage (
    id CHAR(36) NOT NULL PRIMARY KEY,
    subscription_id CHAR(36) NOT NULL,
    metric VARCHAR(100) NOT NULL,
    current_value BIGINT NOT NULL DEFAULT 0,
    limit_value BIGINT NOT NULL,
    reset_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_subscription_id (subscription_id),
    INDEX idx_metric (metric),
    UNIQUE KEY uk_subscription_metric (subscription_id, metric),
    FOREIGN KEY (subscription_id) REFERENCES subscriptions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE plan_changes (
    id CHAR(36) NOT NULL PRIMARY KEY,
    subscription_id CHAR(36) NOT NULL,
    from_plan_id CHAR(36) NULL,
    to_plan_id CHAR(36) NOT NULL,
    change_type VARCHAR(20) NOT NULL,
    effective_date DATETIME NOT NULL,
    prorated_amount DECIMAL(12, 2) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'scheduled',
    scheduled_for DATETIME NULL,
    processed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_subscription_id (subscription_id),
    INDEX idx_status (status),
    INDEX idx_scheduled_for (scheduled_for),
    FOREIGN KEY (subscription_id) REFERENCES subscriptions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;
    }

    /**
     * Subscription status values.
     */
    public static function getSubscriptionStatuses(): array
    {
        return [
            'pending' => 'Subscription created but not yet active',
            'active' => 'Subscription is active and billing',
            'trialing' => 'Subscription is in trial period',
            'past_due' => 'Payment is overdue',
            'canceled' => 'Subscription has been canceled',
            'expired' => 'Subscription has expired',
            'paused' => 'Subscription is paused',
        ];
    }

    /**
     * Plan change types.
     */
    public static function getChangeTypes(): array
    {
        return [
            'upgrade' => 'Moving to a higher tier plan',
            'downgrade' => 'Moving to a lower tier plan',
            'cancellation' => 'Canceling the subscription',
            'reactivation' => 'Reactivating a canceled subscription',
            'renewal' => 'Renewing an expiring subscription',
        ];
    }
}
