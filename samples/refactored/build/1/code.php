<?php

declare(strict_types=1);

namespace Deployer\Core;

interface DeploymentStrategy
{
    public function deploy(DeploymentContext $context): DeploymentResult;
    public function validate(DeploymentContext $context): ValidationResult;
    public function outputResults(DeploymentResult $result): void;
}

abstract class AbstractDeploymentStrategy implements DeploymentStrategy
{
    protected LoggerInterface $logger;
    protected array $validationRules = [];

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function deploy(DeploymentContext $context): DeploymentResult
    {
        $this->validate($context);
        $this->prepareEnvironment($context);
        $this->executeDeployment($context);
        $this->waitForCompletion($context);
        return $this->buildResult($context);
    }

    public function validate(DeploymentContext $context): ValidationResult
    {
        $errors = [];

        foreach ($this->validationRules as $field => $rule) {
            $value = $context->get($field);
            if (!$rule->validate($value)) {
                $errors[] = new ValidationError($field, $rule->message());
            }
        }

        return new ValidationResult($errors);
    }

    protected function prepareEnvironment(DeploymentContext $context): void
    {
        $this->logger->info("Preparing environment for deployment");
    }

    abstract protected function executeDeployment(DeploymentContext $context): void;

    abstract protected function waitForCompletion(DeploymentContext $context): void;

    protected function buildResult(DeploymentContext $context): DeploymentResult
    {
        return new DeploymentResult(
            success: true,
            outputs: $context->getOutputs(),
            metadata: $context->getMetadata()
        );
    }
}

class CloudFormationStrategy extends AbstractDeploymentStrategy
{
    protected function getValidationRules(): array
    {
        return [
            'stackName' => new RequiredStringRule(),
            'template' => new FileExistsRule('/opt/deploy/templates/'),
            'region' => new AllowedValuesRule(['us-east-1', 'us-west-2', 'eu-west-1'])
        ];
    }

    public function __construct(LoggerInterface $logger)
    {
        parent::__construct($logger);
        $this->validationRules = $this->getValidationRules();
    }

    protected function executeDeployment(DeploymentContext $context): void
    {
        $client = $this->createClient($context->get('region'));

        if ($this->stackExists($client, $context->get('stackName'))) {
            $client->updateStack($this->buildStackParams($context));
        } else {
            $client->createStack($this->buildStackParams($context));
        }
    }

    protected function waitForCompletion(DeploymentContext $context): void
    {
        $client = $this->createClient($context->get('region'));
        $client->waitUntil('CloudFormation::Stack::UpdateComplete', [
            'StackName' => $context->get('stackName')
        ]);
    }
}

class TerraformStrategy extends AbstractDeploymentStrategy
{
    protected function getValidationRules(): array
    {
        return [
            'environment' => new AllowedValuesRule(['development', 'staging', 'production']),
            'region' => new AllowedValuesRule(['us-east-1', 'us-west-2', 'eu-west-1', 'ap-southeast-1']),
            'vars' => new DictionaryRule(new StringKeyRule())
        ];
    }

    public function __construct(LoggerInterface $logger)
    {
        parent::__construct($logger);
        $this->validationRules = $this->getValidationRules();
    }

    protected function executeDeployment(DeploymentContext $context): void
    {
        $this->runCommand(['terraform', 'init', ...$this->backendConfig($context)]);
        $this->runCommand(['terraform', 'apply', '-auto-approve', ...$this->varArgs($context)]);
    }

    protected function waitForCompletion(DeploymentContext $context): void
    {
        // Terraform applies are synchronous by default
    }
}

class DeploymentOrchestrator
{
    private array $strategies = [];
    private DeploymentContext $context;

    public function registerStrategy(string $name, DeploymentStrategy $strategy): void
    {
        $this->strategies[$name] = $strategy;
    }

    public function deploy(string $strategyName, array $params): DeploymentResult
    {
        $strategy = $this->strategies[$strategyName] ?? throw new \InvalidArgumentException(
            "Unknown deployment strategy: {$strategyName}"
        );

        $this->context = new DeploymentContext($params);

        return $strategy->deploy($this->context);
    }
}
