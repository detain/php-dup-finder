<?php

declare(strict_types=1);

namespace App\Build\Core;

interface PackageBuilderInterface
{
    public function build(string $projectPath, BuildOptionsInterface $options): BuildResultInterface;
    public function validate(ProjectValidatorInterface $validator): void;
    public function clean(string $projectPath): void;
    public function getMetadata(string $projectPath): PackageMetadata;
}

abstract class AbstractPackageBuilder implements PackageBuilderInterface
{
    protected LoggerInterface $logger;
    protected HttpClient $httpClient;
    protected Config $config;

    public function build(string $projectPath, BuildOptionsInterface $options): BuildResultInterface
    {
        $this->validate($this->createValidator($projectPath));
        $this->clean($projectPath);
        $this->installDependencies($projectPath, $options);
        $this->compile($projectPath, $options);
        $this->runTests($projectPath, $options);

        $artifactPath = $this->findArtifact($projectPath, $options);
        $metadata = $this->getMetadata($projectPath);

        return $this->createBuildResult($artifactPath, $metadata, $options);
    }

    abstract protected function installDependencies(string $path, BuildOptionsInterface $options): void;
    abstract protected function compile(string $path, BuildOptionsInterface $options): void;
    abstract protected function findArtifact(string $path, BuildOptionsInterface $options): string;

    protected function createValidator(string $projectPath): ProjectValidatorInterface
    {
        return new ProjectValidator($projectPath);
    }

    protected function createBuildResult(
        string $artifactPath,
        PackageMetadata $metadata,
        BuildOptionsInterface $options
    ): BuildResultInterface {
        return new BuildResult(
            success: true,
            artifactPath: $artifactPath,
            metadata: $metadata,
            duration: 0.0
        );
    }
}

class NuGetBuilder extends AbstractPackageBuilder
{
    protected function installDependencies(string $path, BuildOptionsInterface $options): void
    {
        $command = ['dotnet', 'restore', $path, '--verbosity', 'minimal'];

        $this->runProcess($command, 300);
    }

    protected function compile(string $path, BuildOptionsInterface $options): void
    {
        $command = [
            'dotnet', 'build', $path,
            '--configuration', $options->get('configuration', 'Release')
        ];

        $this->runProcess($command, 600);
    }

    protected function findArtifact(string $path, BuildOptionsInterface $options): string
    {
        return glob($path . '/bin/Release/**/*.nupkg')[0] ?? '';
    }
}

class PythonBuilder extends AbstractPackageBuilder
{
    protected function installDependencies(string $path, BuildOptionsInterface $options): void
    {
        $command = ['pip', 'install', '-e', '.'];

        $this->runProcess($command, 300);
    }

    protected function compile(string $path, BuildOptionsInterface $options): void
    {
        $command = ['python', '-m', 'build', '--outdir', 'dist', '.'];

        $this->runProcess($command, 300);
    }

    protected function findArtifact(string $path, BuildOptionsInterface $options): string
    {
        return glob($path . '/dist/*.whl')[0] ?? glob($path . '/dist/*.tar.gz')[0] ?? '';
    }
}

class RustBuilder extends AbstractPackageBuilder
{
    protected function installDependencies(string $path, BuildOptionsInterface $options): void
    {
        $command = ['cargo', 'update'];

        $this->runProcess($command, 300);
    }

    protected function compile(string $path, BuildOptionsInterface $options): void
    {
        $command = ['cargo', 'build', '--release'];

        $this->runProcess($command, 1200);
    }

    protected function findArtifact(string $path, BuildOptionsInterface $options): string
    {
        return $path . '/target/release/' . ($options->get('bin_name') ?? 'main');
    }
}

class MultiLanguageBuildOrchestrator
{
    private array $builders = [];
    private LoggerInterface $logger;

    public function registerBuilder(string $language, PackageBuilderInterface $builder): void
    {
        $this->builders[$language] = $builder;
    }

    public function build(string $projectPath, BuildOptionsInterface $options): BuildResultInterface
    {
        $language = $this->detectLanguage($projectPath);

        if (!isset($this->builders[$language])) {
            throw new \RuntimeException("No builder registered for language: {$language}");
        }

        $this->logger->info("Building {$language} project: {$projectPath}");

        return $this->builders[$language]->build($projectPath, $options);
    }

    private function detectLanguage(string $path): string
    {
        if (file_exists($path . '/*.csproj')) {
            return 'dotnet';
        }

        if (file_exists($path . '/Cargo.toml')) {
            return 'rust';
        }

        if (file_exists($path . '/setup.py') || file_exists($path . '/pyproject.toml')) {
            return 'python';
        }

        return 'unknown';
    }
}
