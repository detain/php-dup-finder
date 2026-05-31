<?php

declare(strict_types=1);

namespace App\Domain\Shared\Schema;

/**
 * Centralized Notification schema registry.
 * Single source of truth for all notification schema definitions.
 */
final class NotificationSchemaRegistry
{
    public const TEMPLATE_TABLE = 'notification_templates';
    public const DELIVERY_LOG_TABLE = 'notification_delivery_logs';
    public const EVENT_TABLE = 'notification_delivery_events';

    public static function getTemplateColumns(): array
    {
        return [
            'id' => ['type' => 'char', 'length' => 36],
            'template_key' => ['type' => 'varchar', 'length' => 100, 'unique' => true],
            'channel' => ['type' => 'varchar', 'length' => 20],
            'name' => ['type' => 'varchar', 'length' => 100],
            'subject' => ['type' => 'varchar', 'length' => 255],
            'body' => ['type' => 'text'],
            'html_body' => ['type' => 'text', 'nullable' => true],
            'variables' => ['type' => 'json', 'nullable' => true],
            'is_active' => ['type' => 'boolean'],
            'priority' => ['type' => 'varchar', 'length' => 20],
            'max_retries' => ['type' => 'int'],
            'created_at' => ['type' => 'datetime'],
            'updated_at' => ['type' => 'datetime'],
        ];
    }

    public static function getDeliveryLogColumns(): array
    {
        return [
            'id' => ['type' => 'char', 'length' => 36],
            'notification_id' => ['type' => 'char', 'length' => 36],
            'template_id' => ['type' => 'char', 'length' => 36],
            'channel' => ['type' => 'varchar', 'length' => 20],
            'recipient' => ['type' => 'varchar', 'length' => 255],
            'status' => ['type' => 'varchar', 'length' => 20],
            'error_message' => ['type' => 'text', 'nullable' => true],
            'attempt_count' => ['type' => 'int'],
            'created_at' => ['type' => 'datetime'],
        ];
    }
}
