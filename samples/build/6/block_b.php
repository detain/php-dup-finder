<?php

declare(strict_types=1);

namespace App\CI\GitLab;

class GitLabCiPipelineBuilder
{
    private const CI_TEMPLATE_DIR = '.gitlab/ci-templates';
    private const DEFAULT_IMAGE = 'php:8.2-cli';

    private PipelineConfig $config;
    private array $stages = [];
    private array $variables = [];
    private array $beforeScripts = [];

    public function __construct(PipelineConfig $config)
    {
        $this->config = $config;
        $this->initializeDefaultStages();
    }

    public function addStage(string $name, array $jobs): self
    {
        foreach ($jobs as $job) {
            $this->validateJobConfig($job);

            $job['stage'] = $name;
            $this->stages[] = $job;
        }

        return $this;
    }

    public function addTestStage(array $jobTemplates): self
    {
        foreach ($jobTemplates as $template) {
            $this->stages[] = [
                'stage' => 'test',
                'image' => $template['image'] ?? self::DEFAULT_IMAGE,
                'before_script' => array_merge(
                    $this->beforeScripts,
                    $template['before_script'] ?? []
                ),
                'script' => $template['script'],
                'tags' => $template['tags'] ?? ['php'],
                'extends' => $template['extends'] ?? null,
                'rules' => $template['rules'] ?? null,
                'coverage' => $template['coverage'] ?? null,
                'artifacts' => $template['artifacts'] ?? null
            ];
        }

        return $this;
    }

    public function addUnitTestJob(string $name, array $options = []): self
    {
        $this->stages[] = [
            'stage' => 'test',
            'name' => $name,
            'image' => $options['image'] ?? self::DEFAULT_IMAGE,
            'before_script' => array_merge($this->beforeScripts, [
                'composer install --no-interaction --prefer-dist',
                'chmod +x vendor/bin/phpunit'
            ]),
            'script' => [
                'php vendor/bin/phpunit --testsuite=Unit --colors=never'
            ],
            'tags' => $options['tags'] ?? ['php'],
            'rules' => $options['rules'] ?? [
                ['if' => '$CI_PIPELINE_SOURCE == "merge_request_event"'],
                ['if' => '$CI_COMMIT_BRANCH == "main"']
            ],
            'coverage' => '/Code coverage: \d+\.\d+%/'
        ];

        return $this;
    }

    public function addIntegrationTestJob(string $name, array $options = []): self
    {
        $this->stages[] = [
            'stage' => 'test',
            'name' => $name,
            'image' => $options['image'] ?? self::DEFAULT_IMAGE,
            'services' => [
                [
                    'name' => 'mysql:8.0',
                    'alias' => 'mysql',
                    'environment' => [
                        'MYSQL_ROOT_PASSWORD' => 'root',
                        'MYSQL_DATABASE' => 'test'
                    ]
                ]
            ],
            'before_script' => array_merge($this->beforeScripts, [
                'composer install --no-interaction --prefer-dist',
                'php artisan migrate --force --no-interaction'
            ]),
            'script' => [
                'php vendor/bin/phpunit --testsuite=Integration --colors=never'
            ],
            'tags' => $options['tags'] ?? ['php', 'docker'],
            'rules' => $options['rules'] ?? [
                ['if' => '$CI_PIPELINE_SOURCE == "merge_request_event"']
            ]
        ];

        return $this;
    }

    public function addStaticAnalysisJob(string $name, string $tool, array $options = []): self
    {
        $commands = match($tool) {
            'phpstan' => [
                './vendor/bin/phpstan analyse src --memory-limit=1G --level=' . ($options['level'] ?? 6)
            ],
            'psalm' => [
                './vendor/bin/psalm --no-cache'
            ],
            'phpcs' => [
                './vendor/bin/php-cs-fixer fix --dry-run --diff'
            ],
            'phpcpd' => [
                './vendor/bin/phpcpd --fuzzy src'
            ],
            default => throw new \InvalidArgumentException("Unknown tool: {$tool}")
        };

        $this->stages[] = [
            'stage' => 'test',
            'name' => $name,
            'image' => $options['image'] ?? self::DEFAULT_IMAGE,
            'before_script' => array_merge($this->beforeScripts, [
                'composer install --no-interaction --prefer-dist'
            ]),
            'script' => $commands,
            'tags' => $options['tags'] ?? ['php'],
            'rules' => $options['rules'] ?? [
                ['if' => '$CI_PIPELINE_SOURCE == "merge_request_event"']
            ],
            'allow_failure' => $options['allow_failure'] ?? false
        ];

        return $this;
    }

