<?php

declare(strict_types=1);

namespace App\Infrastructure\Logging\Aggregation;

/**
 * Log aggregation service schema for audit events.
 * This schema is duplicated from:
 * - Database audit_logs table
 * - Doctrine entity: AuditLog
 * - Event store schema
 * - Compliance reporting requirements
 */
class AuditLogAggregationSchema
{
    /**
     * Aggregation pipeline configuration for Elasticsearch.
     * This defines how audit logs are indexed and aggregated.
     */
    public static function getElasticsearchConfig(): array
    {
        return [
            'index_patterns' => ['audit-logs-*'],
            'settings' => [
                'number_of_shards' => 5,
                'number_of_replicas' => 1,
                'index.lifecycle.name' => 'audit-logs-policy',
                'index.lifecycle.rollover_alias' => 'audit-logs',
            ],
            'mappings' => [
                'properties' => [
                    'id' => ['type' => 'keyword'],
                    'event_type' => ['type' => 'keyword'],
                    'event_type_path' => ['type' => 'keyword'],
                    'entity_type' => ['type' => 'keyword'],
                    'entity_id' => ['type' => 'keyword'],
                    'actor_id' => ['type' => 'keyword'],
                    'actor_type' => ['type' => 'keyword'],
                    'actor_ip_address' => ['type' => 'ip'],
                    'occurred_at' => ['type' => 'date'],
                    'recorded_at' => ['type' => 'date'],
                    'correlation_id' => ['type' => 'keyword'],
                    'causation_id' => ['type' => 'keyword'],
                    'event_data_size_bytes' => ['type' => 'integer'],
                    'metadata' => [
                        'type' => 'object',
                        'enabled' => true,
                        'properties' => [
                            'user_agent' => ['type' => 'text'],
                            'request_id' => ['type' => 'keyword'],
                            'session_id' => ['type' => 'keyword'],
                            'location_country' => ['type' => 'keyword'],
                            'location_city' => ['type' => 'keyword'],
                        ],
                    ],
                    'tags' => ['type' => 'keyword'],
                    'severity' => ['type' => 'keyword'],
                    'team' => ['type' => 'keyword'],
                    'application' => ['type' => 'keyword'],
                ],
            ],
        ];
    }

    /**
     * Predefined aggregations for audit log analysis.
     */
    public static function getAggregationQueries(): array
    {
        return [
            'events_by_type' => [
                'terms' => [
                    'field' => 'event_type',
                    'size' => 50,
                ],
            ],
            'events_by_entity' => [
                'composite' => [
                    'sources' => [
                        ['entity_type' => ['terms' => ['field' => 'entity_type']]],
                        ['entity_id' => ['terms' => ['field' => 'entity_id']]],
                    ],
                    'size' => 1000,
                ],
            ],
            'events_by_actor' => [
                'terms' => [
                    'field' => 'actor_id',
                    'size' => 100,
                ],
            ],
            'events_over_time' => [
                'date_histogram' => [
                    'field' => 'occurred_at',
                    'calendar_interval' => 'day',
                ],
            ],
            'events_by_severity' => [
                'terms' => [
                    'field' => 'severity',
                ],
            ],
            'high_volume_actors' => [
                'terms' => [
                    'field' => 'actor_id',
                    'size' => 20,
                    'order' => ['_count' => 'desc'],
                ],
                'aggs' => [
                    'event_types' => [
                        'terms' => [
                            'field' => 'event_type',
                            'size' => 10,
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Retention policy configuration.
     */
    public static function getRetentionPolicy(): array
    {
        return [
            'hot' => [
                'min_age' => '0ms',
                'actions' => [
                    'rollover' => [
                        'max_primary_shard_size' => '50GB',
                        'max_age' => '7d',
                    ],
                ],
            ],
            'warm' => [
                'min_age' => '30d',
                'actions' => [
                    'shrink' => [
                        'number_of_shards' => 1,
                    ],
                    'forcemerge' => [
                        'max_num_segments' => 1,
                    ],
                ],
            ],
            'cold' => [
                'min_age' => '90d',
                'actions' => [
                    'freeze' => [],
                ],
            ],
            'delete' => [
                'min_age' => '365d',
                'actions' => [
                    'delete' => [],
                ],
            ],
        ];
    }
}
