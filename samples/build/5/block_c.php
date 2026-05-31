<?php

declare(strict_types=1);

namespace App\Build\Rust;

class CargoPackageBuilder
{
    private const CRATES_IO = 'https://crates.io/api/v1';
    private const CARGO_REGISTRY = 'https://github.com/rust-lang/crates.io-index';

    private Config $config;
    private HttpClient $httpClient;
    private array $builtArtifacts = [];

    public function __construct(Config $config, HttpClient $httpClient)
    {
        $this->config = $config;
        $this->httpClient = $httpClient;
    }

    public function buildCrate(string $cratePath, CargoBuildOptions $options): CargoBuildResult
    {
        $this->validateCratePath($cratePath);
        $this->validateCargoToml($cratePath);

        $startTime = microtime(true);

        $this->updateDependencies($cratePath, $options);
        $this->cleanBuildArtifacts($cratePath);
        $this->checkCode($cratePath, $options);
        $this->buildRelease($cratePath, $options);
        $this->runUnitTests($cratePath, $options);
        $this->buildDocumentation($cratePath, $options);

        $artifactPath = $this->findArtifact($cratePath, $options);

        $metadata = $this->extractCrateMetadata($cratePath);

        $this->builtArtifacts[] = $artifactPath;

        $duration = round(microtime(true) - $startTime, 2);

        $this->logger->info(
            "Crate built successfully: {} v{}",
            $metadata['name'],
            $metadata['version']
        );

        return new CargoBuildResult(
            success: true,
            artifactPath: $artifactPath,
            duration: $duration,
            metadata: $metadata
        );
    }

    private function validateCratePath(string $path): void
    {
        if (!is_dir($path)) {
            throw new \InvalidArgumentException("Crate path does not exist: {$path}");
        }

        if (!file_exists($path . '/Cargo.toml')) {
            throw new \InvalidArgumentException(
                "No Cargo.toml found in: {$path}"
            );
        }
    }

    private function validateCargoToml(string $cratePath): void
    {
        $cargoToml = $cratePath . '/Cargo.toml';
        $content = file_get_contents($cargoToml);

        if (!preg_match('/^\[package\]$/m', $content)) {
            throw new \InvalidArgumentException("Cargo.toml missing [package] section");
        }

        if (!preg_match('/^name\s*=\s*"([^"]+)"$/m', $content)) {
            throw new \InvalidArgumentException("Cargo.toml missing package name");
        }

        if (!preg_match('/^version\s*=\s*"([^"]+)"$/m', $content)) {
            throw new \InvalidArgumentException("Cargo.toml missing package version");
        }
    }

    private function updateDependencies(string $cratePath, CargoBuildOptions $options): void
    {
        $this->logger->info("Updating dependencies...");

        $command = ['cargo', 'update'];

        if ($options->getTarget() !== null) {
            $command[] = '--target';
            $command[] = $options->getTarget();
        }

        $process = new Process($command);
        $process->setTimeout(300);
        $process->setWorkingDirectory($cratePath);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(
                "Cargo update failed: " . $process->getErrorOutput()
            );
        }

