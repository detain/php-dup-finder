<?php

declare(strict_types=1);

namespace App\Infrastructure\Deployment;

use Symfony\Component\Process\Process;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Kubernetes Deployment Configuration Generator
 *
 * Generates Kubernetes manifests for all microservices based on
 * environment configuration. These configurations are documented in
 * the infrastructure-as-code documentation at docs/infra/k8s-manifests.md
 * and mirrored in the Terraform modules under infra/terraform/.
 *
 * DEPLOYMENT CONFIGURATION OPTIONS:
 * - Replica count: 3 for production, 1 for staging
 * - Resource limits: Defined per service based on historical usage
 * - Health checks: HTTP liveness/readiness probes on /health endpoints
 * - Environment variables: Injected from Kubernetes secrets
 * - Volume mounts: PersistentVolumeClaims for stateful services
 * - PodDisruptionBudget: Ensures minimum availability during updates
 *
 * SERVICE-SPECIFIC CONFIGURATIONS (from infra docs Section 2):
 *
 * api-gateway:
 *   - replicas: 5 (production)
 *   - resources: 2 CPU, 4Gi memory
 *   - port: 8080
 *   - health check: /healthz
 *
 * user-service:
 *   - replicas: 3
 *   - resources: 1 CPU, 2Gi memory
 *   - port: 8081
 *   - database: user_db (PostgreSQL)
 *   - health check: /api/users/health
 *
 * product-service:
 *   - replicas: 3
 *   - resources: 2 CPU, 4Gi memory
 *   - port: 8082
 *   - database: product_db (PostgreSQL)
 *   - cache: redis-product-cache
 *   - health check: /api/products/health
 *
 * order-service:
 *   - replicas: 4
 *   - resources: 2 CPU, 8Gi memory
 *   - port: 8083
 *   - database: order_db (PostgreSQL)
 *   - message_queue: rabbitmq
 *   - health check: /api/orders/health
 *
 * payment-service:
 *   - replicas: 3
 *   - resources: 1 CPU, 2Gi memory
 *   - port: 8084
 *   - database: payment_db (PostgreSQL)
 *   - PCI compliance: true
 *   - health check: /api/payments/health
 *
 * notification-service:
 *   - replicas: 2
 *   - resources: 0.5 CPU, 1Gi memory
 *   - port: 8085
 *   - message_queue: rabbitmq
 *   - health check: /api/notifications/health
 *
 * ENVIRONMENT VARIABLES REQUIRED (per service):
 * - DATABASE_HOST, DATABASE_PORT, DATABASE_NAME, DATABASE_USER, DATABASE_PASSWORD
 * - REDIS_HOST, REDIS_PASSWORD
 * - AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY (for S3)
 * - STRIPE_API_KEY, PAYPAL_CLIENT_ID (payment services)
 * - SENDGRID_API_KEY, TWILIO_ACCOUNT_SID (notification services)
 *
 * DOCUMENTATION REFERENCES:
 * - Kubernetes manifests: infra/k8s/manifests/
 * - Helm charts: infra/helm/
 * - Environment configs: infra/environments/
 * - Secrets management: docs/security/secrets-management.md
 */
class KubernetesManifestGenerator
{
    private const DEFAULT_REPLICAS_PRODUCTION = 3;
    private const DEFAULT_REPLICAS_STAGING = 1;

