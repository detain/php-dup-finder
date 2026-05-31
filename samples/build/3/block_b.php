<?php

declare(strict_types=1);

namespace CI\Validators;

class BuildEnvironmentValidator
{
    private array $issues = [];
    private array $checks;

    public function __construct(?array $checks = null)
    {
        $this->checks = $checks ?? $this->getDefaultChecks();
    }

    public function validate(): ValidationReport
    {
        $this->issues = [];

        $this->checkSystemRequirements();
        $this->checkPhpConfiguration();
        $this->checkDependencies();
        $this->checkFileSystem();
        $this->checkNetworkAccess();
        $this->checkSecuritySettings();

        return new ValidationReport(
            errors: array_filter($this->issues, fn($i) => $i['severity'] === 'error'),
            warnings: array_filter($this->issues, fn($i) => $i['severity'] === 'warning')
        );
    }

    private function getDefaultChecks(): array
    {
        return [
            'php_version' => '8.1.0',
            'required_extensions' => ['pdo', 'mbstring', 'openssl', 'json', 'tokenizer'],
            'required_commands' => ['git', 'composer', 'php'],
            'memory_limit' => '256M',
            'max_execution_time' => 60,
            'check_writable_dirs' => ['/tmp', 'vendor'],
            'check_readonly_config' => false
        ];
    }

    private function checkSystemRequirements(): void
    {
        $minPhp = $this->checks['php_version'];
        if (version_compare(PHP_VERSION, $minPhp, '<')) {
            $this->addIssue('error', "PHP {$minPhp}+ required, found: " . PHP_VERSION);
        }

        $requiredExtensions = $this->checks['required_extensions'] ?? [];
        $loadedExtensions = get_loaded_extensions();

        foreach ($requiredExtensions as $extension) {
            if (!in_array($extension, $loadedExtensions, true)) {
                $this->addIssue('error', "Missing required extension: {$extension}");
            }
        }

        $requiredCommands = $this->checks['required_commands'] ?? [];
        foreach ($requiredCommands as $command) {
            $fullPath = trim(shell_exec("which {$command} 2>/dev/null") ?: '');

            if (empty($fullPath)) {
                $this->addIssue('error', "Required command not found: {$command}");
            } else {
                $this->addIssue('info', "Found command: {$command} at {$fullPath}");
            }
        }
    }

    private function checkPhpConfiguration(): void
    {
        $memoryLimit = ini_get('memory_limit');
        $minMemory = $this->checks['memory_limit'] ?? '256M';

        if ($this->parseMemoryLimit($memoryLimit) < $this->parseMemoryLimit($minMemory)) {
            $this->addIssue('error', "Memory limit {$memoryLimit} is below minimum {$minMemory}");
        }

        $maxExecutionTime = ini_get('max_execution_time');
        $minTimeout = $this->checks['max_execution_time'] ?? 60;

        if ($maxExecutionTime > 0 && $maxExecutionTime < $minTimeout) {
            $this->addIssue('error', "Max execution time {$maxExecutionTime}s is below minimum {$minTimeout}s");
        }

        if (ini_get('short_open_tag')) {
            $this->addIssue('warning', 'short_open_tag is enabled - consider disabling');
        }

        if (!ini_get('expose_php')) {
            $this->addIssue('info', 'PHP exposure disabled (good for security)');
        }
    }

    private function parseMemoryLimit(string $limit): int
    {
        $limit = trim($limit);
        $value = (int)$limit;
        $unit = strtolower(substr($limit, -1));

        return match($unit) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value
        };
    }

    private function checkDependencies(): void
    {
        if (!file_exists(getcwd() . '/composer.json')) {
            $this->addIssue('error', 'composer.json not found');
            return;
        }

        if (!file_exists(getcwd() . '/composer.lock')) {
            $this->addIssue('warning', 'composer.lock not found - dependencies not locked');
            return;
        }

        $composerLockContent = file_get_contents(getcwd() . '/composer.lock');
        $lockData = json_decode($composerLockContent, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->addIssue('error', 'composer.lock is corrupted');
            return;
        }

        $packages = $lockData['packages'] ?? [];
        $devPackages = $lockData['packages-dev'] ?? [];

        $this->addIssue('info', sprintf(
            'Dependencies: %d packages, %d dev packages',
            count($packages),
            count($devPackages)
        ));

        foreach ($packages as $package) {
            if (isset($package['license'])) {
                $this->checkLicense($package['name'], $package['license']);
            }
        }
    }

    private function checkLicense(string $packageName, string $license): void
    {
        $forbiddenLicenses = ['GPL-1.0', 'GPL-2.0', 'LGPL', 'AGPL'];

        if (in_array($license, $forbiddenLicenses, true)) {
            $this->addIssue('warning', "Package {$packageName} uses {$license} license");
        }
    }

    private function checkFileSystem(): void
    {
        $writableDirs = $this->checks['check_writable_dirs'] ?? [];

        foreach ($writableDirs as $dir) {
            if (!file_exists($dir)) {
                $this->addIssue('error', "Required directory does not exist: {$dir}");
                continue;
            }

            if (!is_writable($dir)) {
                $this->addIssue('error', "Directory is not writable: {$dir}");
            }
        }

        $projectDirs = [
            'app' => 'Directory',
            'bootstrap/cache' => 'Directory',
            'config' => 'Directory',
            'public' => 'Directory',
            'resources' => 'Directory',
            'routes' => 'Directory',
            'storage' => 'Directory',
            'tests' => 'Directory',
            'vendor' => 'Directory'
        ];

        foreach ($projectDirs as $dir => $type) {
            $path = getcwd() . '/' . $dir;

            if (!file_exists($path)) {
                $this->addIssue('error', "{$type} missing: {$dir}");
            }
        }
    }

    private function checkNetworkAccess(): void
    {
        $testHosts = [
            'packagist.org' => 'https://packagist.org',
            'github.com' => 'https://github.com'
        ];

        foreach ($testHosts as $name => $url) {
            $connected = $this->testHttpConnection($url);

            if (!$connected) {
                $this->addIssue('warning', "Cannot connect to {$name} - network may be restricted");
            }
        }
    }

    private function testHttpConnection(string $url): bool
    {
        if (!function_exists('curl_init')) {
            return @file_get_contents($url, false, null, 0, 1) !== false;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true
        ]);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $result !== false && $httpCode < 500;
    }

    private function checkSecuritySettings(): void
    {
        if ($this->checks['check_readonly_config'] ?? false) {
            $configDir = getcwd() . '/config';
            if (is_dir($configDir)) {
                $perms = substr(sprintf('%o', fileperms($configDir)), -4);
                if (octdec($perms) > 0755) {
                    $this->addIssue('warning', 'Config directory should be read-only in production');
                }
            }
        }

        if (getenv('APP_KEY') === null || getenv('APP_KEY') === '') {
            $this->addIssue('error', 'APP_KEY environment variable is not set');
        }

        $envFile = getcwd() . '/.env';
        if (file_exists($envFile)) {
            $perms = substr(sprintf('%o', fileperms($envFile)), -4);
            if (octdec($perms) > 0600) {
                $this->addIssue('warning', '.env file should have more restrictive permissions');
            }
        }
    }

    private function addIssue(string $severity, string $message): void
    {
        $this->issues[] = [
            'severity' => $severity,
            'message' => $message,
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }
}