        $this->logger->info("Dependencies updated successfully");
    }

    private function cleanBuildArtifacts(string $cratePath): void
    {
        $this->logger->info("Cleaning build artifacts...");

        $command = ['cargo', 'clean'];

        $process = new Process($command);
        $process->setTimeout(60);
        $process->setWorkingDirectory($cratePath);
        $process->run();

        $targetDir = $cratePath . '/target';
        $depsDir = $targetDir . '/deps';

        if (is_dir($depsDir)) {
            $this->removeDirectoryContents($depsDir);
        }

        $releaseDir = $targetDir . '/release';

        if (is_dir($releaseDir)) {
            $this->removeDirectoryContents($releaseDir);
        }

        $this->logger->info("Build artifacts cleaned");
    }

    private function removeDirectoryContents(string $dir): void
    {
        $files = scandir($dir);

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $path = $dir . '/' . $file;

            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $this->removeDirectoryContents($path);
        rmdir($path);
    }

    private function checkCode(string $cratePath, CargoBuildOptions $options): void
    {
        $this->logger->info("Running cargo check...");

        $command = ['cargo', 'check'];

        if ($options->getTarget() !== null) {
            $command[] = '--target';
            $command[] = $options->getTarget();
        }

        if ($options->getFeatures() !== []) {
            $command[] = '--features';
            $command[] = implode(',', $options->getFeatures());
        }

        $process = new Process($command);
        $process->setTimeout(600);
        $process->setWorkingDirectory($cratePath);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(
                "Cargo check failed: " . $process->getErrorOutput()
            );
        }

        $this->logger->info("Code check passed");
    }

    private function buildRelease(string $cratePath, CargoBuildOptions $options): void
    {
        $this->logger->info("Building release...");

        $command = [
            'cargo', 'build',
            '--release',
            '--lib',
            '--bin', $options->getBinName() ?? 'main'
        ];

        if ($options->getTarget() !== null) {
            $command[] = '--target';
            $command[] = $options->getTarget();
        }

        if ($options->getFeatures() !== []) {
            $command[] = '--features';
            $command[] = implode(',', $options->getFeatures());
        }

        if ($options->isLto()) {
            $command[] = '-C';
            $command[] = 'lto=on';
        }

        if ($options->getCodegenUnits() !== null) {
            $command[] = '-C';
            $command[] = 'codegen-units=' . $options->getCodegenUnits();
        }

        $process = new Process($command);
        $process->setTimeout(1200);
        $process->setWorkingDirectory($cratePath);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(
                "Release build failed: " . $process->getErrorOutput()
            );
        }

        $this->logger->info("Release build completed");
    }

    private function runUnitTests(string $cratePath, CargoBuildOptions $options): void
    {
        if (!$options->shouldRunTests()) {
            return;
        }

        $this->logger->info("Running unit tests...");

        $command = ['cargo', 'test', '--lib', '--bins', '--', '--test-threads=4'];

        if ($options->getTarget() !== null) {
            $command[] = '--target';
            $command[] = $options->getTarget();
        }

        $process = new Process($command);
        $process->setTimeout(600);
        $process->setWorkingDirectory($cratePath);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(
                "Tests failed: " . $process->getErrorOutput()
            );
        }

        $this->logger->info("All tests passed");
    }

    private function buildDocumentation(string $cratePath, CargoBuildOptions $options): void
    {
        if (!$options->shouldBuildDocs()) {
            return;
        }

        $this->logger->info("Building documentation...");

        $command = [
            'cargo', 'doc',
            '--no-deps',
            '--open'
        ];

        if ($options->getTarget() !== null) {
            $command[] = '--target';
            $command[] = $options->getTarget();
        }

        $process = new Process($command);
        $process->setTimeout(300);
        $process->setWorkingDirectory($cratePath);
        $process->run();

        if (!$process->isSuccessful()) {
            $this->logger->warning("Documentation build had warnings");
        } else {
            $this->logger->info("Documentation built successfully");
        }
    }

    private function findArtifact(string $cratePath, CargoBuildOptions $options): string
    {
        $targetDir = $cratePath . '/target';

        if ($options->getTarget() !== null) {
            $targetDir .= '/' . $options->getTarget();
        }

        $releaseDir = $targetDir . '/release';

        $binName = $options->getBinName() ?? 'main';
        $artifactPath = $releaseDir . '/' . $binName;

        if (!file_exists($artifactPath)) {
            throw new \RuntimeException("Build artifact not found: {$artifactPath}");
        }

        return realpath($artifactPath);
    }

    private function extractCrateMetadata(string $cratePath): array
    {
        $cargoToml = $cratePath . '/Cargo.toml';
        $content = file_get_contents($cargoToml);

        preg_match('/^name\s*=\s*"([^"]+)"$/m', $content, $nameMatch);
        preg_match('/^version\s*=\s*"([^"]+)"$/m', $content, $versionMatch);

        return [
            'name' => $nameMatch[1] ?? basename($cratePath),
            'version' => $versionMatch[1] ?? 'unknown'
        ];
    }

    public function publishCrate(string $cratePath, string $apiToken): void
    {
        $this->logger->info("Publishing crate to crates.io...");

        $command = [
            'cargo', 'publish',
            '--token', $apiToken
        ];

        $process = new Process($command);
        $process->setTimeout(120);
        $process->setWorkingDirectory($cratePath);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(
                "Publish failed: " . $process->getErrorOutput()
            );
        }

        $this->logger->info("Crate published successfully");
    }
}
