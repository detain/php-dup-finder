<?php

declare(strict_types=1);

namespace App\Domain\Shared\Schema;

/**
 * Centralized Analytics schema registry.
 * Single source of truth for all analytics schema definitions.
 */
final class AnalyticsSchemaRegistry
{
    public const TABLE_NAME = 'analytics_events';
    public const FACT_TABLE = 'fact_events';

    public static function getColumns(): array
    {
        return [
            'id' => ['type' => 'char', 'length' => 36],
            'event_type' => ['type' => 'varchar', 'length' => 100],
            'event_category' => ['type' => 'varchar', 'length' => 100, 'nullable' => true],
            'event_label' => ['type' => 'varchar', 'length' => 100, 'nullable' => true],
            'entity_type' => ['type' => 'varchar', 'length' => 100, 'nullable' => true],
            'entity_id' => ['type' => 'varchar', 'length' => 36, 'nullable' => true],
            'user_id' => ['type' => 'varchar', 'length' => 36, 'nullable' => true],
            'session_id' => ['type' => 'varchar', 'length' => 36],
            'value' => ['type' => 'decimal', 'precision' => 10, 'scale' => 2, 'nullable' => true],
            'properties' => ['type' => 'json'],
            'occurred_at' => ['type' => 'datetime'],
            'created_at' => ['type' => 'datetime'],
        ];
    }

    public static function getEventTypes(): array
    {
        return [
            'page_view', 'click', 'search', 'add_to_cart', 'remove_from_cart',
            'checkout_started', 'purchase_completed', 'signup_completed',
            'login', 'logout', 'form_submitted', 'video_played', 'error_occurred',
        ];
    }
}
