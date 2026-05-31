<?php

declare(strict_types=1);

namespace App\Container\Service;

class ServiceDeploymentBuilder
{
    private DeploymentManifest $manifest;
    private array $containers = [];
    private array $volumes = [];
    private array $networks = [];
    private array $dependencies = [];

    public function __construct(string $serviceName)
    {
        $this->manifest = new DeploymentManifest($serviceName);
    }

    public function withImage(string $image, string $tag = 'latest'): self
    {
        $this->manifest->setImage("{$image}:{$tag}");

        return $this;
    }

    public function withReplicaCount(int $count): self
    {
        $this->manifest->setReplicas($count);

        return $this;
    }

    public function withPort(int $port, string $name = 'main'): self
    {
        $this->manifest->addPort($port, $name);

        return $this;
    }

    public function withEnvironment(array $env): self
    {
        foreach ($env as $key => $value) {
            $this->manifest->addEnvironmentVariable($key, (string)$value);
        }

        return $this;
    }

    public function withSecret(string $name, string $secretRef, string $key): self
    {
        $this->manifest->addSecretReference($name, $secretRef, $key);

        return $this;
    }

    public function withConfigMap(string $name, string $configMapRef, string $key): self
    {
        $this->manifest->addConfigMapReference($name, $configMapRef, $key);

        return $this;
    }

    public function withResourceLimits(string $cpuLimit, string $memoryLimit, string $cpuRequest, string $memoryRequest): self
    {
        $this->manifest->setResourceLimits(
            $cpuLimit,
            $memoryLimit,
            $cpuRequest,
            $memoryRequest
        );

        return $this;
    }

    public function withHealthCheck(string $path, int $initialDelay = 30, int $interval = 10): self
    {
        $this->manifest->setHealthCheck($path, $initialDelay, $interval);

        return $this;
    }

    public function withReadinessCheck(string $path, int $initialDelay = 5, int $interval = 5): self
    {
        $this->manifest->setReadinessCheck($path, $initialDelay, $interval);

        return $this;
    }

    public function withVolume(string $name, string $mountPath, string $type = 'empty', array $config = []): self
    {
        $volume = new VolumeConfig($name, $mountPath, $type, $config);
        $this->volumes[] = $volume;

        return $this;
    }

    public function withPersistentVolumeClaim(string $claimName, string $mountPath, string $accessMode = 'ReadWriteOnce'): self
    {
        $this->manifest->addVolumeClaim($claimName, $mountPath, $accessMode);

        return $this;
    }

    public function withNetwork(string $networkName, bool $external = false): self
    {
        $this->networks[] = [
            'name' => $networkName,
            'external' => $external
        ];

        return $this;
    }

    public function dependsOn(string $service, string $condition = 'service_healthy'): self
    {
        $this->dependencies[$service] = $condition;

        return $this;
    }

    public function withInitCommand(array $command): self
    {
        $this->manifest->setInitCommand($command);

        return $this;
    }

    public function withCommand(array $command): self
    {
        $this->manifest->setCommand($command);

        return $this;
    }

    public function withArguments(array $args): self
    {
        $this->manifest->setArgs($args);

        return $this;
    }

    public function withUser(int $uid, int $gid): self
    {
        $this->manifest->setUser($uid, $gid);

        return $this;
    }

    public function withWorkingDirectory(string $path): self
    {
        $this->manifest->setWorkingDirectory($path);

        return $this;
    }

    public function withRestartPolicy(string $policy = 'always'): self
    {
        $this->manifest->setRestartPolicy($policy);

        return $this;
    }

    public function withGracefulShutdown(int $timeout = 30): self
    {
        $this->manifest->setGracefulShutdown($timeout);

        return $this;
    }

    public function withDns(string $dns): self
    {
        $this->manifest->addDns($dns);

        return $this;
    }

    public function withDnsSearch(string $searchDomain): self
    {
        $this->manifest->addDnsSearch($searchDomain);

        return $this;
    }

    public function withHostEntry(string $ip, string $hostname): self
    {
        $this->manifest->addHostEntry($ip, $hostname);

        return $this;
    }

    public function withSysctls(array $sysctls): self
    {
        foreach ($sysctls as $key => $value) {
            $this->manifest->addSysctl($key, $value);
        }

        return $this;
    }

    public function withCapabilities(string $capability, bool $add = true): self
    {
        $this->manifest->addCapability($capability, $add);

        return $this;
    }