    private const SERVICE_CONFIGS = [
        'api-gateway' => [
            'replicas' => 5,
            'resources' => ['cpu' => '2000m', 'memory' => '4Gi'],
            'port' => 8080,
            'health_path' => '/healthz',
        ],
        'user-service' => [
            'replicas' => 3,
            'resources' => ['cpu' => '1000m', 'memory' => '2Gi'],
            'port' => 8081,
            'health_path' => '/api/users/health',
            'database' => 'user_db',
        ],
        'product-service' => [
            'replicas' => 3,
            'resources' => ['cpu' => '2000m', 'memory' => '4Gi'],
            'port' => 8082,
            'health_path' => '/api/products/health',
            'database' => 'product_db',
            'cache' => 'redis-product-cache',
        ],
        'order-service' => [
            'replicas' => 4,
            'resources' => ['cpu' => '2000m', 'memory' => '8Gi'],
            'port' => 8083,
            'health_path' => '/api/orders/health',
            'database' => 'order_db',
            'message_queue' => 'rabbitmq',
        ],
        'payment-service' => [
            'replicas' => 3,
            'resources' => ['cpu' => '1000m', 'memory' => '2Gi'],
            'port' => 8084,
            'health_path' => '/api/payments/health',
            'database' => 'payment_db',
            'pci_compliant' => true,
        ],
        'notification-service' => [
            'replicas' => 2,
            'resources' => ['cpu' => '500m', 'memory' => '1Gi'],
            'port' => 8085,
            'health_path' => '/api/notifications/health',
            'message_queue' => 'rabbitmq',
        ],
    ];

    /**
     * Generate complete Kubernetes deployment manifests for all services.
     *
     * @param string $environment Target environment (production, staging, development)
     * @param string $namespace Kubernetes namespace
     * @param array<string, string> $imageTags Map of service name to image tag
     * @return array<string, array> Generated Kubernetes resources keyed by filename
     */
    public function generateManifests(
        string $environment,
        string $namespace,
        array $imageTags
    ): array {

        $replicas = $environment === 'production'
            ? self::DEFAULT_REPLICAS_PRODUCTION
            : self::DEFAULT_REPLICAS_STAGING;

        $manifests = [];

        $manifests['namespace.yaml'] = $this->generateNamespaceManifest($namespace);

        foreach (self::SERVICE_CONFIGS as $serviceName => $config) {
            $serviceManifests = $this->generateServiceManifests(
                $serviceName,
                $config,
                $namespace,
                $imageTags[$serviceName] ?? 'latest',
                $replicas
            );

            $manifests["{$serviceName}-deployment.yaml"] = $serviceManifests['deployment'];
            $manifests["{$serviceName}-service.yaml"] = $serviceManifests['service'];
            $manifests["{$serviceName}-hpa.yaml"] = $serviceManifests['hpa'];
            $manifests["{$serviceName}-pdb.yaml"] = $serviceManifests['pdb'];
        }

        return $manifests;
    }

