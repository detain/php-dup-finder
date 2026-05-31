<?php

declare(strict_types=1);

namespace App\Deployment;

class DeploymentPrerequisitesChecker
{
    private const EXIT_SUCCESS = 0;
    private const EXIT_VALIDATION_ERROR = 1;

    private array $failures = [];
    private array $prerequisiteChecks;

    public function __construct(?array $checks = null)
    {
        $this->prerequisiteChecks = $checks ?? $this->getDefaultPrerequisites();
    }

    public function runAllChecks(): bool
    {
        $this->failures = [];

        $this->verifyMinimumPhpVersion();
        $this->verifyRequiredPhpExtensions();
        $this->verifyEnvironmentVariables();
        $this->verifyConfigurationFiles();
        $this->verifyStorageDirectories();
        $this->verifyDatabaseConnection();
        $this->verifyCacheConnection();
        $this->verifyQueueConnection();

        return empty($this->failures);
    }

    private function getDefaultPrerequisites(): array
    {
        return [
            'php_version' => '8.1.0',
            'required_extensions' => ['pdo_mysql', 'mbstring', 'openssl', 'curl', 'json', 'tokenizer', 'xml'],
            'required_env' => ['APP_ENV', 'APP_KEY', 'DB_CONNECTION'],
            'required_configs' => ['app.php', 'database.php', 'cache.php'],
            'writable_dirs' => ['storage/app', 'storage/framework/cache', 'storage/framework/sessions', 'storage/framework/views', 'bootstrap/cache'],
            'min_memory' => '128M'
        ];
    }

    public function printResults(): void
    {
        if (empty($this->failures)) {
            echo "\n\033[32m✓ All prerequisites passed\033[0m\n\n";
            return;
        }

        echo "\n\033[31m✗ Prerequisites validation failed:\033[0m\n\n";

        foreach ($this->failures as $failure) {
            echo "  [\033[31m{$failure['severity']}\033[0m] {$failure['check']}: {$failure['message']}\n";
        }

        echo "\n";
    }

    public function getExitCode(): int
    {
        return empty($this->failures) ? self::EXIT_SUCCESS : self::EXIT_VALIDATION_ERROR;
    }

    private function verifyMinimumPhpVersion(): void
    {
        $minVersion = $this->prerequisiteChecks['php_version'];
        $currentVersion = PHP_VERSION;

        if (version_compare($currentVersion, $minVersion, '<')) {
            $this->addFailure(
                'php_version',
                'error',
                "PHP {$minVersion} required, found PHP {$currentVersion}"
            );
        }
    }

    private function verifyRequiredPhpExtensions(): void
    {
        $required = $this->prerequisiteChecks['required_extensions'];
        $loaded = get_loaded_extensions();

        foreach ($required as $extension) {
            if (!in_array($extension, $loaded, true)) {
                $this->addFailure(
                    'extension',
                    'error',
                    "Required PHP extension not loaded: {$extension}"
                );
            }
        }

        if (extension_loaded('xdebug')) {
            $this->addFailure('extension', 'warning', 'Xdebug is loaded (may impact performance)');
        }

        if (!extension_loaded('opcache')) {
            $this->addFailure('extension', 'warning', 'OPcache not loaded (recommended for production)');
        }
    }

    private function verifyEnvironmentVariables(): void
    {
        $required = $this->prerequisiteChecks['required_env'];

        foreach ($required as $envVar) {
            $value = getenv($envVar);

            if ($value === false || $value === '') {
                $this->addFailure(
                    'environment',
                    'error',
                    "Required environment variable not set: {$envVar}"
                );
                continue;
            }

            if ($envVar === 'APP_KEY') {
                if (!$this->isValidAppKey($value)) {
                    $this->addFailure(
                        'environment',
                        'error',
                        "APP_KEY appears to be invalid (should be 32+ characters base64)"
                    );
                }
            }

            if ($envVar === 'APP_ENV') {
                if (!in_array($value, ['local', 'development', 'testing', 'staging', 'production'], true)) {
                    $this->addFailure(
                        'environment',
                        'warning',
                        "APP_ENV has unusual value: {$value}"
                    );
                }

                if ($value === 'local' || $value === 'development') {
                    $appDebug = getenv('APP_DEBUG');
                    if ($appDebug !== 'true' && $appDebug !== '1') {
                        $this->addFailure(
                            'environment',
                            'warning',
                            'APP_DEBUG should be true in local/development environment'
                        );
                    }
                }
            }
        }
    }

