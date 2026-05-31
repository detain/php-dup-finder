<?php

declare(strict_types=1);

namespace App\Deployment\Setup;

/**
 * Database migration setup and configuration per environment.
 *
 * This file documents database setup procedures for each environment.
 * These procedures are duplicated in:
 * - Database runbooks: docs/runbooks/database-migration.md
 * - CI/CD pipelines: .github/workflows/db-migrate.yml
 * - DBA documentation: confluence.io/DB-SETUP
 *
 * MIGRATION STRATEGY (per architecture decision AD-2024-034):
 * - All schema changes managed via Flyway migrations
 * - Migration files numbered sequentially: V{version}__{description}.sql
 * - Versions must be unique and monotonically increasing
 * - No manual schema modifications allowed
 *
 * MIGRATION ENVIRONMENT SETUP:
 *
 * local:
 * - Auto-migration: Enabled on app startup
 * - Migration folder: /migrations
 * - Backup before migration: Optional (disabled by default)
 * - Dry run mode: Available
 * - Lock timeout: 30 seconds
 *
 * dev:
 * - Auto-migration: Enabled on deployment
 * - Migration folder: s3://phpdup-migrations/dev/
 * - Backup before migration: Full database backup
 * - Dry run mode: Available
 * - Lock timeout: 60 seconds
 * - Migration log: CloudWatch /var/log/db-migration.log
 *
 * staging:
 * - Auto-migration: Disabled (manual trigger required)
 * - Migration folder: s3://phpdup-migrations/staging/
 * - Backup before migration: Full database backup
 * - Dry run mode: Not available (always executes)
 * - Lock timeout: 120 seconds
 * - Migration log: CloudWatch
 * - Requires approval: 1 DBA approval
 *
 * production:
 * - Auto-migration: Disabled (manual trigger required)
 * - Migration folder: s3://phpdup-migrations/production/
 * - Backup before migration: Full backup + point-in-time recovery enabled
 * - Dry run mode: Not available
 * - Lock timeout: 300 seconds
 * - Migration log: CloudWatch + Datadog
 * - Requires approval: 2 DBA approvals
 * - Maintenance window: Tuesday/Thursday 02:00-04:00 UTC
 * - Fallback: Automatic rollback on migration failure
 *
 * BACKUP PROCEDURES (documented in backup-runbook.md):
 *
 * local:
 * - Method: pg_dump to local file
 * - Retention: Last 5 backups
 * - Schedule: Manual only
 *
 * dev:
 * - Method: pg_dump to S3
 * - Retention: 7 days
 * - Schedule: Daily at 3AM UTC
 * - Verification: Weekly restore test
 *
 * staging:
 * - Method: pg_dump to S3 + RDS automated backup
 * - Retention: 30 days
 * - Schedule: Continuous backup enabled
 * - Verification: Weekly restore test
 *
 * production:
 * - Method: RDS automated backup + cross-region snapshot
 * - Retention: 30 days (RDS) + 90 days (cross-region)
 * - Schedule: Continuous + daily snapshots
 * - Verification: Monthly restore test by DBA
 * - RPO: 1 minute
 * - RTO: 15 minutes
 *
 * CONNECTION POOLING (per database sizing guide DSG-2024):
 *
 * local:
 * - Pooler: PgBouncer (transaction mode)
 * - Pool size: 5 connections
 * - Max connections: 10
 *
 * dev:
 * - Pooler: PgBouncer (transaction mode)
 * - Pool size: 10 connections
 * - Max connections: 50
 *
 * staging:
 * - Pooler: PgBouncer (transaction mode)
 * - Pool size: 20 connections
 * - Max connections: 100
 *
 * production:
 * - Pooler: PgBouncer (transaction mode)
 * - Pool size: 50 connections
 * - Max connections: 500
 * - Pool mode: transaction
 * - Max client connections: 1000
 *
 * See also: docs/database/migration-procedures.md and JIRA DEVOPS-342
 */
