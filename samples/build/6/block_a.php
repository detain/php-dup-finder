<?php

declare(strict_types=1);

namespace App\CI;

class GitHubActionsWorkflowBuilder
{
    private const WORKFLOW_DIR = '.github/workflows';
    private const RUNNER_LABEL = 'ubuntu-latest';

    private WorkflowConfig $config;
    private array $steps = [];
    private array $envVars = [];
    private array $secrets = [];

    public function __construct(WorkflowConfig $config)
    {
        $this->config = $config;
        $this->initializeDefaultSteps();
    }

    public function addStep(string $name, array $runConfig): self
    {
        $this->validateStepConfig($runConfig);

        $this->steps[] = [
            'name' => $name,
            'run' => $runConfig['run'] ?? null,
            'uses' => $runConfig['uses'] ?? null,
            'with' => $runConfig['with'] ?? [],
            'env' => $runConfig['env'] ?? [],
            'if' => $runConfig['if'] ?? null
        ];

        return $this;
    }

    public function addCacheStep(string $paths, string $key): self
    {
        $this->steps[] = [
            'name' => 'Cache dependencies',
            'uses' => 'actions/cache@v3',
            'with' => [
                'path' => $paths,
                'key' => $key
            ]
        ];

        return $this;
    }

    public function addSetupPhpStep(string $version, array $extensions = []): self
    {
        $step = [
            'name' => 'Setup PHP',
            'uses' => 'shivammathur/setup-php@v2',
            'with' => [
                'php-version' => $version
            ]
        ];

        if (!empty($extensions)) {
            $step['with']['extensions'] = implode(',', $extensions);
        }

        $this->steps[] = $step;

        return $this;
    }

    public function addComposerInstallStep(bool $dev = false): self
    {
        $this->steps[] = [
            'name' => 'Install dependencies',
            'run' => 'composer install --no-interaction --prefer-dist' . ($dev ? '' : '--no-dev'),
            'env' => [
                'COMPOSER_TOKEN' => '${{ secrets.COMPOSER_TOKEN }}'
            ]
        ];

        return $this;
    }

    public function addPhpUnitStep(?string $testsuite = null): self
    {
        $command = './vendor/bin/phpunit --colors=never';

        if ($testsuite !== null) {
            $command .= " --testsuite={$testsuite}";
        }

        $this->steps[] = [
            'name' => 'Run PHPUnit',
            'run' => $command,
            'env' => [
                'APP_ENV' => 'testing',
                'DB_CONNECTION' => 'sqlite',
                'DB_DATABASE' => ':memory:'
            ]
        ];

        return $this;
    }

    public function addPhpStanStep(int $level = 6): self
    {
        $this->steps[] = [
            'name' => 'Run PHPStan',
            'run' => './vendor/bin/phpstan analyse --memory-limit=1G --level=' . $level,
        ];

        return $this;
    }

    public function addPsalmStep(): self
    {
        $this->steps[] = [
            'name' => 'Run Psalm',
            'run' => './vendor/bin/psalm --no-cache',
        ];

        return $this;
    }

    public function addSecurityAuditStep(): self
    {
        $this->steps[] = [
            'name' => 'Security audit',
            'run' => 'composer audit --format=json || true',
        ];

        return $this;
    }

    public function addEnvVar(string $key, string $value): self
    {
        $this->envVars[$key] = $value;

        return $this;
    }

    public function addSecret(string $name): self
    {
        $this->secrets[] = $name;

        return $this;
    }

    public function build(): array
    {
        $workflow = [
            'name' => $this->config->getWorkflowName(),
            'on' => $this->buildTrigger(),
            'env' => $this->envVars,
            'jobs' => [
                'build' => $this->buildJob()
            ]
        ];

        return $workflow;
    }

    private function initializeDefaultSteps(): void
    {
        $this->steps = [
            [
                'name' => 'Checkout code',
                'uses' => 'actions/checkout@v3'
            ]
        ];
    }

    private function validateStepConfig(array $config): void
    {
        if (!isset($config['run']) && !isset($config['uses'])) {
            throw new \InvalidArgumentException('Step must have either "run" or "uses" property');
        }

        if (isset($config['run']) && isset($config['uses'])) {
            throw new \InvalidArgumentException('Step cannot have both "run" and "uses" properties');
        }
    }

