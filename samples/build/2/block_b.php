<?php

declare(strict_types=1);

namespace App\Deployment;

class DeploymentHooks
{
    private array $preDeployHooks = [];
    private array $postDeployHooks = [];
    private DeploymentLogger $logger;

    public function __construct(DeploymentLogger $logger)
    {
        $this->logger = $logger;
    }

    public function registerPreDeploy(string $name, callable $hook): void
    {
        $this->validateHookName($name);
        $this->preDeployHooks[$name] = $hook;
    }

    public function registerPostDeploy(string $name, callable $hook): void
    {
        $this->validateHookName($name);
        $this->postDeployHooks[$name] = $hook;
    }

    public function executePreDeploy(DeploymentContext $context): void
    {
        $this->logger->info("Executing pre-deploy hooks", ['count' => count($this->preDeployHooks)]);

        foreach ($this->preDeployHooks as $name => $hook) {
            $this->executeHook($name, $hook, $context);
        }
    }

    public function executePostDeploy(DeploymentContext $context): void
    {
        $this->logger->info("Executing post-deploy hooks", ['count' => count($this->postDeployHooks)]);

        foreach ($this->postDeployHooks as $name => $hook) {
            $this->executeHook($name, $hook, $context);
        }
    }

    private function executeHook(string $name, callable $hook, DeploymentContext $context): void
    {
        $startTime = microtime(true);

        try {
            $this->logger->info("Running hook: {$name}");

            $result = $hook($context);

            $duration = round(microtime(true) - $startTime, 2);

            $this->logger->info("Hook completed", [
                'hook' => $name,
                'duration' => $duration,
                'success' => true
            ]);
        } catch (\Throwable $e) {
            $duration = round(microtime(true) - $startTime, 2);

            $this->logger->error("Hook failed", [
                'hook' => $name,
                'duration' => $duration,
                'error' => $e->getMessage()
            ]);

            throw new \RuntimeException(
                "Hook '{$name}' failed: " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    private function validateHookName(string $name): void
    {
        if (!preg_match('/^[a-z][a-z0-9-]+$/', $name)) {
            throw new \InvalidArgumentException(
                "Hook name must be lowercase with hyphens."
            );
        }
    }

    public function createDefaultHooks(): void
    {
        $this->registerPreDeploy('validate-environment', function (DeploymentContext $ctx) {
            $this->logger->info("Validating deployment environment");

            $requiredVars = [
                'APP_ENV',
                'APP_DEBUG',
                'DATABASE_URL',
                'REDIS_URL',
                'AWS_ACCESS_KEY_ID'
            ];

            foreach ($requiredVars as $var) {
                if (empty(getenv($var))) {
                    throw new \RuntimeException("Required environment variable missing: {$var}");
                }
            }

            $phpVersion = PHP_VERSION;
            if (version_compare($phpVersion, '8.1.0', '<')) {
                throw new \RuntimeException("PHP 8.1+ required, found: {$phpVersion}");
            }

            $requiredExtensions = ['pdo', 'mbstring', 'openssl', 'redis'];
            $missingExtensions = [];

            foreach ($requiredExtensions as $ext) {
                if (!extension_loaded($ext)) {
                    $missingExtensions[] = $ext;
                }
            }

            if (!empty($missingExtensions)) {
                throw new \RuntimeException(
                    "Missing PHP extensions: " . implode(', ', $missingExtensions)
                );
            }

            $this->logger->info("Environment validation passed");
        });

        $this->registerPreDeploy('backup-database', function (DeploymentContext $ctx) {
            $this->logger->info("Creating database backup before deployment");

            $timestamp = date('Y-m-d-His');
            $backupPath = "/backups/{$ctx->getEnvironment()}/{$timestamp}";

            $this->runCommand([
                'pg_dump',
                $ctx->get('DATABASE_URL'),
                '--file', "{$backupPath}/database.sql",
                '--format=c',
                '--compress=9'
            ]);

            $this->runCommand([
                'tar',
                '-czf',
                "{$backupPath}/storage.tar.gz",
                getcwd() . '/storage/app'
            ]);

            $this->runCommand([
                'tar',
                '-czf',
                "{$backupPath}/uploads.tar.gz",
                getcwd() . '/public/uploads'
            ]);

            $this->logger->info("Database backup created at: {$backupPath}");

            return $backupPath;
        });

        $this->registerPreDeploy('clear-caches', function (DeploymentContext $ctx) {
            $this->logger->info("Clearing application caches");

            $cachePaths = [
                getcwd() . '/bootstrap/cache',
                getcwd() . '/storage/framework/cache',
                getcwd() . '/storage/framework/views',
                getcwd() . '/storage/framework/sessions'
            ];

            foreach ($cachePaths as $path) {
                if (is_dir($path)) {
                    $this->runCommand(['rm', '-rf', "{$path}/*"]);
                    $this->logger->info("Cleared: {$path}");
                }
            }

            if (function_exists('opcache_get_status')) {
                if (function_exists('opcache_reset')) {
                    opcache_reset();
                    $this->logger->info("OPcache reset");
                }
            }

            $this->logger->info("Cache clearing completed");
        });

        $this->registerPostDeploy('migrate-database', function (DeploymentContext $ctx) {
            $this->logger->info("Running database migrations");

            $process = new Process([
                'php',
                getcwd() . '/artisan',
                'migrate',
                '--force',
                '--no-interaction'
            ]);

            $process->setTimeout(300);
            $process->run();

            if (!$process->isSuccessful()) {
                throw new \RuntimeException(
                    "Migration failed: " . $process->getErrorOutput()
                );
            }

            $this->logger->info("Migrations completed successfully");
        });

        $this->registerPostDeploy('warm-caches', function (DeploymentContext $ctx) {
            $this->logger->info("Warming up application caches");

            $routesToWarm = [
                '/api/health',
                '/api/config',
                '/api/user'
            ];

            $baseUrl = $ctx->get('APP_URL');

            foreach ($routesToWarm as $route) {
                $this->runCommand([
                    'curl',
                    '-s',
                    '-o',
                    '/dev/null',
                    '-w',
                    '%{http_code}',
                    "{$baseUrl}{$route}"
                ]);

                usleep(100000);
            }

            $this->logger->info("Cache warming completed");
        });

        $this->registerPostDeploy('notify-slack', function (DeploymentContext $ctx) {
            $this->logger->info("Sending deployment notification to Slack");

            $webhookUrl = getenv('SLACK_WEBHOOK_URL');

            if (empty($webhookUrl)) {
                $this->logger->warning("SLACK_WEBHOOK_URL not set, skipping notification");
                return;
            }

            $payload = [
                'text' => "Deployment completed",
                'attachments' => [
                    [
                        'color' => '#36a64f',
                        'fields' => [
                            ['title' => 'Environment', 'value' => $ctx->getEnvironment(), 'short' => true],
                            ['title' => 'Version', 'value' => $ctx->getVersion(), 'short' => true],
                            ['title' => 'Deployed By', 'value' => $ctx->getDeployedBy(), 'short' => true],
                            ['title' => 'Timestamp', 'value' => date('Y-m-d H:i:s'), 'short' => true]
                        ]
                    ]
                ]
            ];

            $this->postToSlack($webhookUrl, $payload);

            $this->logger->info("Slack notification sent");
        });
    }

    private function runCommand(array $command): array
    {
        $process = new Process($command);
        $process->setTimeout(120);
        $process->run();

        return [
            'exitCode' => $process->getExitCode(),
            'output' => $process->getOutput()
        ];
    }

    private function postToSlack(string $webhookUrl, array $payload): void
    {
        $ch = curl_init($webhookUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true
        ]);

        curl_exec($ch);
        curl_close($ch);
    }
}
