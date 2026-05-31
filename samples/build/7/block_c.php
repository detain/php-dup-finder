<?php

declare(strict_types=1);

namespace App\BuildSystem\Go;

class GoBuildManager
{
    private const GO_VERSION_MIN = '1.21';
    private const GOPATH_DEFAULT = '$HOME/go';

    private GoBuildEnvironment $environment;
    private array $buildFlags = [];
    private array $ldFlags = [];
    private array $tags = [];

    public function __construct(GoBuildEnvironment $environment)
    {
        $this->environment = $environment;
    }

    public function validateEnvironment(): void
    {
        $this->checkGoInstallation();
        $this->validateGoModules();
        $this->checkGoPathSetup();
        $this->verifyModuleStructure();
        $this->checkCgoAvailability();
        $this->validateExternalDependencies();
        $this->checkBuildConstraints();
    }

    private function checkGoInstallation(): void
    {
        $goPath = getenv('GOPATH') ?: str_replace('$HOME', getenv('HOME') ?: '~', self::GOPATH_DEFAULT);

        $this->environment->setGoPath($goPath);

        $command = ['go', 'version'];

        $process = new Process($command);
        $process->setTimeout(10);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new GoBuildException(
                "Go is not installed or not in PATH"
            );
        }

        $output = $process->getOutput();

        if (preg_match('/go version go(\d+\.\d+\.\d+)/', $output, $matches)) {
            $version = $matches[1];

            if (version_compare($version, self::GO_VERSION_MIN, '<')) {
                throw new GoBuildException(
                    "Go " . self::GO_VERSION_MIN . "+ required, found: {$version}"
                );
            }

            $this->environment->setGoVersion($version);
        }

        $gocmd = trim(shell_exec('which go') ?: '');

        if (!empty($gocmd)) {
            $this->environment->setGoPath($gocmd);
        }
    }

    private function validateGoModules(): void
    {
        $goModFile = getcwd() . '/go.mod';

        if (!file_exists($goModFile)) {
            throw new GoBuildException(
                "go.mod not found. Initialize with: go mod init"
            );
        }

        $content = file_get_contents($goModFile);

        if (!preg_match('/^module\s+/m', $content)) {
            throw new GoBuildException(
                "go.mod missing module declaration"
            );
        }

        if (!preg_match('/^go\s+/m', $content)) {
            throw new GoBuildException(
                "go.mod missing go version directive"
            );
        }

        if (preg_match('/^go\s+(\d+\.\d+)/m', $content, $matches)) {
            $goVersion = $matches[1];

            if (version_compare($goVersion, self::GO_VERSION_MIN, '<')) {
                throw new GoBuildException(
                    "go.mod requires Go {$goVersion}, but minimum supported is " . self::GO_VERSION_MIN
                );
            }

            $this->environment->setModuleGoVersion($goVersion);
        }
    }

    private function checkGoPathSetup(): void
    {
        $gopath = $this->environment->getGoPath();

        $srcDir = $gopath . '/src';

        if (!is_dir($gopath)) {
            mkdir($gopath, 0755, true);
        }

        if (!is_writable($gopath)) {
            $this->logger->warning("GOPATH {$gopath} is not writable");
        }

        $moduleCache = getenv('GOMODCACHE') ?: $gopath . '/pkg/mod';

        if (!is_dir($moduleCache)) {
            mkdir($moduleCache, 0755, true);
        }

        $this->environment->setModuleCachePath($moduleCache);
    }

    private function verifyModuleStructure(): void
    {
        $goModFile = getcwd() . '/go.mod';
        $content = file_get_contents($goModFile);

        preg_match('/^module\s+([^\s]+)/m', $content, $moduleMatch);
        $moduleName = $moduleMatch[1] ?? '';

        if (empty($moduleName)) {
            throw new GoBuildException("Could not determine module name from go.mod");
        }

        $this->environment->setModuleName($moduleName);

        $mainFile = getcwd() . '/cmd/main.go';

        if (file_exists($mainFile)) {
            $this->environment->setHasMainPackage(true);
        }

        $pkgDir = getcwd() . '/pkg';

        if (is_dir($pkgDir)) {
            $this->environment->setHasSharedPackage(true);
        }
    }

    private function checkCgoAvailability(): void
    {
        $cgoEnabled = getenv('CGO_ENABLED');

        if ($cgoEnabled === '0') {
            $this->environment->setCgoEnabled(false);
            return;
        }

        $command = ['go', 'env', 'CGO_ENABLED'];

        $process = new Process($command);
        $process->setTimeout(5);
        $process->run();

        $cgoAvailable = trim($process->getOutput()) === '1';

        $this->environment->setCgoEnabled($cgoAvailable);

        if (!$cgoAvailable) {
            $this->logger->info("CGO is disabled - C extensions will not be built");
        }
    }

    private function validateExternalDependencies(): void
    {
        $command = ['go', 'mod', 'verify'];

        $process = new Process($command);
        $process->setTimeout(120);
        $process->setWorkingDirectory(getcwd());
        $process->run();

        if (!$process->isSuccessful()) {
            $this->logger->warning(
                "go mod verify failed - dependencies may be corrupted"
            );
        }

        $command = ['go', 'mod', 'tidy'];

        $process = new Process($command);
        $process->setTimeout(180);
        $process->setWorkingDirectory(getcwd());
        $process->run();

        if (!$process->isSuccessful()) {
            throw new GoBuildException(
                "go mod tidy failed: " . $process->getErrorOutput()
            );
        }

        $this->logger->info("Dependencies verified and tidied");
    }

    private function checkBuildConstraints(): void
    {
        $goFiles = $this->findGoFiles(getcwd() . '/cmd');

        foreach ($goFiles as $file) {
            $content = file_get_contents($file);

            if (preg_match('///\+build\s+([^\n]+)/', $content, $matches)) {
                $constraints = $matches[1];

                if (str_contains($constraints, 'ignore')) {
                    $this->logger->info("Build constraint found in: {$file}");
                }
            }

            if (preg_match('///go:build\s+([^\n]+)/', $content, $matches)) {
                $constraints = $matches[1];

                $this->logger->debug("Go 1.17+ build constraint in: {$file}");
            }
        }
    }

    private function findGoFiles(string $dir): array
    {
        $files = [];

        if (!is_dir($dir)) {
            return $files;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() === 'go') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    public function addBuildFlag(string $flag): self
    {
        $this->buildFlags[] = $flag;

        return $this;
    }

    public function addLdFlag(string $key, string $value): self
    {
        $this->ldFlags[$key] = $value;

        return $this;
    }

    public function addTag(string $tag): self
    {
        $this->tags[] = $tag;

        return $this;
    }

    public function build(string $outputPath = './bin/app'): GoBuildResult
    {
        $this->validateEnvironment();

        $startTime = microtime(true);

        $command = $this->buildCommand($outputPath);

        $process = new Process($command);
        $process->setTimeout(600);
        $process->setWorkingDirectory(getcwd());

        $process->run();

        $duration = round(microtime(true) - $startTime, 2);

        $result = new GoBuildResult(
            success: $process->isSuccessful(),
            outputPath: $process->isSuccessful() ? $outputPath : null,
            command: implode(' ', $command),
            exitCode: $process->getExitCode(),
            output: $process->getOutput(),
            error: $process->getErrorOutput(),
            duration: $duration
        );

        if ($result->isSuccessful()) {
            $this->environment->addBuiltArtifact($outputPath);

            $this->logger->info("Build completed successfully: {$outputPath}");
        }

        return $result;
    }

    private function buildCommand(string $outputPath): array
    {
        $command = ['go', 'build'];

        foreach ($this->buildFlags as $flag) {
            $command[] = $flag;
        }

        if (!empty($this->ldFlags)) {
            $ldflags = [];

            foreach ($this->ldFlags as $key => $value) {
                $ldflags[] = "-X {$key}={$value}";
            }

            $command[] = '-ldflags';
            $command[] = implode(' ', $ldflags);
        }

        if (!empty($this->tags)) {
            $command[] = '-tags';
            $command[] = implode(',', $this->tags);
        }

        $command[] = '-o';
        $command[] = $outputPath;

        $command[] = './...';

        return $command;
    }

    public function runTests(): TestResult
    {
        $this->validateEnvironment();

        $startTime = microtime(true);

        $command = ['go', 'test', '-v', '-race', './...'];

        foreach ($this->tags as $tag) {
            $command[] = '-tags';
            $command[] = $tag;
        }

        $process = new Process($command);
        $process->setTimeout(600);
        $process->setWorkingDirectory(getcwd());

        $process->run();

        $duration = round(microtime(true) - $startTime, 2);

        $result = new TestResult(
            success: $process->isSuccessful(),
            exitCode: $process->getExitCode(),
            output: $process->getOutput(),
            error: $process->getErrorOutput(),
            duration: $duration,
            passed: $this->countPassedTests($process->getOutput()),
            failed: $this->countFailedTests($process->getOutput())
        );

        return $result;
    }

    private function countPassedTests(string $output): int
    {
        preg_match_all('/--- PASS: /', $output, $matches);

        return count($matches[0]);
    }

    private function countFailedTests(string $output): int
    {
        preg_match_all('/--- FAIL: /', $output, $matches);

        return count($matches[0]);
    }
}

