<?php

declare(strict_types=1);

namespace App\Deployment\Monitoring;

/**
 * Monitoring and alerting configuration per environment.
 *
 * This file documents monitoring setup for each environment.
 * Configuration is duplicated in:
 * - Monitoring configs: infra/monitoring/
 * - Alert rules: infra/alerting/rules/
 * - Dashboards: grafana.example.com/dashboards/
 *
 * MONITORING STACK (per MATT-2024 architecture):
 * - Metrics: Prometheus + Grafana
 * - Logs: CloudWatch + Elasticsearch + Kibana
 * - Traces: Jaeger
 * - APM: New Relic (production only)
 * - Uptime: Pingdom + self-hosted health checks
 *
 * METRICS COLLECTION:
 *
 * local:
 * - prometheus: Enabled, port 9090
 * - scrape_interval: 30 seconds
 * - retention: 15 days
 * - exporters: node, process, php-fpm
 *
 * dev:
 * - prometheus: Enabled, port 9090
 * - scrape_interval: 15 seconds
 * - retention: 30 days
 * - exporters: node, process, php-fpm, postgres, redis
 *
 * staging:
 * - prometheus: Enabled, port 9090
 * - scrape_interval: 15 seconds
 * - retention: 60 days
 * - exporters: node, process, php-fpm, postgres, redis, custom
 *
 * production:
 * - prometheus: Enabled, clustered
 * - scrape_interval: 15 seconds
 * - retention: 90 days
 * - exporters: node, process, php-fpm, postgres, redis, custom, blackbox
 * - remote_write: true (to long-term storage)
 *
 * ALERT THRESHOLDS (per alert configuration AC-2024):
 *
 * local:
 * - No automated alerts (development only)
 *
 * dev:
 * - Service down: Critical (immediate)
 * - Error rate > 5%: Warning (5 min sustained)
 * - Latency p99 > 2s: Warning (5 min sustained)
 * - CPU > 80%: Warning (10 min sustained)
 * - Memory > 85%: Warning (10 min sustained)
 *
 * staging:
 * - Service down: Critical (immediate + on-call)
 * - Error rate > 1%: Warning (2 min sustained)
 * - Error rate > 5%: Critical (immediate + on-call)
 * - Latency p99 > 1s: Warning (5 min sustained)
 * - Latency p99 > 3s: Critical (immediate + on-call)
 * - CPU > 70%: Warning (5 min sustained)
 * - Memory > 80%: Warning (5 min sustained)
 * - Disk > 80%: Warning (15 min sustained)
 *
 * production:
 * - Service down: Critical (immediate + escalation)
 * - Error rate > 0.5%: Warning (2 min sustained)
 * - Error rate > 2%: Critical (immediate + escalation)
 * - Latency p99 > 500ms: Warning (5 min sustained)
 * - Latency p99 > 1s: Critical (immediate + escalation)
 * - CPU > 60%: Warning (5 min sustained)
 * - CPU > 80%: Critical (immediate + escalation)
 * - Memory > 75%: Warning (5 min sustained)
 * - Memory > 90%: Critical (immediate + escalation)
 * - Disk > 75%: Warning (10 min sustained)
 * - Disk > 90%: Critical (immediate + escalation)
 *
 * LOGGING LEVELS:
 *
 * local:
 * - application: DEBUG
 * - framework: DEBUG
 * - database: INFO
 * - cache: WARNING
 * - external_services: INFO
 *
 * dev:
 * - application: DEBUG
 * - framework: INFO
 * - database: INFO
 * - cache: WARNING
 * - external_services: WARNING
 *
 * staging:
 * - application: INFO
 * - framework: WARNING
 * - database: WARNING
 * - cache: WARNING
 * - external_services: ERROR
 *
 * production:
 * - application: WARNING
 * - framework: ERROR
 * - database: ERROR
 * - cache: ERROR
 * - external_services: CRITICAL
 *
 * DASHBOARDS (per environment):
 *
 * local:
 * - System overview
 * - Application logs (last 24 hours)
 * - No external service dashboards
 *
 * dev:
 * - System overview
 * - Application performance
 * - Database performance
 * - Cache hit rates
 * - External services
 *
 * staging:
 * - All dev dashboards
 * - SLO/SLA tracking
 * - Capacity planning
 * - Cost analysis
 *
 * production:
 * - All staging dashboards
 * - Business metrics
 * - Revenue tracking
 * - User activity
 * - Security events
 * - Compliance reports
 *
 * DOCUMENTED IN:
 * - docs/monitoring/setup.md
 * - docs/monitoring/alerts.md
 * - docs/monitoring/dashboards.md
 * - Confluence: MONITORING-001
 */
