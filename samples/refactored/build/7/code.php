<?php

declare(strict_types=1);

namespace App\BuildSystem\Core;

interface EnvironmentValidatorInterface
{
    public function validate(): ValidationResult;
    public function getName(): string;
}

abstract class AbstractEnvironmentValidator implements EnvironmentValidatorInterface
{
    protected LoggerInterface $logger;
    protected array $issues = [];

    public function validate(): ValidationResult
    {
        $this->issues = [];

        $this->doValidate();

        return new ValidationResult($this->issues);
    }

    abstract protected function doValidate(): void;

    protected function addIssue(string $severity, string $message): void
    {
        $this->issues[] = [
            'severity' => $severity,
            'message' => $message,
            'validator' => $this->getName()
        ];
    }

    protected function checkFileExists(string $path, string $description): void
    {
        if (!file_exists($path)) {
            $this->addIssue('error', "{$description} not found: {$path}");
        }
    }

    protected function checkDirectoryExists(string $path, string $description): void
    {
        if (!is_dir($path)) {
            $this->addIssue('error', "{$description} not found: {$path}");
        }
    }

    protected function checkExecutable(string $command, string $description): void
    {
        $fullPath = trim(shell_exec("which {$command} 2>/dev/null") ?: '');

        if (empty($fullPath)) {
            $this->addIssue('error', "{$description} not found in PATH");
        }
    }

    protected function checkVersion(string $actual, string $minimum, string $description): void
    {
        if (version_compare($actual, $minimum, '<')) {
            $this->addIssue('error', "{$description} version {$minimum}+ required, found: {$actual}");
        }
    }

    protected function checkDiskSpace(string $path, int $minBytes): void
    {
        $freeSpace = disk_free_space($path);

        if ($freeSpace === false) {
            return;
        }

        if ($freeSpace < $minBytes) {
            $this->addIssue('error', "Insufficient disk space. Required: " . $this->formatBytes($minBytes) . ", Available: " . $this->formatBytes($freeSpace));
        }
    }

    protected function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];

        foreach ($units as $i => $unit) {
            if ($bytes < 1024 || $i === count($units) - 1) {
                return round($bytes, 2) . ' ' . $unit;
            }
            $bytes /= 1024;
        }

        return '0 B';
    }
}

class JavaValidator extends AbstractEnvironmentValidator
{
    private string $minVersion = '17';
    private ?string $javaHome = null;

    public function getName(): string
    {
        return 'java';
    }

    protected function doValidate(): void
    {
        $this->javaHome = getenv('JAVA_HOME') ?: $this->findJavaHome();

        if ($this->javaHome === null || !is_dir($this->javaHome)) {
            $this->addIssue('error', 'JAVA_HOME is not set or directory does not exist');
            return;
        }

        $javaBinary = "{$this->javaHome}/bin/java";

        if (!file_exists($javaBinary)) {
            $this->addIssue('error', "Java binary not found at: {$javaBinary}");
            return;
        }

        $version = $this->getJavaVersion($javaBinary);

        $this->checkVersion($version, $this->minVersion, 'Java');
    }

    private function findJavaHome(): ?string
    {
        $paths = [
            '/usr/lib/jvm/java-17-openjdk-amd64',
            '/usr/lib/jvm/java-21-openjdk-amd64',
            '/opt/java/openjdk-17',
            '/opt/java/openjdk-21'
        ];

        foreach ($paths as $path) {
            if (is_dir($path)) {
                return $path;
            }
        }

        return null;
    }

    private function getJavaVersion(string $binary): string
    {
        $process = new Process([$binary, '-version', '2>&1']);
        $process->setTimeout(5);
        $process->run();

        if (preg_match('/version\s+"([^"]+)"/', $process->getOutput(), $matches)) {
            return $matches[1];
        }

        return '0.0.0';
    }
}

class BuildOrchestrator
{
    private array $validators = [];
    private LoggerInterface $logger;

    public function registerValidator(EnvironmentValidatorInterface $validator): void
    {
        $this->validators[] = $validator;
    }

    public function validateAll(): ValidationReport
    {
        $allIssues = [];

        foreach ($this->validators as $validator) {
            $result = $validator->validate();
            $allIssues = array_merge($allIssues, $result->getIssues());
        }

        return new ValidationReport($allIssues);
    }
}