    public function withPrivileged(bool $privileged = true): self
    {
        $this->manifest->setPrivileged($privileged);

        return $this;
    }

    public function withReadOnlyRootFilesystem(bool $readonly = true): self
    {
        $this->manifest->setReadOnlyRootFilesystem($readonly);

        return $this;
    }

    public function build(): array
    {
        $config = $this->manifest->toArray();

        if (!empty($this->dependencies)) {
            $config['depends_on'] = $this->dependencies;
        }

        if (!empty($this->networks)) {
            $config['networks'] = $this->networks;
        }

        return $config;
    }

    public function toDockerCompose(): string
    {
        $config = [
            'version' => '3.8',
            'services' => [
                $this->manifest->getServiceName() => $this->build()
            ],
            'networks' => $this->buildNetworks()
        ];

        return \Symfony\Component\Yaml\Yaml::dump($config, 4, 2);
    }

    private function buildNetworks(): array
    {
        $networks = [];

        foreach ($this->networks as $network) {
            $networks[$network['name']] = [
                'external' => $network['external']
            ];
        }

        return $networks;
    }

    public function toKubernetes(): array
    {
        $serviceName = $this->manifest->getServiceName();

        return [
            'apiVersion' => 'apps/v1',
            'kind' => 'Deployment',
            'metadata' => [
                'name' => $serviceName,
                'labels' => [
                    'app' => $serviceName
                ]
            ],
            'spec' => [
                'replicas' => $this->manifest->getReplicas(),
                'selector' => [
                    'matchLabels' => [
                        'app' => $serviceName
                    ]
                ],
                'template' => [
                    'metadata' => [
                        'labels' => [
                            'app' => $serviceName
                        ]
                    ],
                    'spec' => [
                        'containers' => [
                            $this->buildContainerSpec()
                        ]
                    ]
                ]
            ]
        ];
    }

    private function buildContainerSpec(): array
    {
        $spec = [
            'name' => $this->manifest->getServiceName(),
            'image' => $this->manifest->getImage(),
            'ports' => $this->manifest->getPorts(),
            'env' => $this->manifest->getEnvironmentVariables(),
            'resources' => $this->manifest->getResourceLimits(),
            'livenessProbe' => $this->manifest->getLivenessProbe(),
            'readinessProbe' => $this->manifest->getReadinessProbe()
        ];

        if ($this->manifest->getCommand()) {
            $spec['command'] = $this->manifest->getCommand();
        }

        if ($this->manifest->getArgs()) {
            $spec['args'] = $this->manifest->getArgs();
        }

        if ($this->manifest->getWorkingDirectory()) {
            $spec['workingDir'] = $this->manifest->getWorkingDirectory();
        }

        return $spec;
    }
}

class DeploymentManifest
{
    private string $serviceName;
    private string $image = '';
    private int $replicas = 1;
    private array $ports = [];
    private array $env = [];
    private array $secrets = [];
    private array $configMaps = [];
    private array $resourceLimits = [];
    private array $livenessProbe = [];
    private array $readinessProbe = [];
    private array $volumes = [];
    private array $initCommand = [];
    private array $command = [];
    private array $args = [];
    private int $uid = 0;
    private int $gid = 0;
    private string $workingDir = '';
    private string $restartPolicy = 'always';
    private int $gracefulShutdown = 30;
    private array $dns = [];
    private array $dnsSearch = [];
    private array $hostEntries = [];
    private array $sysctls = [];
    private array $capabilities = [];
    private bool $privileged = false;
    private bool $readOnlyRootFilesystem = false;

    public function __construct(string $serviceName)
    {
        $this->serviceName = $serviceName;
    }

    public function getServiceName(): string
    {
        return $this->serviceName;
    }

    public function setImage(string $image): void
    {
        $this->image = $image;
    }

    public function getImage(): string
    {
        return $this->image;
    }

    public function setReplicas(int $count): void
    {
        $this->replicas = $count;
    }

    public function getReplicas(): int
    {
        return $this->replicas;
    }

    public function addPort(int $port, string $name): void
    {
        $this->ports[] = ['containerPort' => $port, 'name' => $name];
    }

    public function getPorts(): array
    {
        return $this->ports;
    }

    public function addEnvironmentVariable(string $name, string $value): void
    {
        $this->env[] = ['name' => $name, 'value' => $value];
    }

