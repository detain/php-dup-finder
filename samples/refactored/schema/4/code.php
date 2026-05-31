<?php

declare(strict_types=1);

namespace App\Domain\Shared\Schema;

/**
 * Centralized Audit Log schema registry.
 * Single source of truth for all audit log schema definitions.
 */
final class AuditLogSchemaRegistry
{
    public const TABLE_NAME = 'audit_logs';
    public const EVENT_STORE_TABLE = 'event_store';
    public const ELASTICSEARCH_INDEX = 'audit-logs';

    public static function getColumns(): array
    {
        return [
            'id' => ['type' => 'char', 'length' => 36, 'primary' => true],
            'event_type' => ['type' => 'varchar', 'length' => 255, 'index' => true],
            'entity_type' => ['type' => 'varchar', 'length' => 255, 'index' => true],
            'entity_id' => ['type' => 'varchar', 'length' => 36, 'index' => true],
            'actor_id' => ['type' => 'varchar', 'length' => 36, 'nullable' => true, 'index' => true],
            'actor_type' => ['type' => 'varchar', 'length' => 100, 'nullable' => true],
            'actor_ip_address' => ['type' => 'varchar', 'length' => 45, 'nullable' => true],
            'event_data' => ['type' => 'json'],
            'metadata' => ['type' => 'json', 'nullable' => true],
            'occurred_at' => ['type' => 'datetime', 'index' => true],
            'correlation_id' => ['type' => 'varchar', 'length' => 36, 'nullable' => true],
            'causation_id' => ['type' => 'varchar', 'length' => 36, 'nullable' => true],
        ];
    }

    public static function getDDL(): string
    {
        $columns = [];
        foreach (self::getColumns() as $name => $config) {
            $type = match ($config['type']) {
                'char' => "CHAR({$config['length']})",
                'varchar' => "VARCHAR({$config['length']})",
                'datetime' => 'DATETIME',
                'json' => 'JSON',
                default => 'VARCHAR(255)',
            };

            $nullable = ($config['nullable'] ?? false) ? ' NULL' : ' NOT NULL';
            $index = ($config['index'] ?? false) ? ', INDEX idx_' . str_replace('_', '_', $name) . " ({$name})" : '';

            $columns[] = "{$name} {$type}{$nullable}{$index}";
        }

        return "CREATE TABLE " . self::TABLE_NAME . " (\n    " . implode(",\n    ", $columns) . "\n)";
    }
}
