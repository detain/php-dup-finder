<?php

declare(strict_types=1);

namespace App\Deployment\Documentation;

/**
 * Centralized deployment documentation generator.
 * Single source of truth for all deployment procedures,
 * eliminating duplication across scripts, runbooks, and wikis.
 */
final class DeploymentDocumentationGenerator
{
    private const DOCUMENTED_STEPS = [
        'pre_health_check' => 'Perform health checks on running pods',
        'backup' => 'Create database backup before migration',
        'migrate' => 'Run database migrations with verification',
        'deploy_service' => 'Deploy service with rolling update',
        'traffic_shift' => 'Shift traffic gradually (10% -> 50% -> 100%)',
        'post_verification' => 'Verify service health and metrics',
    ];

    private const ROLLBACK_TRIGGERS = [
        'error_rate_threshold' => 0.05,
        'latency_threshold_ms' => 2000,
        'http_5xx_rate' => 0.01,
        'deployment_timeout_seconds' => 1800,
    ];

    public static function getDeploymentSteps(): array
    {
        return self::DOCUMENTED_STEPS;
    }

    public static function getRollbackTriggers(): array
    {
        return self::ROLLBACK_TRIGGERS;
    }
}
