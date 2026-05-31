<?php

declare(strict_types=1);

namespace App\Deployment\Core;

interface DeploymentSpecInterface
{
    public function addHealthCheck(HealthCheck $check): self;
    public function addResourceRequirement(ResourceRequirement $req): self;
    public function addEnvironment(EnvironmentVariable $env): self;
    public function build(): array;
}

abstract class AbstractDeploymentSpec implements DeploymentSpecInterface
{
    protected array $healthChecks = [];
    protected array $resources = [];
    protected array $environment = [];

    public function addHealthCheck(HealthCheck $check): self
    {
        $this->healthChecks[] = $check;
        return $this;
    }

    public function addResourceRequirement(ResourceRequirement $req): self
    {
        $this->resources[] = $req;
        return $this;
    }

    public function addEnvironment(EnvironmentVariable $env): self
    {
        $this->environment[] = $env;
        return $this;
    }

    abstract public function build(): array;
}

class HealthCheck
{
    private string $type;
    private string $path;
    private int $initialDelaySeconds;
    private int $periodSeconds;
    private int $timeoutSeconds;
    private int $failureThreshold;

    public static function http(string $path, int $initialDelay = 30, int $period = 10): self
    {
        return new self('http', $path, $initialDelay, $period, 5, 3);
    }

    public static function tcp(int $port, int $initialDelay = 30, int $period = 10): self
    {
        return new self('tcp', "port:{$port}", $initialDelay, $period, 5, 3);
    }

    private function __construct(
        string $type,
        string $path,
        int $initialDelaySeconds,
        int $periodSeconds,
        int $timeoutSeconds,
        int $failureThreshold
    ) {
        $this->type = $type;
        $this->path = $path;
        $this->initialDelaySeconds = $initialDelaySeconds;
        $this->periodSeconds = $periodSeconds;
        $this->timeoutSeconds = $timeoutSeconds;
        $this->failureThreshold = $failureThreshold;
    }

    public function toArray(): array
    {
        if ($this->type === 'http') {
            return [
                'httpGet' => ['path' => $this->path, 'port' => 8080],
                'initialDelaySeconds' => $this->initialDelaySeconds,
                'periodSeconds' => $this->periodSeconds,
                'timeoutSeconds' => $this->timeoutSeconds,
                'failureThreshold' => $this->failureThreshold
            ];
        }

        return [
            'tcpSocket' => ['port' => (int)explode(':', $this->path)[1]],
            'initialDelaySeconds' => $this->initialDelaySeconds,
            'periodSeconds' => $this->periodSeconds,
            'timeoutSeconds' => $this->timeoutSeconds,
            'failureThreshold' => $this->failureThreshold
        ];
    }
}

class ResourceRequirement
{
    private string $cpuRequest;
    private string $memoryRequest;
    private string $cpuLimit;
    private string $memoryLimit;

    public static function small(): self
    {
        return new self('50m', '128Mi', '200m', '256Mi');
    }

    public static function medium(): self
    {
        return new self('100m', '256Mi', '500m', '512Mi');
    }

    public static function large(): self
    {
        return new self('500m', '1Gi', '2000m', '2Gi');
    }

    private function __construct(
        string $cpuRequest,
        string $memoryRequest,
        string $cpuLimit,
        string $memoryLimit
    ) {
        $this->cpuRequest = $cpuRequest;
        $this->memoryRequest = $memoryRequest;
        $this->cpuLimit = $cpuLimit;
        $this->memoryLimit = $memoryLimit;
    }

    public function toArray(): array
    {
        return [
            'requests' => ['cpu' => $this->cpuRequest, 'memory' => $this->memoryRequest],
            'limits' => ['cpu' => $this->cpuLimit, 'memory' => $this->memoryLimit]
        ];
    }
}

class DeploymentFactory
{
    public static function createKubernetesDeployment(string $name, array $spec): array
    {
        return [
            'apiVersion' => 'apps/v1',
            'kind' => 'Deployment',
            'metadata' => ['name' => $name],
            'spec' => [
                'replicas' => $spec['replicas'] ?? 3,
                'selector' => ['matchLabels' => ['app' => $name]],
                'template' => [
                    'metadata' => ['labels' => ['app' => $name]],
                    'spec' => [
                        'containers' => [
                            [
                                'name' => $name,
                                'image' => $spec['image'],
                                'ports' => [['containerPort' => $spec['port'] ?? 8080]],
                                'livenessProbe' => $spec['liveness'] ?? null,
                                'readinessProbe' => $spec['readiness'] ?? null,
                                'resources' => $spec['resources'] ?? []
                            ]
                        ]
                    ]
                ]
            ]
        ];
    }

    public static function createDockerComposeService(string $name, array $spec): array
    {
        return [
            'image' => $spec['image'],
            'ports' => [$spec['port'] ?? 8080],
            'environment' => $spec['env'] ?? [],
            'deploy' => [
                'replicas' => $spec['replicas'] ?? 1,
                'resources' => $spec['resources'] ?? []
            ],
            'healthcheck' => $spec['healthcheck'] ?? null
        ];
    }
}
