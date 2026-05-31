<?php

declare(strict_types=1);

namespace App\CI\Core;

interface WorkflowBuilderInterface
{
    public function addStep(string $name, array $config): self;
    public function addTestStep(string $name, array $config): self;
    public function addDeployStep(string $name, string $environment, array $config): self;
    public function build(): array;
    public function save(string $path): void;
}

abstract class AbstractWorkflowBuilder implements WorkflowBuilderInterface
{
    protected array $steps = [];
    protected array $config = [];
    protected LoggerInterface $logger;

    abstract public function build(): array;

    public function addStep(string $name, array $config): self
    {
        $this->validateStepConfig($config);
        $this->steps[] = $this->normalizeStep($name, $config);

        return $this;
    }

    public function addTestStep(string $name, array $config): self
    {
        return $this->addStep($name, array_merge($config, ['stage' => 'test']));
    }

    public function addDeployStep(string $name, string $environment, array $config): self
    {
        return $this->addStep($name, array_merge($config, [
            'stage' => 'deploy',
            'environment' => $environment
        ]));
    }

    protected function validateStepConfig(array $config): void
    {
        if (!isset($config['run']) && !isset($config['uses'])) {
            throw new \InvalidArgumentException('Step must have "run" or "uses" property');
        }
    }

    abstract protected function normalizeStep(string $name, array $config): array;

    protected function arrayToYaml(array $array, int $indent = 0): string
    {
        $yaml = '';
        $indentStr = str_repeat('  ', $indent);

        foreach ($array as $key => $value) {
            if (is_array($value)) {
                if ($this->isAssocArray($value)) {
                    $yaml .= "{$indentStr}{$key}:\n";
                    $yaml .= $this->arrayToYaml($value, $indent + 1);
                } else {
                    $yaml .= "{$indentStr}{$key}:\n";
                    foreach ($value as $item) {
                        $yaml .= "{$indentStr}  - " . (is_array($item) ? "\n" . $this->arrayToYaml($item, $indent + 2) : "{$item}\n");
                    }
                }
            } else {
                $yaml .= "{$indentStr}{$key}: {$value}\n";
            }
        }

        return $yaml;
    }

    protected function isAssocArray(array $arr): bool
    {
        if (empty($arr)) return false;
        return array_keys($arr) !== range(0, count($arr) - 1);
    }
}

trait SharedBuildSteps
{
    protected function addCheckoutStep(): array
    {
        return ['name' => 'Checkout', 'uses' => 'actions/checkout@v3'];
    }

    protected function addPhpSetupStep(string $version, array $extensions = []): array
    {
        return [
            'name' => 'Setup PHP',
            'uses' => 'shivammathur/setup-php@v2',
            'with' => array_merge(['php-version' => $version], $extensions ? ['extensions' => implode(',', $extensions)] : [])
        ];
    }

    protected function addComposerInstallStep(bool $dev = true): array
    {
        return [
            'name' => 'Install dependencies',
            'run' => 'composer install --no-interaction --prefer-dist' . ($dev ? '' : '--no-dev')
        ];
    }

    protected function addPhpUnitStep(?string $testsuite = null): array
    {
        return [
            'name' => 'Run PHPUnit',
            'run' => './vendor/bin/phpunit' . ($testsuite ? " --testsuite={$testsuite}" : '') . ' --colors=never'
        ];
    }

    protected function addStaticAnalysisStep(string $tool): array
    {
        return [
            'name' => "Run {$tool}",
            'run' => "./vendor/bin/{$tool}"
        ];
    }
}

class GitHubActionsBuilder extends AbstractWorkflowBuilder
{
    use SharedBuildSteps;

    protected function normalizeStep(string $name, array $config): array
    {
        return array_merge(['name' => $name], $config);
    }
}

class GitLabCIBuilder extends AbstractWorkflowBuilder
{
    protected function normalizeStep(string $name, array $config): array
    {
        return [
            'name' => $name,
            'script' => is_array($config['run'] ?? null) ? $config['run'] : [$config['run'] ?? '']
        ];
    }
}

class WorkflowOrchestrator
{
    private array $builders = [];

    public function registerBuilder(string $platform, WorkflowBuilderInterface $builder): void
    {
        $this->builders[$platform] = $builder;
    }

    public function buildForPlatform(string $platform, array $steps): array
    {
        if (!isset($this->builders[$platform])) {
            throw new \RuntimeException("No builder for platform: {$platform}");
        }

        foreach ($steps as $step) {
            $this->builders[$platform]->addStep($step['name'], $step['config']);
        }

        return $this->builders[$platform]->build();
    }
}
