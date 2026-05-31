<?php

declare(strict_types=1);

namespace App\Shared\Validation;

interface PrerequisiteCheckInterface
{
    public function getName(): string;
    public function getSeverity(): string;
    public function validate(): ValidationResult;
    public function getRequiredExtensions(): array;
    public function getRequiredEnvVars(): array;
}

abstract class AbstractPrerequisiteCheck implements PrerequisiteCheckInterface
{
    protected array $config;
    protected array $issues = [];

    public function getSeverity(): string
    {
        return 'error';
    }

    public function validate(): ValidationResult
    {
        $this->issues = [];
        $this->doValidate();

        return new ValidationResult($this->issues);
    }

    public function getRequiredExtensions(): array
    {
        return [];
    }

    public function getRequiredEnvVars(): array
    {
        return [];
    }

    abstract protected function doValidate(): void;

    protected function addIssue(string $message): void
    {
        $this->issues[] = [
            'name' => $this->getName(),
            'severity' => $this->getSeverity(),
            'message' => $message
        ];
    }

    protected function getConfig(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }
}

class PhpVersionCheck extends AbstractPrerequisiteCheck
{
    public function getName(): string
    {
        return 'php_version';
    }

    public function getRequiredExtensions(): array
    {
        return [];
    }

    protected function doValidate(): void
    {
        $minVersion = $this->getConfig('min_version', '8.1.0');

        if (version_compare(PHP_VERSION, $minVersion, '<')) {
            $this->addIssue("PHP {$minVersion}+ required, found: " . PHP_VERSION);
        }
    }
}

class ExtensionCheck extends AbstractPrerequisiteCheck
{
    public function getName(): string
    {
        return 'extensions';
    }

    public function getRequiredExtensions(): array
    {
        return $this->getConfig('required', []);
    }

    protected function doValidate(): void
    {
        $required = $this->getRequiredExtensions();
        $loaded = get_loaded_extensions();

        foreach ($required as $extension) {
            if (!in_array($extension, $loaded, true)) {
                $this->addIssue("Required extension missing: {$extension}");
            }
        }
    }
}

class EnvironmentCheck extends AbstractPrerequisiteCheck
{
    public function getName(): string
    {
        return 'environment';
    }

    public function getRequiredEnvVars(): array
    {
        return $this->getConfig('required', []);
    }

    protected function doValidate(): void
    {
        foreach ($this->getRequiredEnvVars() as $var) {
            $value = getenv($var);
            if ($value === false || $value === '') {
                $this->addIssue("Required environment variable not set: {$var}");
            }
        }
    }
}

class PrerequisiteChecker
{
    private array $checks = [];

    public function registerCheck(PrerequisiteCheckInterface $check): void
    {
        $this->checks[$check->getName()] = $check;
    }

    public function validateAll(array $config = []): ValidationReport
    {
        $allIssues = [];

        foreach ($this->checks as $check) {
            $check = $this->configureCheck($check, $config);
            $result = $check->validate();
            $allIssues = array_merge($allIssues, $result->getIssues());
        }

        return new ValidationReport($allIssues);
    }

    private function configureCheck(PrerequisiteCheckInterface $check, array $config): PrerequisiteCheckInterface
    {
        if ($check instanceof AbstractPrerequisiteCheck) {
            $check->config = $config;
        }

        return $check;
    }
}
