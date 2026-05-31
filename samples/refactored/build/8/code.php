<?php

declare(strict_types=1);

namespace App\Deployment\Core;

interface DeploymentTemplateInterface
{
    public function addStep(DeploymentStep $step): self;
    public function addValidation(ValidationStep $step): self;
    public function build(): array;
    public function toYaml(): string;
}

interface DeploymentStepInterface
{
    public function getName(): string;
    public function getModule(): string;
    public function getArgs(): array;
    public function getOptions(): array;
}

class DeploymentStep implements DeploymentStepInterface
{
    private string $name;
    private string $module;
    private array $args;
    private array $options;

    public function __construct(string $name, string $module, array $args, array $options = [])
    {
        $this->name = $name;
        $this->module = $module;
        $this->args = $args;
        $this->options = $options;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getModule(): string
    {
        return $this->module;
    }

    public function getArgs(): array
    {
        return $this->args;
    }

    public function getOptions(): array
    {
        return $this->options;
    }
}

abstract class AbstractDeploymentTemplate implements DeploymentTemplateInterface
{
    protected array $steps = [];
    protected array $validations = [];
    protected array $config = [];

    public function addStep(DeploymentStep $step): self
    {
        $this->steps[] = $step;
        return $this;
    }

    public function addValidation(ValidationStep $step): self
    {
        $this->validations[] = $step;
        return $this;
    }

    abstract public function build(): array;

    protected function stepsToArray(): array
    {
        return array_map(function (DeploymentStep $step) {
            $result = [
                'name' => $step->getName(),
                $step->getModule() => $step->getArgs()
            ];

            return array_merge($result, $step->getOptions());
        }, $this->steps);
    }

    protected function arrayToYaml(array $data): string
    {
        return \Symfony\Component\Yaml\Yaml::dump($data, 4, 2, \Symfony\Component\Yaml\DUMP_MULTI_LINE_LITERAL);
    }
}

class PhpDeploymentTemplate extends AbstractDeploymentTemplate
{
    public function __construct(string $name)
    {
        $this->config['name'] = $name;
    }

    public function build(): array
    {
        $template = [
            'name' => $this->config['name'],
            'hosts' => 'webservers',
            'become' => true,
            'vars' => [
                'app_path' => '/var/www/app',
                'php_version' => '8.2'
            ],
            'tasks' => $this->stepsToArray()
        ];

        if (!empty($this->validations)) {
            $template['pre_tasks'] = array_map(
                fn(ValidationStep $v) => $v->toAnsibleTask(),
                $this->validations
            );
        }

        return $template;
    }
}

class DeploymentStepFactory
{
    public static function installPackage(string $name, string $package): DeploymentStep
    {
        return new DeploymentStep(
            "Install {$name}",
            'apt',
            ['name' => $package, 'state' => 'present', 'update_cache' => 'yes']
        );
    }

    public static function ensureService(string $name, string $service): DeploymentStep
    {
        return new DeploymentStep(
            "Ensure {$name} is running",
            'service',
            ['name' => $service, 'state' => 'started', 'enabled' => true]
        );
    }

    public static function copyFile(string $src, string $dest, string $owner = 'root', string $mode = '0644'): DeploymentStep
    {
        return new DeploymentStep(
            "Copy {$src} to {$dest}",
            'copy',
            ['src' => $src, 'dest' => $dest, 'owner' => $owner, 'mode' => $mode, 'backup' => 'yes']
        );
    }
}
