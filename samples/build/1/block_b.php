<?php

declare(strict_types=1);

namespace Deployer\Infrastructure;

class TerraformExecutor
{
    private const TERRAFORM_DIR = '/opt/deploy/terraform/';
    private const WORKSPACE_PREFIX = 'env_';

    public function applyInfrastructure(
        string $environment,
        string $region,
        array $vars = []
    ): void {
        $this->validateEnvironment($environment);
        $this->validateRegion($region);
        $this->validateVariables($vars);

        $this->initializeTerraform($environment, $region);
        $this->selectWorkspace($environment);
        $this->validateConfiguration();
        $this->planInfrastructure($vars);
        $this->applyWithApproval($vars);

        $this->outputStateOutputs($environment);
    }

    private function validateEnvironment(string $env): void
    {
        $validEnvironments = ['development', 'staging', 'production'];

        if (!in_array($env, $validEnvironments, true)) {
            throw new \InvalidArgumentException(
                sprintf(
                    "Invalid environment '%s'. Must be one of: %s",
                    $env,
                    implode(', ', $validEnvironments)
                )
            );
        }
    }

    private function validateRegion(string $region): void
    {
        $validRegions = ['us-east-1', 'us-west-2', 'eu-west-1', 'ap-southeast-1'];

        if (!in_array($region, $validRegions, true)) {
            throw new \InvalidArgumentException(
                sprintf(
                    "Invalid AWS region '%s'. Must be one of: %s",
                    $region,
                    implode(', ', $validRegions)
                )
            );
        }
    }

    private function validateVariables(array $vars): void
    {
        foreach ($vars as $key => $value) {
            if (!is_string($key) || !preg_match('/^[a-z_][a-z0-9_]*$/', $key)) {
                throw new \InvalidArgumentException(
                    "Variable names must be lowercase with underscores."
                );
            }
        }
    }

    private function initializeTerraform(string $environment, string $region): void
    {
        $backendConfig = sprintf(
            's3://terraform-state-%s/%s/terraform.tfstate',
            $environment,
            $region
        );

        $this->runCommand([
            'terraform', 'init',
            '-backend-config=bucket=' . $backendConfig,
            '-backend-config=key=' . self::WORKSPACE_PREFIX . $environment . '.tfstate',
            '-backend-config=region=' . $region,
            '-backend-config=encrypt=true'
        ], self::TERRAFORM_DIR);

        $this->logger->info("Terraform initialized for environment: {$environment}");
    }

    private function selectWorkspace(string $environment): void
    {
        $workspaceName = self::WORKSPACE_PREFIX . $environment;

        $this->runCommand([
            'terraform', 'workspace', 'select', $workspaceName
        ], self::TERRAFORM_DIR);

        $this->runCommand([
            'terraform', 'workspace', 'new', $workspaceName
        ], self::TERRAFORM_DIR);

        $this->logger->info("Selected Terraform workspace: {$workspaceName}");
    }

    private function validateConfiguration(): void
    {
        $result = $this->runCommand([
            'terraform', 'validate'
        ], self::TERRAFORM_DIR);

        if ($result['exit_code'] !== 0) {
            throw new \RuntimeException(
                "Terraform configuration validation failed: " . $result['output']
            );
        }

        $this->logger->info("Terraform configuration validated successfully");
    }

    private function planInfrastructure(array $vars): void
    {
        $varArgs = $this->formatVariablesForCli($vars);

        $this->runCommand(array_merge([
            'terraform', 'plan',
            '-out=/tmp/terraform.plan',
            '-detailed-exitcode'
        ], $varArgs), self::TERRAFORM_DIR);

        $this->logger->info("Terraform plan generated");
    }

    private function applyWithApproval(array $vars): void
    {
        $varArgs = $this->formatVariablesForCli($vars);

        $result = $this->runCommand(array_merge([
            'terraform', 'apply',
            '/tmp/terraform.plan'
        ], $varArgs), self::TERRAFORM_DIR);

        if ($result['exit_code'] !== 0) {
            throw new \RuntimeException(
                "Terraform apply failed: " . $result['output']
            );
        }

        $this->logger->info("Terraform apply completed successfully");
    }

    private function formatVariablesForCli(array $vars): array
    {
        $args = [];
        foreach ($vars as $key => $value) {
            $args[] = '-var';
            $args[] = "{$key}={$value}";
        }
        return $args;
    }

    private function outputStateOutputs(string $environment): void
    {
        $result = $this->runCommand([
            'terraform', 'output', '-json'
        ], self::TERRAFORM_DIR);

        $outputs = json_decode($result['output'], true);

        foreach ($outputs as $key => $value) {
            $this->logger->info(
                sprintf("Terraform output: %s = %s", $key, $value['value'])
            );
        }
    }

    private function runCommand(array $command, string $cwd): array
    {
        $process = new Process($command);
        $process->setWorkingDirectory($cwd);
        $process->setTimeout(300);
        $process->run();

        return [
            'exit_code' => $process->getExitCode(),
            'output' => $process->getOutput() . $process->getErrorOutput()
        ];
    }
}
