<?php

declare(strict_types=1);

namespace App\Environment;

class EnvironmentValidator
{
    private array $errors = [];
    private array $warnings = [];
    private array $config = [];

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'min_php_version' => '8.1.0',
            'required_extensions' => ['pdo', 'mbstring', 'openssl'],
            'required_env_vars' => ['APP_ENV', 'APP_KEY'],
            'required_files' => ['.env', 'composer.json'],
            'check_permissions' => true,
            'validate_composer' => true
        ], $config);
    }

    public function validate(): ValidationResult
    {
        $this->errors = [];
        $this->warnings = [];

        $this->checkPhpVersion();
        $this->checkExtensions();
        $this->checkEnvironmentVariables();
        $this->checkRequiredFiles();
        $this->checkPermissions();
        $this->validateComposerJson();
        $this->checkComposerLock();
        $this->validateConfiguration();

        return new ValidationResult($this->errors, $this->warnings);
    }

    private function checkPhpVersion(): void
    {
        $minVersion = $this->config['min_php_version'];
        $currentVersion = PHP_VERSION;

        if (version_compare($currentVersion, $minVersion, '<')) {
            $this->errors[] = sprintf(
                'PHP version %s is required, but %s is installed',
                $minVersion,
                $currentVersion
            );
        }

        if (defined('PHP_VERSION_ID')) {
            $recommendedVersion = '8.2.0';
            if (version_compare($currentVersion, $recommendedVersion, '<')) {
                $this->warnings[] = sprintf(
                    'PHP %s or higher is recommended for optimal performance',
                    $recommendedVersion
                );
            }
        }
    }

    private function checkExtensions(): void
    {
        $required = $this->config['required_extensions'];
        $loaded = get_loaded_extensions();

        foreach ($required as $extension) {
            if (!in_array($extension, $loaded, true)) {
                $this->errors[] = "Required PHP extension missing: {$extension}";
            }
        }

        $recommended = ['opcache', 'redis', 'apcu'];
        foreach ($recommended as $extension) {
            if (!in_array($extension, $loaded, true)) {
                $this->warnings[] = "Recommended PHP extension not loaded: {$extension}";
            }
        }

        if (in_array('opcache', $loaded, true)) {
            $opcacheEnabled = ini_get('opcache.enable');
            if (!$opcacheEnabled) {
                $this->warnings[] = 'OPcache is loaded but not enabled';
            }
        }
    }

    private function checkEnvironmentVariables(): void
    {
        $required = $this->config['required_env_vars'];

        foreach ($required as $var) {
            $value = getenv($var);
            if ($value === false || $value === '') {
                $this->errors[] = "Required environment variable not set: {$var}";
            }
        }

        $appEnv = getenv('APP_ENV') ?: 'production';
        if (!in_array($appEnv, ['local', 'development', 'staging', 'production'], true)) {
            $this->warnings[] = "Unusual APP_ENV value: {$appEnv}";
        }

        if ($appEnv === 'production' || $appEnv === 'staging') {
            if ($this->isDebugEnabled()) {
                $this->errors[] = 'Debug mode must be disabled in production/staging';
            }
        }
    }

    private function isDebugEnabled(): bool
    {
        $debug = getenv('APP_DEBUG');
        return $debug === 'true' || $debug === '1' || strtolower($debug) === 'true';
    }

    private function checkRequiredFiles(): void
    {
        $required = $this->config['required_files'];
        $basePath = getcwd();

        foreach ($required as $file) {
            $path = $basePath . '/' . $file;
            if (!file_exists($path)) {
                $this->errors[] = "Required file missing: {$file}";
            }
        }

        if (file_exists($basePath . '/.env')) {
            $this->validateEnvFile($basePath . '/.env');
        }
    }

    private function validateEnvFile(string $path): void
    {
        $content = file_get_contents($path);
        $lines = explode("\n", $content);

        $requiredKeys = ['APP_KEY', 'APP_ENV', 'DB_CONNECTION'];
        $foundKeys = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || str_starts_with($line, '#')) {
                continue;
            }

            if (str_contains($line, '=')) {
                $key = explode('=', $line, 2)[0];
                $foundKeys[] = $key;
            }
        }

        foreach ($requiredKeys as $key) {
            if (!in_array($key, $foundKeys, true)) {
                $this->warnings[] = "Recommended .env key not found: {$key}";
            }
        }

        $appKey = getenv('APP_KEY') ?: '';
        if (empty($appKey) || strlen($appKey) < 32) {
            $this->errors[] = 'APP_KEY is missing or invalid in .env';
        }
    }

    private function checkPermissions(): void
    {
        if (!$this->config['check_permissions']) {
            return;
        }

        $writablePaths = [
            'storage',
            'storage/app',
            'storage/framework',
            'storage/framework/cache',
            'storage/framework/sessions',
            'storage/framework/views',
            'storage/logs',
            'bootstrap/cache',
            'public/uploads'
        ];

        $basePath = getcwd();

        foreach ($writablePaths as $path) {
            $fullPath = $basePath . '/' . $path;

            if (!file_exists($fullPath)) {
                $this->warnings[] = "Path does not exist: {$path}";
                continue;
            }

            if (!is_writable($fullPath)) {
                $this->errors[] = "Path is not writable: {$path}";
            }
        }
    }

    private function validateComposerJson(): void
    {
        if (!$this->config['validate_composer']) {
            return;
        }

        $composerPath = getcwd() . '/composer.json';

        if (!file_exists($composerPath)) {
            $this->errors[] = 'composer.json not found';
            return;
        }

        $content = file_get_contents($composerPath);
        $json = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->errors[] = 'composer.json is not valid JSON';
            return;
        }

        if (!isset($json['require']['php'])) {
            $this->errors[] = 'composer.json missing PHP requirement';
        }

        if (!isset($json['autoload'])) {
            $this->warnings[] = 'composer.json missing autoload section';
        }
    }

    private function checkComposerLock(): void
    {
        $basePath = getcwd();
        $composerJsonMtime = filemtime($basePath . '/composer.json');
        $composerLockMtime = @filemtime($basePath . '/composer.lock');

        if ($composerLockMtime === false) {
            $this->warnings[] = 'composer.lock is missing - run composer install';
            return;
        }

        if ($composerJsonMtime > $composerLockMtime) {
            $this->warnings[] = 'composer.json is newer than composer.lock - run composer update';
        }
    }

    private function validateConfiguration(): void
    {
        $databaseUrl = getenv('DATABASE_URL');

        if (empty($databaseUrl)) {
            $this->warnings[] = 'DATABASE_URL is not set';
            return;
        }

        $parsed = parse_url($databaseUrl);

        if ($parsed === false || !isset($parsed['scheme'], $parsed['host'])) {
            $this->errors[] = 'DATABASE_URL is malformed';
            return;
        }

        $supportedDrivers = ['mysql', 'pgsql', 'sqlite', 'sqlsrv'];

        if (!in_array($parsed['scheme'], $supportedDrivers, true)) {
            $this->warnings[] = "Unsupported database driver: {$parsed['scheme']}";
        }
    }
}
