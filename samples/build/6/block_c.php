<?php

declare(strict_types=1);

namespace App\CI\CircleCI;

class CircleCIPipelineBuilder
{
    private const CONFIG_VERSION = 2;
    private const DEFAULT_DOCKER_IMAGE = 'cimg/php:8.2';

    private CircleCIConfig $config;
    private array $jobs = [];
    private array $workflows = [];
    private array $orbs = [];

    public function __construct(CircleCIConfig $config)
    {
        $this->config = $config;
    }

    public function addOrb(string $name, string $version): self
    {
        $this->orbs[$name] = $version;

        return $this;
    }

    public function addJob(string $name, array $jobConfig): self
    {
        $this->validateJobConfig($jobConfig);

        $this->jobs[$name] = $jobConfig;

        return $this;
    }

    public function addTestJob(string $name, array $steps = []): self
    {
        $this->jobs[$name] = [
            'docker' => [
                ['image' => $steps['image'] ?? self::DEFAULT_DOCKER_IMAGE]
            ],
            'environment' => [
                'APP_ENV' => 'test',
                'DB_HOST' => '127.0.0.1'
            ],
            'services' => [
                ['mysql' => ['image' => 'mysql:8.0']]
            ],
            'steps' => array_merge([
                '- checkout',
                '- run:
                  name: Install dependencies
                  command: |
                    composer install --no-interaction --prefer-dist
                    composer dump-autoload --optimize',
                '- run:
                  name: Run database migrations
                  command: |
                    php artisan migrate --force --no-interaction
                    php artisan db:seed --force || true',
                '- run:
                  name: Run PHPUnit tests
                  command: |
                    ./vendor/bin/phpunit --colors=never',
                '- run:
                  name: Run PHPStan
                  command: ./vendor/bin/phpstan analyse --memory-limit=1G || true',
                '- run:
                  name: Run Psalm
                  command: ./vendor/bin/psalm --no-cache || true',
                '- store_test_results:
                  path: reports/',
                '- store_artifacts:
                  path: coverage/'
            ], $steps['extra_steps'] ?? [])
        ];

        return $this;
    }

    public function addStaticAnalysisJob(string $name, string $tool, array $steps = []): self
    {
        $command = match($tool) {
            'phpstan' => './vendor/bin/phpstan analyse --memory-limit=1G --level=' . ($steps['level'] ?? 6),
            'psalm' => './vendor/bin/psalm --no-cache',
            'phpcs' => './vendor/bin/php-cs-fixer fix --dry-run --diff',
            'phpcpd' => './vendor/bin/phpcpd --fuzzy src',
            'phan' => './vendor/bin/phan -o 2 || true',
            default => throw new \InvalidArgumentException("Unknown tool: {$tool}")
        };

        $this->jobs[$name] = [
            'docker' => [
                ['image' => $steps['image'] ?? self::DEFAULT_DOCKER_IMAGE]
            ],
            'steps' => array_merge([
                '- checkout',
                '- run:
                  name: Install dependencies
                  command: composer install --no-interaction --prefer-dist',
                "- run:
                  name: Run {$tool}
                  command: {$command}"
            ], $steps['extra_steps'] ?? [])
        ];

        return $this;
    }

    public function addBuildJob(string $name, array $steps = []): self
    {
        $this->jobs[$name] = [
            'docker' => [
                ['image' => $steps['image'] ?? self::DEFAULT_DOCKER_IMAGE]
            ],
            'steps' => array_merge([
                '- checkout',
                '- run:
                  name: Install dependencies
                  command: |
                    composer install --no-interaction --prefer-dist --no-dev
                    composer dump-autoload --optimize',
                '- run:
                  name: Build assets
                  command: |
                    npm ci --no-audit
                    npm run build',
                '- run:
                  name: Create deployment package
                  command: |
                    tar -czf /tmp/build.tar.gz \
                      --exclude=./vendor \
                      --exclude=./node_modules \
                      --exclude=./.git \
                      --exclude=./.env.local \
                      --exclude=.env \
                      .',
                '- persist_to_workspace:
                  root: /tmp
                  paths:
                    - build.tar.gz',
                '- store_artifacts:
                  path: /tmp/build.tar.gz'
            ], $steps['extra_steps'] ?? [])
        ];

        return $this;
    }

    public function addDeployJob(string $jobName, string $environment, array $steps = []): self
    {
        $deployScript = match($environment) {
            'staging' => './scripts/deploy-staging.sh',
            'production' => './scripts/deploy-production.sh',
            default => './scripts/deploy.sh --env=' . $environment
        };

        $this->jobs[$jobName] = [
            'docker' => [
                ['image' => $steps['image'] ?? 'cimg/base:stable']
            ],
            'steps' => array_merge([
                '- checkout',
                '- attach_workspace:
                  at: /tmp',
                '- run:
                  name: Deploy to ' . $environment . '
                  command: |
                    chmod +x ' . $deployScript . '
                    ' . $deployScript,
                '- run:
                  name: Verify deployment
                  command: |
                    curl -f ' . $this->getEnvironmentUrl($environment) . '/api/health || exit 1'
            ], $steps['extra_steps'] ?? [])
        ];

        return $this;
    }