class MonitoringConfiguration
{
    public const METRICS_CONFIGS = [
        'local' => [
            'prometheus_enabled' => true,
            'prometheus_port' => 9090,
            'scrape_interval_seconds' => 30,
            'retention_days' => 15,
            'exporters' => ['node', 'process', 'php-fpm'],
        ],
        'dev' => [
            'prometheus_enabled' => true,
            'prometheus_port' => 9090,
            'scrape_interval_seconds' => 15,
            'retention_days' => 30,
            'exporters' => ['node', 'process', 'php-fpm', 'postgres', 'redis'],
        ],
        'staging' => [
            'prometheus_enabled' => true,
            'prometheus_port' => 9090,
            'scrape_interval_seconds' => 15,
            'retention_days' => 60,
            'exporters' => ['node', 'process', 'php-fpm', 'postgres', 'redis', 'custom'],
        ],
        'production' => [
            'prometheus_enabled' => true,
            'prometheus_clustered' => true,
            'scrape_interval_seconds' => 15,
            'retention_days' => 90,
            'exporters' => ['node', 'process', 'php-fpm', 'postgres', 'redis', 'custom', 'blackbox'],
            'remote_write' => true,
        ],
    ];

    public const ALERT_THRESHOLDS = [
        'local' => [],
        'dev' => [
            'error_rate_warning' => 0.05,
            'latency_p99_warning_ms' => 2000,
            'cpu_warning' => 0.80,
            'memory_warning' => 0.85,
        ],
        'staging' => [
            'error_rate_warning' => 0.01,
            'error_rate_critical' => 0.05,
            'latency_p99_warning_ms' => 1000,
            'latency_p99_critical_ms' => 3000,
            'cpu_warning' => 0.70,
            'memory_warning' => 0.80,
            'disk_warning' => 0.80,
        ],
        'production' => [
            'error_rate_warning' => 0.005,
            'error_rate_critical' => 0.02,
            'latency_p99_warning_ms' => 500,
            'latency_p99_critical_ms' => 1000,
            'cpu_warning' => 0.60,
            'cpu_critical' => 0.80,
            'memory_warning' => 0.75,
            'memory_critical' => 0.90,
            'disk_warning' => 0.75,
            'disk_critical' => 0.90,
        ],
    ];

    public const LOGGING_LEVELS = [
        'local' => [
            'application' => 'DEBUG',
            'framework' => 'DEBUG',
            'database' => 'INFO',
            'cache' => 'WARNING',
            'external_services' => 'INFO',
        ],
        'dev' => [
            'application' => 'DEBUG',
            'framework' => 'INFO',
            'database' => 'INFO',
            'cache' => 'WARNING',
            'external_services' => 'WARNING',
        ],
        'staging' => [
            'application' => 'INFO',
            'framework' => 'WARNING',
            'database' => 'WARNING',
            'cache' => 'WARNING',
            'external_services' => 'ERROR',
        ],
        'production' => [
            'application' => 'WARNING',
            'framework' => 'ERROR',
            'database' => 'ERROR',
            'cache' => 'ERROR',
            'external_services' => 'CRITICAL',
        ],
    ];

    public function getMetricsConfig(string $environment): array
    {
        return self::METRICS_CONFIGS[$environment] ?? self::METRICS_CONFIGS['local'];
    }

    public function getAlertThresholds(string $environment): array
    {
        return self::ALERT_THRESHOLDS[$environment] ?? [];
    }

    public function getLoggingLevel(string $environment, string $category): string
    {
        $levels = self::LOGGING_LEVELS[$environment] ?? self::LOGGING_LEVELS['local'];
        return $levels[$category] ?? 'INFO';
    }

    public function getScrapeInterval(string $environment): int
    {
        $config = self::METRICS_CONFIGS[$environment] ?? self::METRICS_CONFIGS['local'];
        return $config['scrape_interval_seconds'];
    }
}
