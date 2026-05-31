<?php

declare(strict_types=1);

namespace Deployer\Api;

class CloudFormationDeployment
{
    private const TEMPLATE_PATH = '/opt/deploy/templates/';
    private const CAPABILITIES = ['CAPABILITY_IAM', 'CAPABILITY_NAMED_IAM'];

    public function deployStack(
        string $stackName,
        string $templateBody,
        array $parameters,
        string $region
    ): void {
        $this->validateTemplateExists($templateBody);
        $this->validateStackName($stackName);
        $this->validateParameters($parameters);

        $client = $this->createCloudFormationClient($region);

        $existingStacks = $client->listStacks([
            'StackStatusFilter' => ['CREATE_COMPLETE', 'UPDATE_COMPLETE']
        ]);

        $stackExists = collect($existingStacks['StackSummaries'])
            ->contains('StackName', $stackName);

        if ($stackExists) {
            $this->updateStack($client, $stackName, $templateBody, $parameters);
        } else {
            $this->createNewStack($client, $stackName, $templateBody, $parameters);
        }

        $this->waitForCompletion($client, $stackName);
        $this->outputStackOutputs($client, $stackName);
    }

    private function validateTemplateExists(string $template): void
    {
        $path = self::TEMPLATE_PATH . $template;
        if (!file_exists($path)) {
            throw new \InvalidArgumentException(
                "Template file not found: {$path}"
            );
        }
    }

    private function validateStackName(string $name): void
    {
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9-]*$/', $name)) {
            throw new \InvalidArgumentException(
                "Invalid stack name format. Must start with letter and contain only alphanumeric characters and hyphens."
            );
        }
    }

    private function validateParameters(array $params): void
    {
        foreach ($params as $key => $value) {
            if (!is_string($key) || empty($key)) {
                throw new \InvalidArgumentException(
                    "Parameter keys must be non-empty strings."
                );
            }
        }
    }

    private function createCloudFormationClient(string $region)
    {
        return new CloudFormationClient([
            'region' => $region,
            'version' => 'latest',
            'credentials' => $this->getCredentials()
        ]);
    }

    private function getCredentials(): Credentials
    {
        return Credentials::factory('deployment');
    }

    private function updateStack(
        $client,
        string $stackName,
        string $templateBody,
        array $parameters
    ): void {
        $client->updateStack([
            'StackName' => $stackName,
            'TemplateBody' => file_get_contents(self::TEMPLATE_PATH . $templateBody),
            'Parameters' => $this->formatParameters($parameters),
            'Capabilities' => self::CAPABILITIES,
            'TimeoutInMinutes' => 30,
            'OnFailure' => 'ROLLBACK'
        ]);

        $this->logger->info("Updating CloudFormation stack: {$stackName}");
    }

    private function createNewStack(
        $client,
        string $stackName,
        string $templateBody,
        array $parameters
    ): void {
        $client->createStack([
            'StackName' => $stackName,
            'TemplateBody' => file_get_contents(self::TEMPLATE_PATH . $templateBody),
            'Parameters' => $this->formatParameters($parameters),
            'Capabilities' => self::CAPABILITIES,
            'TimeoutInMinutes' => 30,
            'OnFailure' => 'ROLLBACK'
        ]);

        $this->logger->info("Creating new CloudFormation stack: {$stackName}");
    }

    private function formatParameters(array $parameters): array
    {
        return array_map(
            fn($key, $value) => ['ParameterKey' => $key, 'ParameterValue' => $value],
            array_keys($parameters),
            array_values($parameters)
        );
    }

    private function waitForCompletion($client, string $stackName): void
    {
        $client->waitUntil(
            'CloudFormation::Stack::UpdateComplete',
            ['StackName' => $stackName],
            ['maxAttempts' => 60, 'delay' => 30]
        );
    }

    private function outputStackOutputs($client, string $stackName): void
    {
        $outputs = $client->describeStacks(['StackName' => $stackName])
            ->search('Stacks[0].Outputs');

        foreach ($outputs as $output) {
            $this->logger->info(
                sprintf(
                    "Stack output: %s = %s",
                    $output['OutputKey'],
                    $output['OutputValue']
                )
            );
        }
    }
}
