<?php

declare(strict_types=1);

namespace App\Deployment\Configuration;

/**
 * Centralized environment configuration.
 * Single source of truth for all environment-specific settings,
 * eliminating duplication across wikis, scripts, and CI/CD pipelines.
 */
final class CentralizedEnvironmentConfig
{
    private static array $configs = [];

    public static function load(string $environment): void
    {
        self::$configs[$environment] = [
            'database' => DatabaseMigrationSetup::MIGRATION_CONFIGS[$environment] ?? [],
            'redis' => EnvironmentConfiguration::REDIS_SETTINGS[$environment] ?? [],
            'monitoring' => MonitoringConfiguration::METRICS_CONFIGS[$environment] ?? [],
            'alerts' => MonitoringConfiguration::ALERT_THRESHOLDS[$environment] ?? [],
        ];
    }

    public static function get(string $environment, string $section): array
    {
        return self::$configs[$environment][$section] ?? [];
    }
}
