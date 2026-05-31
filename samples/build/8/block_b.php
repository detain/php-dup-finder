<?php

declare(strict_types=1);

namespace App\Container\Kubernetes;

class KubernetesDeploymentGenerator
{
    private const DEFAULT_NAMESPACE = 'default';
    private const MAXSurge = 1;
    private const MAXUnavailable = 0;

    private K8sConfig $config;
    private array $manifests = [];

    public function __construct(K8sConfig $config)
    {
        $this->config = $config;
        $this->initializeManifest();
    }

    private function initializeManifest(): void
    {
        $this->manifests['apiVersion'] = 'apps/v1';
        $this->manifests['kind'] = 'Deployment';
    }

    public function setName(string $name): self
    {
        $this->manifests['metadata']['name'] = $name;
        $this->manifests['metadata']['labels']['app'] = $name;

        return $this;
    }

    public function setNamespace(string $namespace): self
    {
        $this->manifests['metadata']['namespace'] = $namespace;

        return $this;
    }

    public function setReplicas(int $replicas): self
    {
        $this->manifests['spec']['replicas'] = $replicas;

        return $this;
    }

    public function setSelector(array $labels): self
    {
        $this->manifests['spec']['selector']['matchLabels'] = $labels;

        return $this;
    }

    public function addLabel(string $key, string $value): self
    {
        $this->manifests['metadata']['labels'][$key] = $value;
        $this->manifests['spec']['template']['metadata']['labels'][$key] = $value;

        return $this;
    }

    public function setImage(string $image, string $tag = 'latest'): self
    {
        $this->manifests['spec']['template']['spec']['containers'][0]['image'] = "{$image}:{$tag}";

        return $this;
    }

    public function setImagePullPolicy(string $policy = 'Always'): self
    {
        $this->manifests['spec']['template']['spec']['containers'][0]['imagePullPolicy'] = $policy;

        return $this;
    }

    public function addPort(int $port, string $name = 'http'): self
    {
        $this->manifests['spec']['template']['spec']['containers'][0]['ports'][] = [
            'name' => $name,
            'containerPort' => $port,
            'protocol' => 'TCP'
        ];

        return $this;
    }

    public function addEnvironmentVariable(string $name, string $value): self
    {
        $this->manifests['spec']['template']['spec']['containers'][0]['env'][] = [
            'name' => $name,
            'value' => $value
        ];

        return $this;
    }

    public function addSecretRef(string $name, string $secretName, string $key): self
    {
        $this->manifests['spec']['template']['spec']['containers'][0]['env'][] = [
            'name' => $name,
            'valueFrom' => [
                'secretKeyRef' => [
                    'name' => $secretName,
                    'key' => $key
                ]
            ]
        ];

        return $this;
    }

    public function addConfigMapRef(string $name, string $configMapName, string $key): self
    {
        $this->manifests['spec']['template']['spec']['containers'][0]['env'][] = [
            'name' => $name,
            'valueFrom' => [
                'configMapKeyRef' => [
                    'name' => $configMapName,
                    'key' => $key
                ]
            ]
        ];

        return $this;
    }

    public function addResourceRequest(string $cpu, string $memory): self
    {
        $this->manifests['spec']['template']['spec']['containers'][0]['resources']['requests'] = [
            'cpu' => $cpu,
            'memory' => $memory
        ];

        return $this;
    }

    public function addResourceLimit(string $cpu, string $memory): self
    {
        $this->manifests['spec']['template']['spec']['containers'][0]['resources']['limits'] = [
            'cpu' => $cpu,
            'memory' => $memory
        ];

        return $this;
    }

    public function setLivenessProbe(array $probe): self
    {
        $this->manifests['spec']['template']['spec']['containers'][0]['livenessProbe'] = $probe;

        return $this;
    }

    public function setReadinessProbe(array $probe): self
    {
        $this->manifests['spec']['template']['spec']['containers'][0]['readinessProbe'] = $probe;

        return $this;
    }

    public function addVolumeMount(string $name, string $mountPath): self
    {
        $this->manifests['spec']['template']['spec']['containers'][0]['volumeMounts'][] = [
            'name' => $name,
            'mountPath' => $mountPath
        ];

        return $this;
    }

    public function addVolume(string $name, string $hostPath): self
    {
        $this->manifests['spec']['template']['spec']['volumes'][] = [
            'name' => $name,
            'hostPath' => [
                'path' => $hostPath,
                'type' => 'DirectoryOrCreate'
            ]
        ];

        return $this;
    }

    public function addEmptyDirVolume(string $name): self
    {
        $this->manifests['spec']['template']['spec']['volumes'][] = [
            'name' => $name,
            'emptyDir' => new \stdClass()
        ];

        return $this;
    }

    public function addInitContainer(array $container): self
    {
        $this->manifests['spec']['template']['spec']['initContainers'][] = $container;

        return $this;
    }

    public function setRestartPolicy(string $policy = 'Always'): self
    {
        $this->manifests['spec']['template']['spec']['restartPolicy'] = $policy;

        return $this;
    }

    public function addNodeSelector(array $selectors): self
    {
        $this->manifests['spec']['template']['spec']['nodeSelector'] = $selectors;

        return $this;
    }

    public function addToleration(array $toleration): self
    {
        $this->manifests['spec']['template']['spec']['tolerations'][] = $toleration;

        return $this;
    }

    public function addAffinity(array $affinity): self
    {
        $this->manifests['spec']['template']['spec']['affinity'] = $affinity;

        return $this;
    }

    public function setTerminationGracePeriod(int $seconds): self
    {
        $this->manifests['spec']['template']['spec']['terminationGracePeriodSeconds'] = $seconds;

        return $this;
    }

    public function build(): array
    {
        if (!isset($this->manifests['spec']['selector']['matchLabels'])) {
            $this->manifests['spec']['selector']['matchLabels'] = $this->manifests['spec']['template']['metadata']['labels'];
        }

        return $this->manifests;
    }

    public function toYaml(): string
    {
        $yaml = \Symfony\Component\Yaml\Yaml::dump($this->build(), 4, 2, \Symfony\Component\Yaml\Yaml::DUMP_MULTI_LINE_LITERAL);

        return "# Deployment manifest for " . ($this->manifests['metadata']['name'] ?? 'application') . "\n---\n" . $yaml;
    }

    public function save(string $path): void
    {
        $dir = dirname($path);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($path, $this->toYaml());
    }
}