    public function addBuildStage(): self
    {
        $this->stages[] = [
            'stage' => 'build',
            'image' => self::DEFAULT_IMAGE,
            'before_script' => array_merge($this->beforeScripts, [
                'composer install --no-interaction --prefer-dist --no-dev'
            ]),
            'script' => [
                'echo "Building application..."',
                'composer dump-autoload --optimize'
            ],
            'tags' => ['php'],
            'rules' => [
                ['if' => '$CI_COMMIT_BRANCH == "main"']
            ],
            'artifacts' => [
                'name' => 'build',
                'paths' => ['vendor/', 'bootstrap/cache/'],
                'expire_in' => '1 week'
            ]
        ];

        return $this;
    }

    public function addDeployStage(string $environment, array $options = []): self
    {
        $this->stages[] = [
            'stage' => 'deploy',
            'name' => "deploy to {$environment}",
            'image' => $options['image'] ?? 'alpine:latest',
            'before_script' => array_merge($this->beforeScripts, [
                'chmod +x scripts/deploy.sh'
            ]),
            'script' => [
                './scripts/deploy.sh --environment=' . $environment
            ],
            'tags' => $options['tags'] ?? ['deploy'],
            'environment' => [
                'name' => $environment,
                'url' => $this->getEnvironmentUrl($environment)
            ],
            'rules' => $options['rules'] ?? [
                ['if' => '$CI_COMMIT_BRANCH == "main"']
            ],
            'when' => $options['when'] ?? 'manual'
        ];

        return $this;
    }

    public function addVariable(string $key, string $value): self
    {
        $this->variables[$key] = $value;

        return $this;
    }

    public function addBeforeScript(string $script): self
    {
        $this->beforeScripts[] = $script;

        return $this;
    }

    public function build(): array
    {
        return [
            'stages' => $this->getDefaultStages(),
            'variables' => array_merge($this->variables, [
                'COMPOSER_CACHE_DIR' => '/tmp/composer-cache',
                'PHP_MEMORY_LIMIT' => '256M'
            ]),
            'image' => self::DEFAULT_IMAGE,
            'cache' => $this->buildCache(),
            ...$this->stages
        ];
    }

    private function initializeDefaultStages(): void
    {
        $this->stages = [];
    }

    private function validateJobConfig(array $config): void
    {
        if (!isset($config['stage'])) {
            throw new \InvalidArgumentException('Job must have a stage defined');
        }

        if (!isset($config['script'])) {
            throw new \InvalidArgumentException('Job must have script defined');
        }
    }

    private function getDefaultStages(): array
    {
        return ['test', 'build', 'deploy'];
    }

    private function buildCache(): array
    {
        return [
            'key' => '${CI_COMMIT_REF_SLUG}',
            'paths' => [
                'vendor/',
                '.composer/',
                'node_modules/',
                '.npm/'
            ],
            'policy' => 'pull-push'
        ];
    }

    private function getEnvironmentUrl(string $environment): string
    {
        return match($environment) {
            'staging' => 'https://staging.example.com',
            'production' => 'https://example.com',
            'preview' => 'https://preview-${CI_COMMIT_REF_SLUG}.example.com',
            default => "https://{$environment}.example.com"
        };
    }

    public function save(string $filename = '.gitlab-ci.yml'): void
    {
        $pipeline = $this->build();

        $yaml = $this->arrayToYaml($pipeline);

        file_put_contents(getcwd() . '/' . $filename, $yaml);

        $this->logger->info("GitLab CI pipeline saved: {$filename}");
    }

    private function arrayToYaml(array $array, int $indent = 0): string
    {
        $yaml = '';
        $indentStr = str_repeat('  ', $indent);

        foreach ($array as $key => $value) {
            if (is_numeric($key) && is_array($value) && !$this->isAssocArray($value)) {
                $yaml .= "{$indentStr}-\n";
                foreach ($value as $k => $v) {
                    if (is_array($v)) {
                        $yaml .= "{$indentStr}  {$k}:\n";
                        $yaml .= $this->arrayToYaml($v, $indent + 2);
                    } else {
                        $yaml .= "{$indentStr}  {$k}: {$v}\n";
                    }
                }
            } elseif (is_array($value)) {
                if ($this->isAssocArray($value)) {
                    $yaml .= "{$indentStr}{$key}:\n";
                    $yaml .= $this->arrayToYaml($value, $indent + 1);
                } else {
                    $yaml .= "{$indentStr}{$key}:\n";
                    foreach ($value as $item) {
                        if (is_array($item)) {
                            $yaml .= "{$indentStr}  -\n";
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

class PipelineConfig
{
    private string $pipelineName;
    private array $stages;
    private array $variables;

    public function __construct(string $pipelineName)
    {
        $this->pipelineName = $pipelineName;
        $this->stages = ['test', 'build', 'deploy'];
        $this->variables = [];
    }

    public function getPipelineName(): string
    {
        return $this->pipelineName;
    }

    public function getStages(): array
    {
        return $this->stages;
    }

    public function getVariables(): array
    {
        return $this->variables;
    }
}