    /**
     * Generate Deployment, Service, HPA, and PDB for a single service.
     * Resource requirements are documented in the infrastructure sizing guide
     * ISG-2024 and should be updated when service resource usage changes.
     */
    private function generateServiceManifests(
        string $serviceName,
        array $config,
        string $namespace,
        string $imageTag,
        int $replicas
    ): array {

        $labels = [
            'app' => $serviceName,
            'environment' => $namespace,
            'managed-by' => 'deployment-orchestrator',
        ];

        return [
            'deployment' => [
                'apiVersion' => 'apps/v1',
                'kind' => 'Deployment',
                'metadata' => [
                    'name' => $serviceName,
                    'namespace' => $namespace,
                    'labels' => $labels,
                ],
                'spec' => [
                    'replicas' => $replicas,
                    'selector' => ['matchLabels' => ['app' => $serviceName]],
                    'strategy' => [
                        'type' => 'RollingUpdate',
                        'rollingUpdate' => ['maxSurge' => 1, 'maxUnavailable' => 0],
                    ],
                    'template' => [
                        'metadata' => ['labels' => $labels],
                        'spec' => [
                            'containers' => [
                                [
                                    'name' => $serviceName,
                                    'image' => "registry.example.com/{$serviceName}:{$imageTag}",
                                    'ports' => [['containerPort' => $config['port']]],
                                    'resources' => [
                                        'requests' => [
                                            'cpu' => $config['resources']['cpu'],
                                            'memory' => $config['resources']['memory'],
                                        ],
                                        'limits' => [
                                            'cpu' => $config['resources']['cpu'],
                                            'memory' => $config['resources']['memory'],
                                        ],
                                    ],
                                    'livenessProbe' => [
                                        'httpGet' => [
                                            'path' => $config['health_path'],
                                            'port' => $config['port'],
                                        ],
                                        'initialDelaySeconds' => 30,
                                        'periodSeconds' => 10,
                                        'failureThreshold' => 3,
                                    ],
                                    'readinessProbe' => [
                                        'httpGet' => [
                                            'path' => $config['health_path'],
                                            'port' => $config['port'],
                                        ],
                                        'initialDelaySeconds' => 5,
                                        'periodSeconds' => 5,
                                        'failureThreshold' => 3,
                                    ],
                                    'envFrom' => [
                                        ['secretRef' => ['name' => "{$serviceName}-secrets"]],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'service' => [
                'apiVersion' => 'v1',
                'kind' => 'Service',
                'metadata' => [
                    'name' => $serviceName,
                    'namespace' => $namespace,
                ],
                'spec' => [
                    'selector' => ['app' => $serviceName],
                    'ports' => [
                        [
                            'name' => 'http',
                            'port' => 80,
                            'targetPort' => $config['port'],
                        ],
                    ],
                    'type' => 'ClusterIP',
                ],
            ],
            'hpa' => [
                'apiVersion' => 'autoscaling/v2',
                'kind' => 'HorizontalPodAutoscaler',
                'metadata' => [
                    'name' => $serviceName,
                    'namespace' => $namespace,
                ],
                'spec' => [
                    'scaleTargetRef' => [
                        'apiVersion' => 'apps/v1',
                        'kind' => 'Deployment',
                        'name' => $serviceName,
                    ],
                    'minReplicas' => $replicas,
                    'maxReplicas' => $replicas * 3,
                    'metrics' => [
                        [
                            'type' => 'Resource',
                            'resource' => [
                                'name' => 'cpu',
                                'target' => ['type' => 'Utilization', 'averageUtilization' => 70],
                            ],
                        ],
                        [
                            'type' => 'Resource',
                            'resource' => [
                                'name' => 'memory',
                                'target' => ['type' => 'Utilization', 'averageUtilization' => 80],
                            ],
                        ],
                    ],
                ],
            ],
            'pdb' => [
                'apiVersion' => 'policy/v1',
                'kind' => 'PodDisruptionBudget',
                'metadata' => [
                    'name' => $serviceName,
                    'namespace' => $namespace,
                ],
                'spec' => [
                    'selector' => ['matchLabels' => ['app' => $serviceName]],
                    'minAvailable' => max(1, (int) floor($replicas * 0.5)),
                ],
            ],
        ];
    }

    /**
     * Generate namespace manifest with resource quotas.
     * Resource quotas are documented in the infrastructure capacity planning
     * document ICP-2024 Section 3 "Namespace Quotas".
     */
    private function generateNamespaceManifest(string $namespace): array
    {
        $quota = match ($namespace) {
            'production' => [
                'requests.cpu' => '40',
                'requests.memory' => '80Gi',
                'limits.cpu' => '80',
                'limits.memory' => '160Gi',
                'count/deployments.apps' => '20',
                'count/services' => '30',
            ],
            'staging' => [
                'requests.cpu' => '10',
                'requests.memory' => '20Gi',
                'limits.cpu' => '20',
                'limits.memory' => '40Gi',
                'count/deployments.apps' => '10',
                'count/services' => '15',
            ],
            default => [],
        };

        return [
            'apiVersion' => 'v1',
            'kind' => 'Namespace',
            'metadata' => [
                'name' => $namespace,
                'labels' => [
                    'environment' => $namespace,
                    'managed-by' => 'deployment-orchestrator',
                ],
            ],
        ];
    }
}
