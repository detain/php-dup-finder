<?php

declare(strict_types=1);

namespace App\BuildSystem\Core;

interface BuildHookInterface
{
    public function getName(): string;
    public function execute(BuildContext $context): void;
    public function getTimeout(): int;
    public function getPriority(): int;
}

abstract class AbstractBuildHook implements BuildHookInterface
{
    protected LoggerInterface $logger;
    protected int $timeout = 300;
    protected int $priority = 0;

    public function getTimeout(): int
    {
        return $this->timeout;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function execute(BuildContext $context): void
    {
        $this->logger->info("Executing hook: {$this->getName()}");

        $startTime = microtime(true);

        try {
            $this->doExecute($context);

            $this->logger->info("Hook completed", [
                'hook' => $this->getName(),
                'duration' => round(microtime(true) - $startTime, 2)
            ]);
        } catch (\Throwable $e) {
            $this->logger->error("Hook failed", [
                'hook' => $this->getName(),
                'duration' => round(microtime(true) - $startTime, 2),
                'error' => $e->getMessage()
            ]);

            throw new \RuntimeException(
                "Hook '{$this->getName()}' failed: " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    abstract protected function doExecute(BuildContext $context): void;
}

class ValidateEnvironmentHook extends AbstractBuildHook
{
    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function getName(): string
    {
        return 'validate-environment';
    }

    protected function doExecute(BuildContext $context): void
    {
        foreach ($context->getRequiredEnvVars() as $var) {
            if (empty(getenv($var))) {
                throw new \RuntimeException("Missing required env var: {$var}");
            }
        }

        $this->validatePhpVersion($context->getMinPhpVersion());
        $this->validateExtensions($context->getRequiredExtensions());
    }

    private function validatePhpVersion(string $minVersion): void
    {
        if (version_compare(PHP_VERSION, $minVersion, '<')) {
            throw new \RuntimeException(
                "PHP {$minVersion}+ required, found: " . PHP_VERSION
            );
        }
    }

    private function validateExtensions(array $extensions): void
    {
        $missing = array_filter($extensions, fn($ext) => !extension_loaded($ext));

        if (!empty($missing)) {
            throw new \RuntimeException(
                "Missing PHP extensions: " . implode(', ', $missing)
            );
        }
    }
}

class ClearCacheHook extends AbstractBuildHook
{
    private array $cachePaths;

    public function __construct(LoggerInterface $logger, array $cachePaths)
    {
        $this->logger = $logger;
        $this->cachePaths = $cachePaths;
    }

    public function getName(): string
    {
        return 'clear-cache';
    }

    protected function doExecute(BuildContext $context): void
    {
        foreach ($this->cachePaths as $path) {
            if (is_dir($path)) {
                array_map('unlink', glob("{$path}/*"));
                $this->logger->info("Cleared cache: {$path}");
            }
        }

        if (function_exists('opcache_reset')) {
            opcache_reset();
        }
    }
}

class HookRegistry
{
    private array $hooks = [];
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function register(BuildHookInterface $hook): void
    {
        $this->hooks[$hook->getName()] = $hook;
    }

    public function getByName(string $name): ?BuildHookInterface
    {
        return $this->hooks[$name] ?? null;
    }

    public function executeAll(array $hookNames, BuildContext $context): void
    {
        $hooks = array_filter(
            array_map([$this, 'getByName'], $hookNames)
        );

        usort($hooks, fn($a, $b) => $a->getPriority() <=> $b->getPriority());

        foreach ($hooks as $hook) {
            $hook->execute($context);
        }
    }
}