    private function isValidAppKey(string $key): bool
    {
        if (str_starts_with($key, 'base64:')) {
            $key = substr($key, 7);
        }

        return strlen($key) >= 32 && base64_decode($key, true) !== false;
    }

    private function verifyConfigurationFiles(): void
    {
        $configs = $this->prerequisiteChecks['required_configs'];
        $configDir = getcwd() . '/config';

        if (!is_dir($configDir)) {
            $this->addFailure(
                'configuration',
                'error',
                'Config directory not found'
            );
            return;
        }

        foreach ($configs as $config) {
            $path = "{$configDir}/{$config}";

            if (!file_exists($path)) {
                $this->addFailure(
                    'configuration',
                    'error',
                    "Required config file missing: config/{$config}"
                );
            } elseif (!is_readable($path)) {
                $this->addFailure(
                    'configuration',
                    'error',
                    "Config file not readable: config/{$config}"
                );
            }
        }
    }

    private function verifyStorageDirectories(): void
    {
        $dirs = $this->prerequisiteChecks['writable_dirs'];
        $basePath = getcwd();

        foreach ($dirs as $dir) {
            $path = "{$basePath}/{$dir}";

            if (!file_exists($path)) {
                $this->addFailure(
                    'storage',
                    'error',
                    "Storage directory does not exist: {$dir}"
                );
                continue;
            }

            if (!is_dir($path)) {
                $this->addFailure(
                    'storage',
                    'error',
                    "Storage path is not a directory: {$dir}"
                );
                continue;
            }

            if (!is_writable($path)) {
                $this->addFailure(
                    'storage',
                    'error',
                    "Storage directory is not writable: {$dir}"
                );
            }
        }
    }

    private function verifyDatabaseConnection(): void
    {
        $dbUrl = getenv('DATABASE_URL');

        if (empty($dbUrl)) {
            $this->addFailure(
                'database',
                'error',
                'DATABASE_URL not configured'
            );
            return;
        }

        try {
            $parsed = parse_url($dbUrl);

            if (!$parsed || !isset($parsed['scheme'], $parsed['host'])) {
                $this->addFailure(
                    'database',
                    'error',
                    'DATABASE_URL is malformed'
                );
                return;
            }

            if (!in_array($parsed['scheme'], ['mysql', 'pgsql', 'sqlite', 'sqlsrv'], true)) {
                $this->addFailure(
                    'database',
                    'warning',
                    "Unsupported database driver: {$parsed['scheme']}"
                );
            }

            $this->addFailure('database', 'info', 'Database connection string parsed successfully');
        } catch (\Exception $e) {
            $this->addFailure(
                'database',
                'error',
                "Failed to parse DATABASE_URL: " . $e->getMessage()
            );
        }
    }

    private function verifyCacheConnection(): void
    {
        $cacheUrl = getenv('REDIS_URL') ?: getenv('CACHE_URL');

        if (empty($cacheUrl)) {
            $this->addFailure(
                'cache',
                'warning',
                'No cache connection URL configured'
            );
            return;
        }

        if (str_contains($cacheUrl, 'redis')) {
            if (!extension_loaded('redis')) {
                $this->addFailure(
                    'cache',
                    'warning',
                    'Redis extension not loaded but Redis URL configured'
                );
            }
        }
    }

    private function verifyQueueConnection(): void
    {
        $queueUrl = getenv('QUEUE_CONNECTION');

        if (empty($queueUrl)) {
            $this->addFailure(
                'queue',
                'warning',
                'QUEUE_CONNECTION not set (defaults to sync)'
            );
            return;
        }

        $validDrivers = ['sync', 'redis', 'database', 'sqs', 'beanstalkd'];

        if (!in_array($queueUrl, $validDrivers, true)) {
            $this->addFailure(
                'queue',
                'warning',
                "Unusual queue driver: {$queueUrl}"
            );
        }
    }

    private function addFailure(string $check, string $severity, string $message): void
    {
        $this->failures[] = [
            'check' => $check,
            'severity' => $severity,
            'message' => $message
        ];
    }
}
