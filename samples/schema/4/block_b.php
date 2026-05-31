<?php

declare(strict_types=1);

namespace App\Infrastructure\EventStore;

/**
 * Event store schema for domain events.
 * This schema is duplicated from:
 * - Database audit_logs table
 * - Doctrine entity: AuditLog
 * - Log aggregation service
 * - Compliance reporting
 */
class EventStoreSchema
{
    public const TABLE_NAME = 'event_store';

    /**
     * Event store table DDL.
     * Mirrors audit_logs table with additional event sourcing fields.
     */
    public static function getCreateTableSQL(): string
    {
        return <<<SQL
CREATE TABLE event_store (
    id CHAR(36) NOT NULL PRIMARY KEY,
    event_type VARCHAR(255) NOT NULL,
    aggregate_type VARCHAR(255) NOT NULL,
    aggregate_id VARCHAR(36) NOT NULL,
    sequence_number BIGINT NOT NULL AUTO_INCREMENT,
    event_data JSON NOT NULL,
    metadata JSON NULL,
    occurred_at DATETIME(6) NOT NULL,
    recorded_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),

    INDEX idx_event_type (event_type),
    INDEX idx_aggregate (aggregate_type, aggregate_id),
    INDEX idx_occurred_at (occurred_at),
    INDEX idx_sequence (aggregate_type, aggregate_id, sequence_number),
    UNIQUE KEY uk_sequence (aggregate_type, aggregate_id, sequence_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE event_stream_projections (
    id CHAR(36) NOT NULL PRIMARY KEY,
    projection_name VARCHAR(255) NOT NULL,
    stream_type VARCHAR(255) NOT NULL,
    stream_id VARCHAR(36) NOT NULL,
    processed_sequence BIGINT NOT NULL DEFAULT 0,
    last_processed_at DATETIME(6) NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'running',
    error_message TEXT NULL,
    checkpoint_data JSON NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),

    INDEX idx_projection (projection_name),
    INDEX idx_stream (stream_type, stream_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;
    }

    /**
     * Event data schema for serialization.
     * All domain events must conform to this structure.
     */
    public static function getEventDataSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['eventType', 'aggregateId', 'occurredAt'],
            'properties' => [
                'eventType' => [
                    'type' => 'string',
                    'description' => 'Fully qualified event class name',
                    'example' => 'App\Domain\Order\Event\OrderPlaced',
                ],
                'aggregateId' => [
                    'type' => 'string',
                    'description' => 'ID of the aggregate that emitted this event',
                ],
                'aggregateType' => [
                    'type' => 'string',
                    'description' => 'Type of aggregate (e.g., Order, User)',
                ],
                'occurredAt' => [
                    'type' => 'string',
                    'format' => 'date-time',
                    'description' => 'When the event occurred',
                ],
                'payload' => [
                    'type' => 'object',
                    'description' => 'Event-specific data',
                ],
                'metadata' => [
                    'type' => 'object',
                    'properties' => [
                        'correlationId' => ['type' => 'string'],
                        'causationId' => ['type' => 'string'],
                        'userId' => ['type' => 'string'],
                        'ipAddress' => ['type' => 'string'],
                        'userAgent' => ['type' => 'string'],
                    ],
                ],
            ],
        ];
    }

    /**
     * Event sourcing metadata schema.
     */
    public static function getMetadataSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'correlationId' => [
                    'type' => 'string',
                    'description' => 'Links related events across services',
                ],
                'causationId' => [
                    'type' => 'string',
                    'description' => 'ID of the command or event that caused this',
                ],
                'userId' => [
                    'type' => 'string',
                    'description' => 'User who triggered this event',
                ],
                'ipAddress' => [
                    'type' => 'string',
                    'description' => 'IP address of the request',
                ],
                'userAgent' => [
                    'type' => 'string',
                    'description' => 'Browser/client user agent',
                ],
                'serviceVersion' => [
                    'type' => 'string',
                    'description' => 'Version of the service that created this event',
                ],
                'environment' => [
                    'type' => 'string',
                    'enum' => ['development', 'staging', 'production'],
                ],
            ],
        ];
    }
}
