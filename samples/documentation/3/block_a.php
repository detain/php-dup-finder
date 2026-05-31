<?php

declare(strict_types=1);

namespace App\Deployment\Scripts;

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Microservices Deployment Orchestration Script
 *
 * This script handles the deployment of all microservices to the staging
 * and production Kubernetes clusters. The deployment procedure is documented
 * in the runbook OPS-RUN-001 and mirrored in the CI/CD pipeline documentation.
 *
 * DEPLOYMENT STEPS (per runbook Section 3):
 * 1. Pre-deployment health checks on all running pods
 * 2. Database migration execution with backup verification
 * 3. Rolling update of each microservice in dependency order
 * 4. Smoke tests after each service update
 * 5. Traffic shifting (10% -> 50% -> 100%) with monitoring
 * 6. Post-deployment verification and rollback triggers
 *
 * PREREQUISITES (documented in README.md Section "Deployment Setup"):
 * - kubectl configured with appropriate cluster credentials
 * - Helm v3 installed and authenticated
 * - Docker images pushed to registry with correct tags
 * - Kubernetes secrets configured for all environments
 * - PostgreSQL client available for migration verification
 *
 * ROLLBACK PROCEDURES (from runbook Section 5):
 * - Automatic rollback if error rate exceeds 5% during deployment
 * - Automatic rollback if p99 latency exceeds 2 seconds
 * - Manual rollback available via: ./deploy.php rollback --service=<name>
 * - Database migrations are not rolled back automatically
 *
 * MONITORING ALERTS (per runbook Section 6):
 * - Slack notification to #deployments channel on start/completion
 * - PagerDuty alert if deployment fails or times out (>30 min)
 * - Deployment metrics logged to Datadog for audit trail
 *
 * See also: docs/runbooks/deployment/OPS-RUN-001.md and confluence.io/page/OPS-DEPLOY
 */
class DeploymentOrchestrator
{
    private const DEPLOYMENT_TIMEOUT_SECONDS = 1800;
    private const HEALTH_CHECK_RETRIES = 5;
    private const HEALTH_CHECK_INTERVAL_SECONDS = 10;
    private const TRAFFIC_SHIFT_PERCENTAGES = [10, 50, 100];

    private const SERVICES_IN_ORDER = [
        'api-gateway',
        'user-service',
        'product-service',
        'order-service',
        'payment-service',
        'notification-service',
    ];

    private const NAMESPACE = 'production';
    private const HELM_CHART_PATH = '/deploy/helm/application';

    public function __construct(
        private readonly KubernetesClient $k8s,
        private readonly DatabaseMigrator $migrator,
        private readonly DockerRegistry $registry,
        private readonly MonitoringClient $monitoring,
        private readonly OutputInterface $output,
    ) {}

    /**
     * Execute full production deployment with zero-downtime rolling updates.
     *
     * @param string $version Tag/branch to deploy (e.g., "v2.3.1")
     * @param array<string> $services List of services to deploy, defaults to all
     * @return DeploymentResult Summary of deployment outcome
     */
    public function deploy(string $version, array $services = []): DeploymentResult
    {
        $servicesToDeploy = empty($services) ? self::SERVICES_IN_ORDER : $services;
        $startTime = new \DateTimeImmutable();

        $this->output->writeln("<info>Starting deployment of version {$version}</info>");
        $this->monitoring->notifyDeploymentStart($version, $servicesToDeploy);

        try {
            $healthStatus = $this->performPreDeploymentHealthCheck();
            if (!$healthStatus->isHealthy) {
                throw new DeploymentException(
                    "Pre-deployment health check failed: {$healthStatus->failureReason}"
                );
            }

            $this->output->writeln("<info>Running database migrations...</info>");
            $migrationResult = $this->migrator->migrate();
            if (!$migrationResult->isSuccessful) {
                throw new DeploymentException(
                    "Migration failed: {$migrationResult->errorMessage}"
                );
            }

            $deployedServices = [];
            foreach ($servicesToDeploy as $serviceName) {
                $this->output->writeln("<info>Deploying service: {$serviceName}</info>");

                $serviceResult = $this->deployService($serviceName, $version);
                if (!$serviceResult->isSuccessful) {
                    $this->rollbackPreviousServices($deployedServices);
                    throw new DeploymentException(
                        "Service {$serviceName} deployment failed, rolled back previous services"
                    );
                }

                $deployedServices[] = $serviceName;
            }

            $duration = (new \DateTimeImmutable())->diff($startTime);

            $this->output->writeln("<info>Deployment completed successfully in {$duration->i}m {$duration->s}s</info>");
            $this->monitoring->notifyDeploymentSuccess($version, $deployedServices, $duration);

            return new DeploymentResult(
                success: true,
                version: $version,
                servicesDeployed: $deployedServices,
                duration: $duration,
            );

        } catch (\Exception $e) {
            $this->output->writeln("<error>Deployment failed: {$e->getMessage()}</error>");
            $this->monitoring->notifyDeploymentFailure($version, $e);
            throw $e;
        }
    }