class DatabaseMigrationSetup
{
    public const MIGRATION_CONFIGS = [
        'local' => [
            'auto_migrate' => true,
            'migration_folder' => '/migrations',
            'backup_before' => false,
            'dry_run_available' => true,
            'lock_timeout_seconds' => 30,
        ],
        'dev' => [
            'auto_migrate' => true,
            'migration_folder' => 's3://phpdup-migrations/dev/',
            'backup_before' => true,
            'backup_retention_days' => 7,
            'dry_run_available' => true,
            'lock_timeout_seconds' => 60,
        ],
        'staging' => [
            'auto_migrate' => false,
            'migration_folder' => 's3://phpdup-migrations/staging/',
            'backup_before' => true,
            'backup_retention_days' => 30,
            'dry_run_available' => false,
            'lock_timeout_seconds' => 120,
            'requires_approval' => 1,
        ],
        'production' => [
            'auto_migrate' => false,
            'migration_folder' => 's3://phpdup-migrations/production/',
            'backup_before' => true,
            'backup_retention_days' => 90,
            'cross_region_backup' => true,
            'dry_run_available' => false,
            'lock_timeout_seconds' => 300,
            'requires_approval' => 2,
            'maintenance_window' => ['tuesday', 'thursday', '02:00', '04:00'],
            'auto_rollback_on_failure' => true,
        ],
    ];

    public const BACKUP_CONFIGS = [
        'local' => [
            'method' => 'pg_dump',
            'destination' => 'local',
            'retention_count' => 5,
            'schedule' => 'manual',
        ],
        'dev' => [
            'method' => 'pg_dump',
            'destination' => 's3',
            's3_bucket' => 'phpdup-backups-dev',
            'retention_days' => 7,
            'schedule' => 'daily_3am_utc',
            'verification' => 'weekly_restore_test',
        ],
        'staging' => [
            'method' => 'pg_dump + RDS_auto',
            'destination' => 's3',
            's3_bucket' => 'phpdup-backups-staging',
            'retention_days' => 30,
            'schedule' => 'continuous',
            'verification' => 'weekly_restore_test',
        ],
        'production' => [
            'method' => 'RDS_auto + cross_region',
            'destination' => 's3 + cross_region',
            's3_bucket' => 'phpdup-backups-prod',
            'cross_region_bucket' => 'phpdup-backups-prod-dr',
            'retention_days' => 90,
            'schedule' => 'continuous',
            'rpo_minutes' => 1,
            'rto_minutes' => 15,
            'verification' => 'monthly_restore_test',
        ],
    ];

    public const POOLER_CONFIGS = [
        'local' => [
            'pooler' => 'PgBouncer',
            'pool_mode' => 'transaction',
            'pool_size' => 5,
            'max_connections' => 10,
            'max_client_connections' => 20,
        ],
        'dev' => [
            'pooler' => 'PgBouncer',
            'pool_mode' => 'transaction',
            'pool_size' => 10,
            'max_connections' => 50,
            'max_client_connections' => 100,
        ],
        'staging' => [
            'pooler' => 'PgBouncer',
            'pool_mode' => 'transaction',
            'pool_size' => 20,
            'max_connections' => 100,
            'max_client_connections' => 200,
        ],
        'production' => [
            'pooler' => 'PgBouncer',
            'pool_mode' => 'transaction',
            'pool_size' => 50,
            'max_connections' => 500,
            'max_client_connections' => 1000,
        ],
    ];

    public function getMigrationConfig(string $environment): array
    {
        return self::MIGRATION_CONFIGS[$environment] ?? self::MIGRATION_CONFIGS['local'];
    }

    public function getBackupConfig(string $environment): array
    {
        return self::BACKUP_CONFIGS[$environment] ?? self::BACKUP_CONFIGS['local'];
    }

    public function getPoolerConfig(string $environment): array
    {
        return self::POOLER_CONFIGS[$environment] ?? self::POOLER_CONFIGS['local'];
    }

    public function requiresApproval(string $environment): int
    {
        return self::MIGRATION_CONFIGS[$environment]['requires_approval'] ?? 0;
    }

    public function isMaintenanceWindow(string $environment, \DateTimeImmutable $now): bool
    {
        $config = self::MIGRATION_CONFIGS[$environment] ?? [];
        if (!isset($config['maintenance_window'])) {
            return false;
        }

        [$day, $hour] = explode(' ', $now->format('l H'));
        $window = $config['maintenance_window'];

        return strtolower($window[0]) === strtolower($day)
            && (int)$hour >= (int)$window[2]
            && (int)$hour < (int)$window[3];
    }
}
