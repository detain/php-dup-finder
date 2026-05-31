<?php

declare(strict_types=1);

namespace App\Deployment\Configuration;

/**
 * Environment-specific configuration for all deployment environments.
 *
 * This file documents the configuration for each environment.
 * Configuration values are duplicated in:
 * - Infrastructure terraform modules: infra/terraform/envs/
 * - Kubernetes configs: infra/k8s/envs/
 * - CI/CD pipeline definitions: .github/workflows/deploy.yml
 * - Secret management: vault.example.com/envs/
 *
 * ENVIRONMENT HIERARCHY:
 * - local: Development on local machines
 * - dev: Development integration environment
 * - staging: Pre-production testing
 * - production: Live production environment
 *
 * DATABASE CONFIGURATION (per environment):
 *
 * local:
 * - host: localhost
 * - port: 5432
 * - database: phpdup_local
 * - username: developer
 * - password: dev_password (not secure, local only)
 * - max_connections: 20
 * - ssl_mode: disable
 * - pool_size: 5
 *
 * dev:
 * - host: dev-db.internal.example.com
 * - port: 5432
 * - database: phpdup_dev
 * - username: phpdup_app
 * - ssl_mode: require
 * - max_connections: 50
 * - pool_size: 10
 *
 * staging:
 * - host: staging-db.internal.example.com
 * - port: 5432
 * - database: phpdup_staging
 * - username: phpdup_app
 * - ssl_mode: require
 * - max_connections: 100
 * - pool_size: 20
 *
 * production:
 * - host: prod-db-primary.internal.example.com
 * - port: 5432
 * - database: phpdup_production
 * - username: phpdup_app (read) / phpdup_admin (write)
 * - ssl_mode: verify-full
 * - max_connections: 500
 * - pool_size: 50
 * - replica_host: prod-db-replica.internal.example.com
 * - backup_retention_days: 30
 *
 * REDIS CONFIGURATION (per environment):
 *
 * local:
 * - host: localhost
 * - port: 6379
 * - password: null
 * - database: 0
 * - ssl: false
 *
 * dev:
 * - host: dev-redis.internal.example.com
 * - port: 6379
 * - password: ${REDIS_PASSWORD}
 * - database: 0
 * - ssl: true
 *
 * staging:
 * - host: staging-redis.internal.example.com
 * - port: 6379
 * - password: ${REDIS_PASSWORD}
 * - database: 0
 * - ssl: true
 * - cluster_mode: true
 *
 * production:
 * - host: prod-redis-primary.internal.example.com
 * - port: 6379
 * - password: ${REDIS_PASSWORD}
 * - database: 0
 * - ssl: true
 * - cluster_mode: true
 * - replica_host: prod-redis-replica.internal.example.com
 * - sentinel_hosts: ["sentinel1.internal", "sentinel2.internal", "sentinel3.internal"]
 *
 * RATE LIMITING CONFIGURATION (per environment):
 *
 * local:
 * - enabled: false
 * - requests_per_minute: 10000
 *
 * dev:
 * - enabled: true
 * - requests_per_minute: 1000
 * - burst_allowance: 1.5
 *
 * staging:
 * - enabled: true
 * - requests_per_minute: 5000
 * - burst_allowance: 2.0
 *
 * production:
 * - enabled: true
 * - requests_per_minute: 10000
 * - burst_allowance: 2.0
 * - concurrent_limit: 1000
 *
 * LOGGING CONFIGURATION (per environment):
 *
 * local:
 * - level: debug
 * - format: json
 * - output: stdout
 * - sentry_dsn: null
 *
 * dev:
 * - level: debug
 * - format: json
 * - output: file
 * - file_path: /var/log/phpdup/dev.log
 * - sentry_dsn: null
 *
 * staging:
 * - level: info
 * - format: json
 * - output: file
 * - file_path: /var/log/phpdup/staging.log
 * - sentry_dsn: ${SENTRY_DSN}
 * - sentry_environment: staging
 *
 * production:
 * - level: warning
 * - format: json
 * - output: file
 * - file_path: /var/log/phpdup/production.log
 * - sentry_dsn: ${SENTRY_DSN}
 * - sentry_environment: production
 * - log_retention_days: 90
 *
 * DOCUMENTED IN:
 * - docs/configuration/environments.md
 * - confluence.io/ENV-CONFIG
 * - JIRA: DEVOPS-234
 */