    public function addSecretReference(string $name, string $secretName, string $key): void
    {
        $this->secrets[] = [
            'name' => $name,
            'valueFrom' => ['secretKeyRef' => ['name' => $secretName, 'key' => $key]]
        ];
    }

    public function addConfigMapReference(string $name, string $configMapName, string $key): void
    {
        $this->configMaps[] = [
            'name' => $name,
            'valueFrom' => ['configMapKeyRef' => ['name' => $configMapName, 'key' => $key]]
        ];
    }

    public function getEnvironmentVariables(): array
    {
        return array_merge($this->env, $this->secrets, $this->configMaps);
    }

    public function setResourceLimits(string $cpuLimit, string $memoryLimit, string $cpuRequest, string $memoryRequest): void
    {
        $this->resourceLimits = [
            'limits' => ['cpu' => $cpuLimit, 'memory' => $memoryLimit],
            'requests' => ['cpu' => $cpuRequest, 'memory' => $memoryRequest]
        ];
    }

    public function getResourceLimits(): array
    {
        return $this->resourceLimits;
    }

    public function setHealthCheck(string $path, int $initialDelay, int $interval): void
    {
        $this->livenessProbe = [
            'httpGet' => ['path' => $path, 'port' => 'http'],
            'initialDelaySeconds' => $initialDelay,
            'periodSeconds' => $interval
        ];
    }

    public function getLivenessProbe(): array
    {
        return $this->livenessProbe;
    }

    public function setReadinessCheck(string $path, int $initialDelay, int $interval): void
    {
        $this->readinessProbe = [
            'httpGet' => ['path' => $path, 'port' => 'http'],
            'initialDelaySeconds' => $initialDelay,
            'periodSeconds' => $interval
        ];
    }

    public function getReadinessProbe(): array
    {
        return $this->readinessProbe;
    }

    public function addVolumeClaim(string $claimName, string $mountPath, string $accessMode): void
    {
        $this->volumes[] = [
            'name' => $claimName,
            'persistentVolumeClaim' => ['claimName' => $claimName, 'readOnly' => false]
        ];
    }

    public function setInitCommand(array $command): void
    {
        $this->initCommand = $command;
    }

    public function setCommand(array $command): void
    {
        $this->command = $command;
    }

    public function getCommand(): array
    {
        return $this->command;
    }

    public function setArgs(array $args): void
    {
        $this->args = $args;
    }

    public function getArgs(): array
    {
        return $this->args;
    }

    public function setUser(int $uid, int $gid): void
    {
        $this->uid = $uid;
        $this->gid = $gid;
    }

    public function setWorkingDirectory(string $path): void
    {
        $this->workingDir = $path;
    }

    public function getWorkingDirectory(): string
    {
        return $this->workingDir;
    }

    public function setRestartPolicy(string $policy): void
    {
        $this->restartPolicy = $policy;
    }

    public function setGracefulShutdown(int $timeout): void
    {
        $this->gracefulShutdown = $timeout;
    }

    public function addDns(string $dns): void
    {
        $this->dns[] = $dns;
    }

    public function addDnsSearch(string $domain): void
    {
        $this->dnsSearch[] = $domain;
    }

    public function addHostEntry(string $ip, string $hostname): void
    {
        $this->hostEntries[] = ['ip' => $ip, 'hostname' => $hostname];
    }

    public function addSysctl(string $name, string $value): void
    {
        $this->sysctls[$name] = $value;
    }

    public function addCapability(string $cap, bool $add): void
    {
        $type = $add ? 'add' : 'drop';
        $this->capabilities[$type][] = $cap;
    }

    public function setPrivileged(bool $privileged): void
    {
        $this->privileged = $privileged;
    }

    public function setReadOnlyRootFilesystem(bool $readonly): void
    {
        $this->readOnlyRootFilesystem = $readonly;
    }

    public function toArray(): array
    {
        $config = [
            'image' => $this->image,
            'restart' => $this->restartPolicy
        ];

        if (!empty($this->ports)) {
            $config['ports'] = $this->ports;
        }

        if (!empty($this->env)) {
            $config['environment'] = $this->env;
        }

        if (!empty($this->resourceLimits)) {
            $config['deploy'] = ['resources' => $this->resourceLimits];
        }

        if (!empty($this->command)) {
            $config['command'] = $this->command;
        }

        if (!empty($this->args)) {
            $config['args'] = $this->args;
        }

        if ($this->workingDir) {
            $config['working_dir'] = $this->workingDir;
        }

        return $config;
    }
}