    public function addWorkflow(string $name, array $jobNames): self
    {
        $workflow = [
            $name => [
                'jobs' => []
            ]
        ];

        foreach ($jobNames as $jobName) {
            if (is_array($jobName)) {
                $workflow[$name]['jobs'][] = $jobName;
            } else {
                $workflow[$name]['jobs'][] = $jobName;
            }
        }

        $this->workflows = array_merge($this->workflows, $workflow);

        return $this;
    }

    public function addContinuousDeploymentWorkflow(): self
    {
        $this->workflows['continuous-deployment'] = [
            'jobs' => [
                ['test-and-analyze' => ['filters' => ['branches' => ['only' => '/.*/']]]],
                ['build-package' => ['filters' => ['branches' => ['only' => 'main']]]],
                ['deploy-staging' => [
                    'requires' => ['test-and-analyze', 'build-package'],
                    'filters' => ['branches' => ['only' => 'main']],
                    'type' => 'approval'
                ]],
                ['deploy-production' => [
                    'requires' => ['deploy-staging'],
                    'filters' => ['branches' => ['only' => 'main']]
                ]]
            ]
        ];

        return $this;
    }

    private function validateJobConfig(array $config): void
    {
        if (!isset($config['docker']) && !isset($config['machine'])) {
            throw new \InvalidArgumentException('Job must have docker or machine executor');
        }

        if (!isset($config['steps'])) {
            throw new \InvalidArgumentException('Job must have steps defined');
        }
    }

    private function getEnvironmentUrl(string $environment): string
    {
        return match($environment) {
            'staging' => 'https://staging.example.com',
            'production' => 'https://example.com',
            'preview' => 'https://preview-$CIRCLE_BRANCH.example.com',
            default => "https://{$environment}.example.com"
        };
    }

    public function build(): array
    {
        $config = [
            'version' => self::CONFIG_VERSION,
            'orbs' => $this->buildOrbs(),
            'jobs' => $this->jobs,
            'workflows' => $this->workflows
        ];

        return $config;
    }

    private function buildOrbs(): array
    {
        $orbs = [];

        foreach ($this->orbs as $name => $version) {
            $orbs[$name] = $version;
        }

        return $orbs;
    }

    public function save(string $filename = '.circleci/config.yml'): void
    {
        $config = $this->build();

        $yaml = $this->arrayToYaml($config);

        $configDir = getcwd() . '/.circleci';

        if (!is_dir($configDir)) {
            mkdir($configDir, 0755, true);
        }

        file_put_contents(getcwd() . '/' . $filename, $yaml);

        $this->logger->info("CircleCI config saved: {$filename}");
    }

    private function arrayToYaml(array $array, int $indent = 0): string
    {
        $yaml = '';
        $indentStr = str_repeat('  ', $indent);

        foreach ($array as $key => $value) {
            if (is_numeric($key) && is_array($value) && !$this->isAssocArray($value)) {
                foreach ($value as $item) {
                    if (is_string($item)) {
                        $yaml .= "{$indentStr}- {$item}\n";
                    } elseif (is_array($item)) {
                        $yaml .= $this->arrayToYaml($item, $indent + 1);
                    }
                }
            } elseif (is_array($value)) {
                if ($this->isAssocArray($value)) {
                    $yaml .= "{$indentStr}{$key}:\n";
                    $yaml .= $this->arrayToYaml($value, $indent + 1);
                } else {
                    $yaml .= "{$indentStr}{$key}:\n";
                    foreach ($value as $item) {
                        if (is_string($item)) {
                            $yaml .= "{$indentStr}  - {$item}\n";
                        } elseif (is_array($item)) {
                            $yaml .= "{$indentStr}  -\n";
                            $yaml .= $this->arrayToYaml($item, $indent + 2);
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

class CircleCIConfig
{
    private string $pipelineName;
    private string $defaultBranch = 'main';
    private bool $enableSlackNotifications = false;

    public function __construct(string $pipelineName)
    {
        $this->pipelineName = $pipelineName;
    }

    public function getPipelineName(): string
    {
        return $this->pipelineName;
    }

    public function getDefaultBranch(): string
    {
        return $this->defaultBranch;
    }

    public function enableSlackNotifications(): self
    {
        $this->enableSlackNotifications = true;
        return $this;
    }
}