class EnvironmentConfiguration
{
    public const ENV_LOCAL = 'local';
    public const ENV_DEV = 'dev';
    public const ENV_STAGING = 'staging';
    public const ENV_PRODUCTION = 'production';

    public const DATABASE_SETTINGS = [
        self::ENV_LOCAL => [
            'host' => 'localhost',
            'port' => 5432,
            'database' => 'phpdup_local',
            'username' => 'developer',
            'password' => 'dev_password',
            'max_connections' => 20,
            'ssl_mode' => 'disable',
            'pool_size' => 5,
        ],
        self::ENV_DEV => [
            'host' => 'dev-db.internal.example.com',
            'port' => 5432,
            'database' => 'phpdup_dev',
            'username' => 'phpdup_app',
            'password' => '${DB_PASSWORD}',
            'max_connections' => 50,
            'ssl_mode' => 'require',
            'pool_size' => 10,
        ],
        self::ENV_STAGING => [
            'host' => 'staging-db.internal.example.com',
            'port' => 5432,
            'database' => 'phpdup_staging',
            'username' => 'phpdup_app',
            'password' => '${DB_PASSWORD}',
            'max_connections' => 100,
            'ssl_mode' => 'require',
            'pool_size' => 20,
        ],
        self::ENV_PRODUCTION => [
            'host' => 'prod-db-primary.internal.example.com',
            'port' => 5432,
            'database' => 'phpdup_production',
            'username' => 'phpdup_app',
            'password' => '${DB_PASSWORD}',
            'max_connections' => 500,
            'ssl_mode' => 'verify-full',
            'pool_size' => 50,
            'replica_host' => 'prod-db-replica.internal.example.com',
            'backup_retention_days' => 30,
        ],
    ];

    public const REDIS_SETTINGS = [
        self::ENV_LOCAL => [
            'host' => 'localhost',
            'port' => 6379,
            'password' => null,
            'database' => 0,
            'ssl' => false,
        ],
        self::ENV_DEV => [
            'host' => 'dev-redis.internal.example.com',
            'port' => 6379,
            'password' => '${REDIS_PASSWORD}',
            'database' => 0,
            'ssl' => true,
        ],
        self::ENV_STAGING => [
            'host' => 'staging-redis.internal.example.com',
            'port' => 6379,
            'password' => '${REDIS_PASSWORD}',
            'database' => 0,
            'ssl' => true,
            'cluster_mode' => true,
        ],
        self::ENV_PRODUCTION => [
            'host' => 'prod-redis-primary.internal.example.com',
            'port' => 6379,
            'password' => '${REDIS_PASSWORD}',
            'database' => 0,
            'ssl' => true,
            'cluster_mode' => true,
            'replica_host' => 'prod-redis-replica.internal.example.com',
            'sentinel_hosts' => ['sentinel1.internal', 'sentinel2.internal', 'sentinel3.internal'],
        ],
    ];

    public const RATE_LIMIT_SETTINGS = [
        self::ENV_LOCAL => [
            'enabled' => false,
            'requests_per_minute' => 10000,
        ],
        self::ENV_DEV => [
            'enabled' => true,
            'requests_per_minute' => 1000,
            'burst_allowance' => 1.5,
        ],
        self::ENV_STAGING => [
            'enabled' => true,
            'requests_per_minute' => 5000,
            'burst_allowance' => 2.0,
        ],
        self::ENV_PRODUCTION => [
            'enabled' => true,
            'requests_per_minute' => 10000,
            'burst_allowance' => 2.0,
            'concurrent_limit' => 1000,
        ],
    ];

    private string $environment;

    public function __construct(string $environment)
    {
        $this->environment = $environment;
    }

    public function getDatabaseConfig(): array
    {
        return self::DATABASE_SETTINGS[$this->environment] ?? self::DATABASE_SETTINGS[self::ENV_LOCAL];
    }

    public function getRedisConfig(): array
    {
        return self::REDIS_SETTINGS[$this->environment] ?? self::REDIS_SETTINGS[self::ENV_LOCAL];
    }

    public function getRateLimitConfig(): array
    {
        return self::RATE_LIMIT_SETTINGS[$this->environment] ?? self::RATE_LIMIT_SETTINGS[self::ENV_LOCAL];
    }

    public function isProduction(): bool
    {
        return $this->environment === self::ENV_PRODUCTION;
    }

    public function isDevelopment(): bool
    {
        return in_array($this->environment, [self::ENV_LOCAL, self::ENV_DEV], true);
    }
}