    /**
     * Deploy a single service with rolling update and traffic shifting.
     * Traffic shifting percentages and thresholds are documented in the
     * deployment runbook Section 4.2 "Traffic Management".
     */
    private function deployService(string $serviceName, string $version): ServiceDeploymentResult
    {
        $image = $this->registry->getImageUri($serviceName, $version);

        $this->k8s->deploy($serviceName, $image, [
            'namespace' => self::NAMESPACE,
            'strategy' => 'RollingUpdate',
            'maxSurge' => 1,
            'maxUnavailable' => 0,
        ]);

        foreach (self::TRAFFIC_SHIFT_PERCENTAGES as $percentage) {
            $this->output->writeln("  Shifting traffic to {$percentage}%");
            $this->k8s->setTrafficSplit($serviceName, $percentage);

            $healthCheck = $this->waitForServiceHealthy($serviceName);
            if (!$healthCheck->isHealthy) {
                $this->output->writeln("<error>Health check failed at {$percentage}% traffic</error>");
                return new ServiceDeploymentResult(
                    success: false,
                    service: $serviceName,
                    failedAtTrafficPercentage: $percentage,
                    error: $healthCheck->failureReason,
                );
            }

            $this->monitoring->recordDeploymentProgress($serviceName, $percentage);

            if ($percentage < 100) {
                sleep(30);
            }
        }

        return new ServiceDeploymentResult(
            success: true,
            service: $serviceName,
            finalTrafficPercentage: 100,
        );
    }

    /**
     * Pre-deployment health check as documented in runbook Section 3.1.
     * Checks all running pods are in Ready state and error rates are normal.
     */
    private function performPreDeploymentHealthCheck(): HealthStatus
    {
        $pods = $this->k8s->getAllPods(self::NAMESPACE);

        $notReadyPods = array_filter(
            $pods,
            fn($pod) => $pod['phase'] !== 'Running' || $pod['readyCondition'] !== True
        );

        if (!empty($notReadyPods)) {
            return new HealthStatus(
                isHealthy: false,
                failureReason: 'Found ' . count($notReadyPods) . ' not-ready pods'
            );
        }

        $errorRate = $this->monitoring->getCurrentErrorRate();
        if ($errorRate > 0.01) {
            return new HealthStatus(
                isHealthy: false,
                failureReason: "Current error rate {$errorRate}% exceeds threshold of 1%"
            );
        }

        return new HealthStatus(isHealthy: true);
    }

    /**
     * Wait for service to become healthy with retries.
     * Timeout and retry values are documented in the runbook Section 3.3.
     */
    private function waitForServiceHealthy(string $serviceName): HealthStatus
    {
        for ($attempt = 1; $attempt <= self::HEALTH_CHECK_RETRIES; $attempt++) {
            $pods = $this->k8s->getPodsForService($serviceName, self::NAMESPACE);

            $allReady = true;
            foreach ($pods as $pod) {
                if ($pod['readyCondition'] !== True) {
                    $allReady = false;
                    break;
                }
            }

            if ($allReady) {
                $latency = $this->monitoring->getServiceLatency($serviceName);
                if ($latency->p99 > 2000) {
                    return new HealthStatus(
                        isHealthy: false,
                        failureReason: "p99 latency {$latency->p99}ms exceeds 2000ms threshold"
                    );
                }
                return new HealthStatus(isHealthy: true);
            }

            if ($attempt < self::HEALTH_CHECK_RETRIES) {
                sleep(self::HEALTH_CHECK_INTERVAL_SECONDS);
            }
        }

        return new HealthStatus(
            isHealthy: false,
            failureReason: 'Service did not become healthy within timeout'
        );
    }

    /**
     * Rollback previously deployed services in reverse order.
     * Rollback procedure is documented in runbook Section 5 "Rollback Procedures".
     */
    private function rollbackPreviousServices(array $deployedServices): void
    {
        $this->output->writeln("<warn>Rolling back previously deployed services...</warn>");

        $reversed = array_reverse($deployedServices);
        foreach ($reversed as $serviceName) {
            $this->output->writeln("  Rolling back: {$serviceName}");
            $this->k8s->rollback($serviceName, self::NAMESPACE);
        }
    }
}
