<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications\Logging;

use App\Domain\Notifications\Entity\NotificationTemplate;

/**
 * Notification delivery logging schema.
 * This schema is duplicated from:
 * - Doctrine entity: NotificationTemplate
 * - Database table: notification_templates
 * - Channel configurations
 * - Template system
 */
class NotificationDeliveryLogSchema
{
    public const TABLE_NAME = 'notification_delivery_logs';

    /**
     * Delivery log table schema for tracking all notification deliveries.
     */
    public static function getCreateTableSQL(): string
    {
        return <<<SQL
CREATE TABLE notification_delivery_logs (
    id CHAR(36) NOT NULL PRIMARY KEY,
    notification_id CHAR(36) NOT NULL,
    template_id CHAR(36) NOT NULL,
    template_key VARCHAR(100) NOT NULL,
    channel VARCHAR(20) NOT NULL,
    recipient VARCHAR(255) NOT NULL,
    subject VARCHAR(255) NULL,
    status VARCHAR(20) NOT NULL,
    priority VARCHAR(20) NOT NULL DEFAULT 'normal',
    error_code VARCHAR(50) NULL,
    error_message TEXT NULL,
    attempt_count INT NOT NULL DEFAULT 1,
    first_attempt_at DATETIME NOT NULL,
    last_attempt_at DATETIME NOT NULL,
    delivered_at DATETIME NULL,
    opened_at DATETIME NULL,
    clicked_at DATETIME NULL,
    bounced_at DATETIME NULL,
    unsubscribed_at DATETIME NULL,
    metadata JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_notification_id (notification_id),
    INDEX idx_template_id (template_id),
    INDEX idx_template_key (template_key),
    INDEX idx_channel (channel),
    INDEX idx_status (status),
    INDEX idx_recipient (recipient),
    INDEX idx_first_attempt_at (first_attempt_at),
    INDEX idx_last_attempt_at (last_attempt_at),
    INDEX idx_delivered_at (delivered_at),
    INDEX idx_metadata (metadata(255))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notification_delivery_events (
    id CHAR(36) NOT NULL PRIMARY KEY,
    delivery_log_id CHAR(36) NOT NULL,
    event_type VARCHAR(50) NOT NULL,
    event_data JSON NULL,
    occurred_at DATETIME(6) NOT NULL,

    INDEX idx_delivery_log_id (delivery_log_id),
    INDEX idx_event_type (event_type),
    INDEX idx_occurred_at (occurred_at),
    FOREIGN KEY (delivery_log_id) REFERENCES notification_delivery_logs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;
    }

    /**
     * Delivery event types.
     */
    public static function getEventTypes(): array
    {
        return [
            'queued' => 'Notification queued for delivery',
            'processing' => 'Notification being processed',
            'sent' => 'Notification sent to provider',
            'delivered' => 'Notification delivered to recipient',
            'opened' => 'Notification opened by recipient',
            'clicked' => 'Link in notification clicked',
            'bounced' => 'Notification bounced',
            'soft_bounced' => 'Notification soft bounced (temporary failure)',
            'hard_bounced' => 'Notification hard bounced (permanent failure)',
            'unsubscribed' => 'Recipient unsubscribed',
            'complained' => 'Recipient marked as spam',
            'failed' => 'Delivery failed after all retries',
        ];
    }

    /**
     * Status values for delivery logs.
     */
    public static function getStatusValues(): array
    {
        return [
            'pending' => 'Waiting to be processed',
            'queued' => 'Queued for delivery',
            'processing' => 'Currently being processed',
            'sent' => 'Sent to provider',
            'delivered' => 'Successfully delivered',
            'failed' => 'Failed after all retries',
            'cancelled' => 'Cancelled before delivery',
            'skipped' => 'Skipped (e.g., unsubscribed)',
        ];
    }
}
