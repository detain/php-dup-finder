<?php

declare(strict_types=1);

namespace Deployer\Kubernetes;

class K8sDeploymentManager
{
    private const MANIFEST_DIR = '/opt/deploy/k8s/';
    private const NAMESPACE_PREFIX = 'prod-';

    public function deployApplication(
        string $serviceName,
        string $version,
        array $replicas,
        string $cluster
    ): void {
        $this->validateServiceName($serviceName);
        $this->validateVersion($version);
        $this->validateReplicas($replicas);
        $this->validateCluster($cluster);

        $this->authenticateToCluster($cluster);
        $this->prepareNamespace($serviceName);
        $this->applyManifests($serviceName, $version);
        $this->waitForRollout($serviceName);
        $this->verifyDeployment($serviceName, $replicas);
    }

    private function validateServiceName(string $name): void
    {
        if (!preg_match('/^[a-z]([a-z0-9-]*[a-z0-9])?$/', $name)) {
            throw new \InvalidArgumentException(
                "Invalid service name. Must be lowercase alphanumeric with single hyphens, cannot start or end with hyphen."
            );
        }
    }

    private function validateVersion(string $version): void
    {
        if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) {
            throw new \InvalidArgumentException(
                "Invalid version format. Must follow semver (e.g., 1.2.3)."
            );
        }
    }

    private function validateReplicas(array $replicas): void
    {
        if ($replicas['desired'] < 1 || $replicas['desired'] > 100) {
            throw new \InvalidArgumentException(
                "Replica count must be between 1 and 100."
            );
        }

        if ($replicas['maxSurge'] < 0 || $replicas['maxSurge'] > 5) {
            throw new \InvalidArgumentException(
                "maxSurge must be between 0 and 5."
            );
        }
    }

    private function validateCluster(string $cluster): void
    {
        $validClusters = ['us-east-prod', 'us-west-prod', 'eu-west-prod'];

        if (!in_array($cluster, $validClusters, true)) {
            throw new \InvalidArgumentException(
                sprintf(
                    "Invalid cluster '%s'. Must be one of: %s",
                    $cluster,
                    implode(', ', $validClusters)
                )
            );
        }
    }

    private function authenticateToCluster(string $cluster): void
    {
        $kubeconfig = "/opt/deploy/kubeconfig/{$cluster}.yaml";

        if (!file_exists($kubeconfig)) {
            throw new \RuntimeException("Kubeconfig not found: {$kubeconfig}");
        }

        $result = $this->execute([
            'kubectl', 'get', 'nodes',
            '--kubeconfig', $kubeconfig
        ]);

        if ($result['exit_code'] !== 0) {
            throw new \RuntimeException(
                "Failed to authenticate to cluster: " . $result['error']
            );
        }

        $this->logger->info("Authenticated to Kubernetes cluster: {$cluster}");
    }

    private function prepareNamespace(string $serviceName): void
    {
        $namespace = self::NAMESPACE_PREFIX . $serviceName;

        $result = $this->execute([
            'kubectl', 'create', 'namespace', $namespace,
            '--dry-run=client', '-o', 'yaml'
        ]);

        if ($result['exit_code'] === 0) {
            $this->execute([
                'kubectl', 'create', 'namespace', $namespace
            ]);
            $this->logger->info("Created namespace: {$namespace}");
        }

        $this->applyCommonLabels($namespace);
    }

    private function applyManifests(string $serviceName, string $version): void
    {
        $manifestFiles = glob(self::MANIFEST_DIR . "{$serviceName}/*.yaml");

        if (empty($manifestFiles)) {
            $manifestFiles = [self::MANIFEST_DIR . "{$serviceName}.yaml"];
        }

        foreach ($manifestFiles as $manifest) {
            $content = $this->substituteVariables(
                file_get_contents($manifest),
                ['VERSION' => $version, 'SERVICE' => $serviceName]
            );

            $tempFile = tempnam('/tmp', 'k8s_');
            file_put_contents($tempFile, $content);

            $result = $this->execute([
                'kubectl', 'apply', '-f', $tempFile
            ]);

            unlink($tempFile);

            if ($result['exit_code'] !== 0) {
                throw new \RuntimeException(
                    "Failed to apply manifest {$manifest}: " . $result['error']
                );
            }
        }

        $this->logger->info("Applied Kubernetes manifests for: {$serviceName}");
    }

    private function substituteVariables(string $content, array $vars): string
    {
        foreach ($vars as $key => $value) {
            $content = str_replace('${' . $key . '}', $value, $content);
        }
        return $content;
    }

    private function applyCommonLabels(string $namespace): void
    {
        $this->execute([
            'kubectl', 'label', 'namespaces', $namespace,
            'environment=production',
            'managed-by=deployer',
            '--overwrite'
        ]);
    }

    private function waitForRollout(string $serviceName): void
    {
        $result = $this->execute([
            'kubectl', 'rollout', 'status',
            'deployment/' . $serviceName,
            '-n', self::NAMESPACE_PREFIX . $serviceName,
            '--timeout=300s'
        ]);

        if ($result['exit_code'] !== 0) {
            throw new \RuntimeException(
                "Rollout timed out for service: {$serviceName}"
            );
        }

        $this->logger->info("Deployment rollout complete for: {$serviceName}");
    }

    private function verifyDeployment(string $serviceName, array $replicas): void
    {
        $result = $this->execute([
            'kubectl', 'get', 'deployment',
            $serviceName,
            '-n', self::NAMESPACE_PREFIX . $serviceName,
            '-o', 'json'
        ]);

        $deployment = json_decode($result['output'], true);
        $readyReplicas = $deployment['status']['readyReplicas'] ?? 0;

        if ($readyReplicas < $replicas['desired']) {
            throw new \RuntimeException(
                sprintf(
                    "Deployment verification failed. Expected %d ready replicas, got %d.",
                    $replicas['desired'],
                    $readyReplicas
                )
            );
        }

        $this->logger->info(
            sprintf(
                "Deployment verified: %s has %d/%d ready replicas",
                $serviceName,
                $readyReplicas,
                $replicas['desired']
            )
        );
    }

    private function execute(array $command): array
    {
        $process = new Process($command);
        $process->setTimeout(300);
        $process->run();

        return [
            'exit_code' => $process->getExitCode(),
            'output' => $process->getOutput(),
            'error' => $process->getErrorOutput()
        ];
    }
}