    private function buildTrigger(): array
    {
        $trigger = [
            'push' => [
                'branches' => $this->config->getBranches()
            ],
            'pull_request' => [
                'branches' => $this->config->getBranches()
            ]
        ];

        if ($this->config->isScheduleEnabled()) {
            $trigger['schedule'] = [
                ['cron' => $this->config->getSchedule()]
            ];
        }

        return $trigger;
    }

    private function buildJob(): array
    {
        return [
            'name' => $this->config->getJobName(),
            'runs-on' => self::RUNNER_LABEL,
            'steps' => $this->steps,
            'services' => $this->buildServices()
        ];
    }

    private function buildServices(): array
    {
        $services = [];

        if ($this->config->needsMysql()) {
            $services['mysql'] = [
                'image' => 'mysql:8.0',
                'env' => [
                    'MYSQL_ROOT_PASSWORD' => 'root',
                    'MYSQL_DATABASE' => 'test'
                ],
                'ports' => ['3306:3306'],
                'options' => '--health-cmd="mysqladmin ping" --health-interval=10s --health-timeout=5s --health-retries=5'
            ];
        }

        if ($this->config->needsRedis()) {
            $services['redis'] = [
                'image' => 'redis:7-alpine',
                'ports' => ['6379:6379'],
                'options' => '--health-cmd="redis-cli ping" --health-interval=10s --health-timeout=5s --health-retries=5'
            ];
        }

        return $services;
    }

    public function save(string $workflowName): void
    {
        $workflow = $this->build();

        $workflowDir = getcwd() . '/' . self::WORKFLOW_DIR;

        if (!is_dir($workflowDir)) {
            mkdir($workflowDir, 0755, true);
        }

        $yaml = $this->arrayToYaml($workflow);

        file_put_contents("{$workflowDir}/{$workflowName}.yml", $yaml);

        $this->logger->info("Workflow saved: {$workflowName}.yml");
    }

    private function arrayToYaml(array $array, int $indent = 0): string
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
                        if (is_array($item)) {
                            $yaml .= "{$indentStr}  - ";
                            $yaml .= $this->arrayToYaml($item, $indent + 2);
                        } else {
                            $yaml .= "{$indentStr}  - {$item}\n";
                        }
                    }
                }
            } else {
                $yaml .= "{$indentStr}{$key}: {$value}\n";
            }
        }

        return $yaml;
    }

    private function isAssocArray(array $arr): bool
    {
        if (empty($arr)) {
            return false;
        }

        return array_keys($arr) !== range(0, count($arr) - 1);
    }
}

class WorkflowConfig
{
    private string $workflowName;
    private string $jobName;
    private array $branches;
    private string $schedule;
    private bool $scheduleEnabled = false;
    private bool $needsMysql = false;
    private bool $needsRedis = false;

    public function __construct(string $workflowName)
    {
        $this->workflowName = $workflowName;
        $this->jobName = 'Build and Test';
        $this->branches = ['main', 'develop'];
        $this->schedule = '0 0 * * *';
    }

    public function getWorkflowName(): string
    {
        return $this->workflowName;
    }

    public function getJobName(): string
    {
        return $this->jobName;
    }

    public function getBranches(): array
    {
        return $this->branches;
    }

    public function getSchedule(): string
    {
        return $this->schedule;
    }

    public function isScheduleEnabled(): bool
    {
        return $this->scheduleEnabled;
    }

    public function needsMysql(): bool
    {
        return $this->needsMysql;
    }

    public function needsRedis(): bool
    {
        return $this->needsRedis;
    }

    public function setBranches(array $branches): self
    {
        $this->branches = $branches;
        return $this;
    }

    public function enableSchedule(string $cron): self
    {
        $this->scheduleEnabled = true;
        $this->schedule = $cron;
        return $this;
    }

    public function enableMysql(): self
    {
        $this->needsMysql = true;
        return $this;
    }

    public function enableRedis(): self
    {
        $this->needsRedis = true;
        return $this;
    }
}
