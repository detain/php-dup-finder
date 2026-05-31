<?php

declare(strict_types=1);

namespace App\Infrastructure\Analytics\Pipeline;

/**
 * Data pipeline schema for analytics events.
 * These schemas are duplicated from:
 * - Doctrine entity: AnalyticsEvent
 * - Database table: analytics_events
 * - Event tracking SDK
 * - Data warehouse
 */
class AnalyticsEventPipelineSchema
{
    /**
     * Avro schema for analytics events in the data pipeline.
     */
    public static function getAvroSchema(): array
    {
        return [
            'type' => 'record',
            'name' => 'AnalyticsEvent',
            'namespace' => 'com.example.analytics',
            'fields' => [
                ['name' => 'id', 'type' => 'string'],
                ['name' => 'event_type', 'type' => 'string'],
                ['name' => 'event_category', 'type' => ['null', 'string']],
                ['name' => 'event_label', 'type' => ['null', 'string']],
                ['name' => 'entity_type', 'type' => ['null', 'string']],
                ['name' => 'entity_id', 'type' => ['null', 'string']],
                ['name' => 'user_id', 'type' => ['null', 'string']],
                ['name' => 'session_id', 'type' => 'string'],
                ['name' => 'value', 'type' => ['null', 'double']],
                ['name' => 'properties', 'type' => ['type' => 'map', 'values' => 'string']],
                ['name' => 'user_context', 'type' => [
                    'type' => 'record',
                    'name' => 'UserContext',
                    'fields' => [
                        ['name' => 'browser', 'type' => ['null', 'string']],
                        ['name' => 'os', 'type' => ['null', 'string']],
                        ['name' => 'device_type', 'type' => ['null', 'string']],
                        ['name' => 'country', 'type' => ['null', 'string']],
                        ['name' => 'region', 'type' => ['null', 'string']],
                    ],
                ]],
                ['name' => 'ip_address', 'type' => ['null', 'string']],
                ['name' => 'user_agent', 'type' => ['null', 'string']],
                ['name' => 'referrer', 'type' => ['null', 'string']],
                ['name' => 'page_url', 'type' => ['null', 'string']],
                ['name' => 'page_title', 'type' => ['null', 'string']],
                ['name' => 'utm_source', 'type' => ['null', 'string']],
                ['name' => 'utm_medium', 'type' => ['null', 'string']],
                ['name' => 'utm_campaign', 'type' => ['null', 'string']],
                ['name' => 'occurred_at', 'type' => 'long', 'logicalType' => 'timestamp-millis'],
                ['name' => 'created_at', 'type' => 'long', 'logicalType' => 'timestamp-millis'],
            ],
        ];
    }

    /**
     * Parquet schema for analytics events.
     */
    public static function getParquetSchema(): array
    {
        return [
            'EventType' => 'BYTE_ARRAY',
            'EventCategory' => 'BYTE_ARRAY',
            'EventLabel' => 'BYTE_ARRAY',
            'EntityType' => 'BYTE_ARRAY',
            'EntityId' => 'BYTE_ARRAY',
            'UserId' => 'BYTE_ARRAY',
            'SessionId' => 'BYTE_ARRAY',
            'Value' => 'DOUBLE',
            'Properties' => 'BYTE_ARRAY',
            'IpAddress' => 'BYTE_ARRAY',
            'UserAgent' => 'BYTE_ARRAY',
            'Referrer' => 'BYTE_ARRAY',
            'PageUrl' => 'BYTE_ARRAY',
            'PageTitle' => 'BYTE_ARRAY',
            'UtmSource' => 'BYTE_ARRAY',
            'UtmMedium' => 'BYTE_ARRAY',
            'UtmCampaign' => 'BYTE_ARRAY',
            'OccurredAt' => 'INT96',
            'CreatedAt' => 'INT96',
        ];
    }

    /**
     * Kafka message schema for real-time event streaming.
     */
    public static function getKafkaSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['id', 'event_type', 'session_id', 'occurred_at'],
            'properties' => [
                'id' => ['type' => 'string', 'description' => 'Unique event ID'],
                'event_type' => [
                    'type' => 'string',
                    'enum' => [
                        'page_view', 'click', 'search', 'add_to_cart', 'remove_from_cart',
                        'checkout_started', 'purchase_completed', 'signup_completed',
                        'login', 'logout', 'form_submitted', 'video_played', 'error_occurred',
                    ],
                ],
                'event_category' => ['type' => 'string'],
                'event_label' => ['type' => 'string'],
                'entity' => [
                    'type' => 'object',
                    'properties' => [
                        'type' => ['type' => 'string'],
                        'id' => ['type' => 'string'],
                    ],
                ],
                'user_id' => ['type' => 'string'],
                'session_id' => ['type' => 'string'],
                'value' => ['type' => 'number'],
                'properties' => ['type' => 'object'],
                'context' => [
                    'type' => 'object',
                    'properties' => [
                        'ip' => ['type' => 'string'],
                        'user_agent' => ['type' => 'string'],
                        'referrer' => ['type' => 'string'],
                        'page' => [
                            'type' => 'object',
                            'properties' => [
                                'url' => ['type' => 'string'],
                                'title' => ['type' => 'string'],
                            ],
                        ],
                    ],
                ],
                'occurred_at' => ['type' => 'integer', 'description' => 'Unix timestamp in milliseconds'],
            ],
        ];
    }
}