class GoBuildEnvironment
{
    private string $goVersion = '';
    private string $goPath = '';
    private string $moduleName = '';
    private string $moduleGoVersion = '';
    private string $moduleCachePath = '';
    private bool $cgoEnabled = true;
    private bool $hasMainPackage = false;
    private bool $hasSharedPackage = false;
    private array $builtArtifacts = [];

    public function getGoVersion(): string
    {
        return $this->goVersion;
    }

    public function setGoVersion(string $version): void
    {
        $this->goVersion = $version;
    }

    public function getGoPath(): string
    {
        return $this->goPath;
    }

    public function setGoPath(string $path): void
    {
        $this->goPath = $path;
    }

    public function getModuleName(): string
    {
        return $this->moduleName;
    }

    public function setModuleName(string $name): void
    {
        $this->moduleName = $name;
    }

    public function getModuleGoVersion(): string
    {
        return $this->moduleGoVersion;
    }

    public function setModuleGoVersion(string $version): void
    {
        $this->moduleGoVersion = $version;
    }

    public function getModuleCachePath(): string
    {
        return $this->moduleCachePath;
    }

    public function setModuleCachePath(string $path): void
    {
        $this->moduleCachePath = $path;
    }

    public function isCgoEnabled(): bool
    {
        return $this->cgoEnabled;
    }

    public function setCgoEnabled(bool $enabled): void
    {
        $this->cgoEnabled = $enabled;
    }

    public function hasMainPackage(): bool
    {
        return $this->hasMainPackage;
    }

    public function setHasMainPackage(bool $has): void
    {
        $this->hasMainPackage = $has;
    }

    public function hasSharedPackage(): bool
    {
        return $this->hasSharedPackage;
    }

    public function setHasSharedPackage(bool $has): void
    {
        $this->hasSharedPackage = $has;
    }

    public function addBuiltArtifact(string $path): void
    {
        $this->builtArtifacts[] = $path;
    }

    public function getBuiltArtifacts(): array
    {
        return $this->builtArtifacts;
    }
}
