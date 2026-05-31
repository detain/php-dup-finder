<?php

declare(strict_types=1);

namespace App\Build;

class NuGetPackageBuilder
{
    private const NUGET_API_URL = 'https://api.nuget.org/v3';
    private const PACKAGE_SOURCE = 'https://pkgs.dev.azure.com/_packaging/MyFeed/nuget/v3';

    private Config $config;
    private HttpClient $httpClient;
    private array $builtPackages = [];

    public function __construct(Config $config, HttpClient $httpClient)
    {
        $this->config = $config;
        $this->httpClient = $httpClient;
    }

    public function buildPackage(string $projectPath, BuildOptions $options): BuildResult
    {
        $this->validateProjectPath($projectPath);
        $this->validateOptions($options);

        $startTime = microtime(true);

        $this->restoreDependencies($projectPath, $options);
        $this->cleanPreviousBuild($projectPath);
        $this->compileProject($projectPath, $options);
        $this->runTests($projectPath, $options);
        $packagePath = $this->createNuGetPackage($projectPath, $options);

        $metadata = $this->extractPackageMetadata($packagePath);

        $this->builtPackages[] = $packagePath;

        $duration = round(microtime(true) - $startTime, 2);

        $this->logger->info(
            "Package built successfully: {$metadata['id']} v{$metadata['version']}"
        );

        return new BuildResult(
            success: true,
            packagePath: $packagePath,
            duration: $duration,
            metadata: $metadata
        );
    }

    private function validateProjectPath(string $path): void
    {
        if (!file_exists($path)) {
            throw new \InvalidArgumentException("Project path does not exist: {$path}");
        }

        $csprojFiles = glob($path . '/*.csproj');

        if (empty($csprojFiles)) {
            throw new \InvalidArgumentException("No .csproj files found in: {$path}");
        }
    }

    private function validateOptions(BuildOptions $options): void
    {
        if ($options->getConfiguration() !== 'Debug' && $options->getConfiguration() !== 'Release') {
            throw new \InvalidArgumentException(
                "Invalid configuration: {$options->getConfiguration()}. Must be Debug or Release."
            );
        }

        if ($options->getTargetFramework() !== 'net8.0' && $options->getTargetFramework() !== 'net7.0') {
            $this->logger->warning("Non-standard target framework: " . $options->getTargetFramework());
        }
    }

    private function restoreDependencies(string $projectPath, BuildOptions $options): void
    {
        $this->logger->info("Restoring NuGet packages...");

        $command = [
            'dotnet', 'restore',
            $projectPath,
            '--configfile', $this->config->getNuGetConfig(),
            '--source', self::NUGET_API_URL,
            '--source', self::PACKAGE_SOURCE,
            '--verbosity', 'minimal'
        ];

        $process = new Process($command);
        $process->setTimeout(300);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(
                "NuGet restore failed: " . $process->getErrorOutput()
            );
        }

        $this->logger->info("Dependencies restored successfully");
    }

    private function cleanPreviousBuild(string $projectPath): void
    {
        $this->logger->info("Cleaning previous build artifacts...");

        $command = [
            'dotnet', 'clean',
            $projectPath,
            '--configuration', 'Release'
        ];

        $process = new Process($command);
        $process->setTimeout(120);
        $process->run();

        $binPath = $projectPath . '/bin';
        $objPath = $projectPath . '/obj';

        if (is_dir($binPath)) {
            $this->removeDirectory($binPath);
        }

        if (is_dir($objPath)) {
            $this->removeDirectory($objPath);
        }

        $this->logger->info("Previous build artifacts removed");
    }

    private function removeDirectory(string $path): void
    {
        $files = array_diff(scandir($path), ['.', '..']);

        foreach ($files as $file) {
            $fullPath = $path . '/' . $file;

            if (is_dir($fullPath)) {
                $this->removeDirectory($fullPath);
            } else {
                unlink($fullPath);
            }
        }

        rmdir($path);
    }

    private function compileProject(string $projectPath, BuildOptions $options): void
    {
        $this->logger->info("Compiling project...");

        $command = [
            'dotnet', 'build',
            $projectPath,
            '--configuration', $options->getConfiguration(),
            '--target', $options->getTargetFramework(),
            '--no-restore',
            '--verbosity', 'minimal'
        ];

        if ($options->isRelease()) {
            $command[] = '--configuration';
            $command[] = 'Release';
        }

        $process = new Process($command);
        $process->setTimeout(600);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(
                "Build failed: " . $process->getErrorOutput()
            );
        }

        $this->logger->info("Project compiled successfully");
    }

    private function runTests(string $projectPath, BuildOptions $options): void
    {
        if (!$options->shouldRunTests()) {
            return;
        }

        $this->logger->info("Running unit tests...");

        $testProjects = $this->findTestProjects(dirname($projectPath));

        foreach ($testProjects as $testProject) {
            $this->logger->info("Running tests in: {$testProject}");

            $command = [
                'dotnet', 'test',
                $testProject,
                '--configuration', $options->getConfiguration(),
                '--no-build',
                '--verbosity', 'minimal',
                '--logger', 'trx;LogFileName=results.trx'
            ];

            $process = new Process($command);
            $process->setTimeout(300);
            $process->run();

            if (!$process->isSuccessful()) {
                throw new \RuntimeException(
                    "Tests failed in {$testProject}: " . $process->getErrorOutput()
                );
            }
        }

        $this->logger->info("All tests passed");
    }

    private function findTestProjects(string $solutionPath): array
    {
        $projects = glob($solutionPath . '/**/*Tests.csproj', GLOB_BRACE);

        return $projects ?: [];
    }

    private function createNuGetPackage(string $projectPath, BuildOptions $options): string
    {
        $this->logger->info("Creating NuGet package...");

        $csprojFile = glob($projectPath . '/*.csproj')[0];
        $outputDir = getcwd() . '/artifacts';

        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $command = [
            'dotnet', 'pack',
            $csprojFile,
            '--configuration', $options->getConfiguration(),
            '--no-build',
            '--output', $outputDir,
            '--include-symbols',
            '--include-source'
        ];

        $process = new Process($command);
        $process->setTimeout(180);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(
                "Pack failed: " . $process->getErrorOutput()
            );
        }

        $nupkgFiles = glob($outputDir . '/*.nupkg');

        if (empty($nupkgFiles)) {
            throw new \RuntimeException("No .nupkg file was created");
        }

        return $nupkgFiles[0];
    }

    private function extractPackageMetadata(string $packagePath): array
    {
        $archive = new ZipArchive();
        $archive->open($packagePath);

        $nuspecContent = $archive->getFromName('*.nuspec');
        $archive->close();

        preg_match('/<id>(.*?)<\/id>/', $nuspecContent, $idMatch);
        preg_match('/<version>(.*?)<\/version>/', $nuspecContent, $versionMatch);

        return [
            'id' => $idMatch[1] ?? basename($packagePath),
            'version' => $versionMatch[1] ?? 'unknown'
        ];
    }

    public function pushPackage(string $packagePath, string $apiKey): void
    {
        $this->logger->info("Pushing package to NuGet.org...");

        $command = [
            'dotnet', 'nuget', 'push',
            $packagePath,
            '--source', self::NUGET_API_URL,
            '--api-key', $apiKey
        ];

        $process = new Process($command);
        $process->setTimeout(120);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(
                "Package push failed: " . $process->getErrorOutput()
            );
        }

        $this->logger->info("Package pushed successfully");
    }
}
