<?php

declare(strict_types=1);

namespace App\Composer;

class ScriptRegistry
{
    private array $scripts = [];
    private array $hooks = [];
    private ComposerIO $io;

    public function __construct(ComposerIO $io)
    {
        $this->io = $io;
        $this->registerDefaultScripts();
    }

    public function registerScript(string $name, callable $callback): void
    {
        $this->validateScriptName($name);
        $this->scripts[$name] = $callback;
    }

    public function registerHook(string $event, string $scriptName): void
    {
        if (!isset($this->scripts[$scriptName])) {
            throw new \InvalidArgumentException(
                "Cannot register hook: script '{$scriptName}' is not registered."
            );
        }

        $this->hooks[$event][] = $scriptName;
    }

    public function runScript(string $name, ...$args): int
    {
        $this->validateScriptName($name);

        if (!isset($this->scripts[$name])) {
            throw new \RuntimeException("Script not found: {$name}");
        }

        $this->io->write(sprintf("<info>Running script %s</info>", $name));

        $startTime = microtime(true);

        try {
            $result = ($this->scripts[$name])(...$args);
            $duration = round(microtime(true) - $startTime, 2);

            $this->io->write(sprintf(
                "<info>Script %s completed successfully in %ss</info>",
                $name,
                $duration
            ));

            return 0;
        } catch (\Throwable $e) {
            $this->io->writeError(sprintf(
                "<error>Script %s failed: %s</error>",
                $name,
                $e->getMessage()
            ));

            return 1;
        }
    }

    public function runHook(string $event, ...$args): int
    {
        if (!isset($this->hooks[$event])) {
            return 0;
        }

        $exitCode = 0;

        foreach ($this->hooks[$event] as $scriptName) {
            $result = $this->runScript($scriptName, ...$args);
            if ($result !== 0) {
                $exitCode = $result;
            }
        }

        return $exitCode;
    }

    private function registerDefaultScripts(): void
    {
        $this->registerScript('post-install-cmd', function (...$args) {
            $this->io->write("<info>Post-install hook triggered</info>");

            $bootstrapFile = getcwd() . '/bootstrap/cache/packages.php';
            if (file_exists($bootstrapFile)) {
                @unlink($bootstrapFile);
                $this->io->write("<comment>Cleared package bootstrap cache</comment>");
            }

            $this->generateAutoload();
        });

        $this->registerScript('post-update-cmd', function (...$args) {
            $this->io->write("<info>Post-update hook triggered</info>");

            $configCache = getcwd() . '/bootstrap/cache/config.php';
            if (file_exists($configCache)) {
                @unlink($configCache);
                $this->io->write("<comment>Cleared config cache</comment>");
            }

            $this->generateAutoload();
        });

        $this->registerScript('pre-commit', function (...$args) {
            $this->io->write("<info>Pre-commit hook running</info>");

            $phpFiles = $this->getStagedPhpFiles();

            if (empty($phpFiles)) {
                $this->io->write("<comment>No PHP files staged for commit</comment>");
                return 0;
            }

            $this->runPhpCsFixer($phpFiles);
            $this->runPhpStan($phpFiles);
            $this->runTests();

            return 0;
        });

        $this->registerScript('pre-push', function (...$args) {
            $this->io->write("<info>Pre-push hook running</info>");

            $this->checkSecurityDependencies();
            $this->runFullTestSuite();

            return 0;
        });
    }

    private function validateScriptName(string $name): void
    {
        if (!preg_match('/^[a-z][a-z0-9-]+$/', $name)) {
            throw new \InvalidArgumentException(
                "Script name must be lowercase with hyphens (e.g., post-install-cmd)."
            );
        }
    }

    private function generateAutoload(): void
    {
        $this->io->write("<info>Regenerating autoload files</info>");

        $process = new Process(['composer', 'dump-autoload', '--optimize']);
        $process->setTimeout(120);
        $process->run();

        if ($process->isSuccessful()) {
            $this->io->write("<comment>Autoload files regenerated</comment>");
        }
    }

    private function getStagedPhpFiles(): array
    {
        $process = new Process(['git', 'diff', '--cached', '--name-only', '--diff-filter=ACM']);
        $process->run();

        $files = array_filter(
            explode("\n", trim($process->getOutput())),
            fn($file) => str_ends_with($file, '.php')
        );

        return $files;
    }

    private function runPhpCsFixer(array $files): void
    {
        if (empty($files)) {
            return;
        }

        $this->io->write("<info>Running PHP CS Fixer</info>");

        $process = new Process([
            'php', 'vendor/bin/php-cs-fixer', 'fix',
            '--diff',
            '--dry-run',
            ...$files
        ]);

        $process->setTimeout(300);
        $process->run();

        if ($process->getExitCode() !== 0) {
            $this->io->writeError("<error>PHP CS Fixer found issues</error>");
        }
    }

    private function runPhpStan(array $files): void
    {
        if (empty($files)) {
            return;
        }

        $this->io->write("<info>Running PHPStan</info>");

        $process = new Process([
            'php', 'vendor/bin/phpstan', 'analyse',
            '--memory-limit=1G',
            ...$files
        ]);

        $process->setTimeout(300);
        $process->run();

        if ($process->getExitCode() !== 0) {
            $this->io->writeError("<error>PHPStan found issues</error>");
        }
    }

    private function runTests(): void
    {
        $this->io->write("<info>Running unit tests</info>");

        $process = new Process([
            'php', 'vendor/bin/phpunit',
            '--testsuite=Unit',
            '--colors=never'
        ]);

        $process->setTimeout(300);
        $process->run();

        if ($process->getExitCode() !== 0) {
            $this->io->writeError("<error>Tests failed</error>");
            throw new \RuntimeException("Unit tests failed during pre-commit hook");
        }
    }

    private function checkSecurityDependencies(): void
    {
        $this->io->write("<info>Checking for known security vulnerabilities</info>");

        $process = new Process([
            'composer', 'audit', '--format=json'
        ]);

        $process->setTimeout(120);
        $process->run();

        $output = json_decode($process->getOutput(), true);

        if (!empty($output['advisories'])) {
            $this->io->writeError("<error>Security vulnerabilities found!</error>");

            foreach ($output['advisories'] as $advisory) {
                $this->io->writeError(sprintf(
                    "  - %s: %s",
                    $advisory['cve'] ?? 'Unknown',
                    $advisory['title'] ?? 'No title'
                ));
            }

            throw new \RuntimeException("Security vulnerabilities must be resolved before pushing");
        }
    }

    private function runFullTestSuite(): void
    {
        $this->io->write("<info>Running full test suite</info>");

        $process = new Process([
            'php', 'vendor/bin/phpunit',
            '--colors=never'
        ]);

        $process->setTimeout(600);
        $process->run();

        if ($process->getExitCode() !== 0) {
            $this->io->writeError("<error>Full test suite failed</error>");
            throw new \RuntimeException("Tests must pass before pushing");
        }
    }
}
