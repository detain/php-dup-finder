<?php
declare(strict_types=1);

namespace Billing\Core\Deployment;

/**
 * Centralized deployment configuration and procedures.
 *
 * All deployment steps are defined here as executable code,
 * ensuring documentation stays synchronized with implementation.
 */
final class DeploymentConfig
{
    public const MAINTENANCE_MODE_TTL = 7200;
    public const BACKUP_RETENTION_DAYS = 30;
    public const DEPLOYMENT_TIMEOUT = 600;

    public static function getPreDeploymentChecks(): array
    {
        return [
            'disk_space_min_gb' => 2,
            'memory_min_mb' => 512,
            'required_services' => ['mysql', 'redis', 'nginx'],
            'check_migrations' => true,
            'backup_required' => true
        ];
    }

    public static function getDeploymentSteps(): array
    {
        return [
            'pre_deployment_checks',
            'enable_maintenance_mode',
            'backup_database',
            'update_code',
            'run_migrations',
            'rebuild_caches',
            'restart_workers',
            'disable_maintenance_mode',
            'verify_deployment'
        ];
    }

    public static function getRollbackSteps(): array
    {
        return [
            'enable_maintenance_mode',
            'restore_code',
            'restore_database',
            'clear_caches',
            'restart_workers',
            'disable_maintenance_mode'
        ];
    }
}
