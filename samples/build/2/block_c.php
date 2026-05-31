<?php

declare(strict_types=1);

namespace App\BuildSystem;

class BuildScriptExecutor
{
    private const SCRIPT_DIR = '/opt/build/scripts/';
    private array $registeredScripts = [];
    private BuildOutputFormatter $formatter;

    public function __construct(BuildOutputFormatter $formatter)
    {
        $this->formatter = $formatter;
    }

    public function registerScript(string $name, string $scriptPath, array $options = []): void
    {
        $this->validateScriptName($name);
        $this->validateScriptExists($scriptPath);

        $this->registeredScripts[$name] = [
            'path' => $scriptPath,
            'options' => $options,
            'timeout' => $options['timeout'] ?? 300,
            'runAs' => $options['runAs'] ?? null,
            'env' => $options['env'] ?? []
        ];
    }

    public function executeScript(string $name, array $args = []): int
    {
        if (!isset($this->registeredScripts[$name])) {
            throw new \InvalidArgumentException("Script not registered: {$name}");
        }

        $script = $this->registeredScripts[$name];

        $this->formatter->startScript($name, $script['path']);

        $startTime = microtime(true);
        $process = $this->createProcess($script, $args);

        try {
            $process->run();

            $exitCode = $process->getExitCode();
            $duration = round(microtime(true) - $startTime, 2);

            $this->formatter->finishScript(
                $name,
                $exitCode,
                $duration,
                $process->getOutput(),
                $process->getErrorOutput()
            );

            return $exitCode;
        } catch (\Exception $e) {
            $duration = round(microtime(true) - $startTime, 2);

            $this->formatter->scriptError($name, $e->getMessage(), $duration);

            throw new \RuntimeException(
                "Script '{$name}' threw exception: " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    private function createProcess(array $script, array $args): Process
    {
        $command = [$script['path'], ...$args];

        $process = new Process($command);
        $process->setTimeout($script['timeout']);

        if (!empty($script['env'])) {
            $process->setEnv($script['env']);
        }

        if ($script['runAs']) {
            $process->setEnv(['USER' => $script['runAs']]);
        }

        return $process;
    }

    private function validateScriptName(string $name): void
    {
        if (!preg_match('/^[a-z][a-z0-9-]+$/', $name)) {
            throw new \InvalidArgumentException(
                "Script name must be lowercase alphanumeric with hyphens."
            );
        }
    }

    private function validateScriptExists(string $path): void
    {
        if (!file_exists($path)) {
            throw new \InvalidArgumentException("Script not found: {$path}");
        }

        if (!is_executable($path)) {
            throw new \InvalidArgumentException("Script is not executable: {$path}");
        }
    }

    public function createDefaultBuildScripts(): void
    {
        $this->registerScript('install-dependencies', self::SCRIPT_DIR . 'install-deps.sh', [
            'timeout' => 600,
            'description' => 'Install composer and npm dependencies'
        ]);

        $this->registerScript('run-tests', self::SCRIPT_DIR . 'run-tests.sh', [
            'timeout' => 300,
            'description' => 'Execute test suite'
        ]);

        $this->registerScript('lint-code', self::SCRIPT_DIR . 'lint-code.sh', [
            'timeout' => 180,
            'description' => 'Run code linting tools'
        ]);

        $this->registerScript('compile-assets', self::SCRIPT_DIR . 'compile-assets.sh', [
            'timeout' => 300,
            'description' => 'Compile and minify frontend assets'
        ]);

        $this->registerScript('security-audit', self::SCRIPT_DIR . 'security-audit.sh', [
            'timeout' => 120,
            'description' => 'Run security checks'
        ]);
    }

    public function executeAll(array $scriptNames, array $args = []): int
    {
        $failedScripts = [];

        foreach ($scriptNames as $name) {
            $exitCode = $this->executeScript($name, $args);

            if ($exitCode !== 0) {
                $failedScripts[] = $name;
            }
        }

        if (!empty($failedScripts)) {
            $this->formatter->summary(count($failedScripts), count($scriptNames));
            return 1;
        }

        $this->formatter->summary(0, count($scriptNames));
        return 0;
    }
}

class BuildOutputFormatter
{
    private const COLORS = [
        'reset' => "\033[0m",
        'red' => "\033[31m",
        'green' => "\033[32m",
        'yellow' => "\033[33m",
        'blue' => "\033[34m"
    ];

    public function startScript(string $name, string $path): void
    {
        printf(
            "%s[%s] Starting: %s%s\n",
            self::COLORS['blue'],
            date('H:i:s'),
            $name,
            self::COLORS['reset']
        );
    }

    public function finishScript(
        string $name,
        int $exitCode,
        float $duration,
        string $output,
        string $errorOutput
    ): void {
        $status = $exitCode === 0 ? 'SUCCESS' : 'FAILED';
        $color = $exitCode === 0 ? self::COLORS['green'] : self::COLORS['red'];

        printf(
            "%s[%s] %s completed in %.2fs (exit code: %d)%s\n",
            $color,
            date('H:i:s'),
            $name,
            $duration,
            $exitCode,
            self::COLORS['reset']
        );

        if (!empty($output)) {
            printf("\n--- Output ---\n%s\n", $output);
        }

        if (!empty($errorOutput)) {
            printf("\n--- Errors ---\n%s\n", $errorOutput);
        }
    }

    public function scriptError(string $name, string $message, float $duration): void
    {
        printf(
            "%s[%s] ERROR in %s: %s (after %.2fs)%s\n",
            self::COLORS['red'],
            date('H:i:s'),
            $name,
            $message,
            $duration,
            self::COLORS['reset']
        );
    }

    public function summary(int $failed, int $total): void
    {
        if ($failed === 0) {
            printf(
                "%s[%s] All %d scripts completed successfully!%s\n",
                self::COLORS['green'],
                date('H:i:s'),
                $total,
                self::COLORS['reset']
            );
        } else {
            printf(
                "%s[%s] %d/%d scripts failed%s\n",
                self::COLORS['red'],
                date('H:i:s'),
                $failed,
                $total,
                self::COLORS['reset']
            );
        }
    }
}
