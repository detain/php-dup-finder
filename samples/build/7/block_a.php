<?php

declare(strict_types=1);

namespace App\BuildSystem;

class GradleBuildWrapper
{
    private const GRADLE_WRAPPER_VERSION = '8.5';
    private const BUILD_CACHE_DIR = '.gradle/caches';

    private BuildEnvironment $environment;
    private array $tasks = [];
    private array $properties = [];

    public function __construct(BuildEnvironment $environment)
    {
        $this->environment = $environment;
    }

    public function validateBuildEnvironment(): void
    {
        $this->validateJavaInstallation();
        $this->validateGradleWrapper();
        $this->validateProjectStructure();
        $this->validateBuildFiles();
        $this->checkDiskSpace();
        $this->validateNetworkConnectivity();
    }

    private function validateJavaInstallation(): void
    {
        $javaHome = getenv('JAVA_HOME');

        if (empty($javaHome)) {
            $javaHome = $this->findJavaHome();
        }

        if ($javaHome === null || !is_dir($javaHome)) {
            throw new BuildException(
                "JAVA_HOME is not set or directory does not exist: " . ($javaHome ?? 'null')
            );
        }

        $javaBinary = "{$javaHome}/bin/java";

        if (!file_exists($javaBinary)) {
            throw new BuildException("Java binary not found at: {$javaBinary}");
        }

        $version = $this->runCommand([$javaBinary, '-version', '2>&1']);

        if (!preg_match('/version\s+"([^"]+)"/', $version, $matches)) {
            throw new BuildException("Failed to parse Java version");
        }

        $javaVersion = $matches[1];

        if (!preg_match('/^17\.|^21\./', $javaVersion)) {
            throw new BuildException(
                "Java 17 or 21 required, found: {$javaVersion}"
            );
        }

        $this->environment->setJavaVersion($javaVersion);
        $this->environment->setJavaHome($javaHome);
    }

    private function findJavaHome(): ?string
    {
        $possiblePaths = [
            '/usr/lib/jvm/java-17-openjdk-amd64',
            '/usr/lib/jvm/java-21-openjdk-amd64',
            '/opt/java/openjdk-17',
            '/opt/java/openjdk-21',
            '/usr/local/java/jdk-17',
            '/usr/local/java/jdk-21'
        ];

        foreach ($possiblePaths as $path) {
            if (is_dir($path)) {
                return $path;
            }
        }

        $result = trim(shell_exec('dirname $(dirname $(readlink -f $(which java) 2>/dev/null)) 2>/dev/null') ?: '');

        if (!empty($result) && is_dir($result)) {
            return $result;
        }

        return null;
    }

    private function validateGradleWrapper(): void
    {
        $gradleWrapper = getcwd() . '/gradlew';
        $gradleWrapperPom = getcwd() . '/gradle/wrapper/gradle-wrapper.properties';

        if (!file_exists($gradleWrapper)) {
            throw new BuildException(
                "Gradle wrapper (gradlew) not found. Run: gradle wrapper"
            );
        }

        if (!is_executable($gradleWrapper)) {
            chmod($gradleWrapper, 0755);
        }

        if (file_exists($gradleWrapperPom)) {
            $content = file_get_contents($gradleWrapperPom);

            preg_match('/distributionUrl=(.+)/', $content, $matches);

            if (isset($matches[1])) {
                $url = trim($matches[1]);

                if (str_contains($url, 'gradle-')) {
                    preg_match('/gradle-(\d+\.\d+)/', $url, $versionMatch);
                    $version = $versionMatch[1] ?? 'unknown';

                    $this->environment->setGradleVersion($version);
                }
            }
        }
    }

    private function validateProjectStructure(): void
    {
        $requiredDirs = ['src/main/java', 'src/main/resources', 'src/test/java'];

        foreach ($requiredDirs as $dir) {
            $path = getcwd() . '/' . $dir;

            if (!is_dir($path)) {
                $this->logger->warning("Optional directory not found: {$dir}");
            }
        }

        $buildFile = getcwd() . '/build.gradle';

        if (!file_exists($buildFile)) {
            $buildFile = getcwd() . '/build.gradle.kts';

            if (!file_exists($buildFile)) {
                throw new BuildException(
                    "No build.gradle or build.gradle.kts found in: " . getcwd()
                );
            }
        }

        $this->environment->setBuildFile(basename($buildFile));
    }

    private function validateBuildFiles(): void
    {
        $buildFile = getcwd() . '/build.gradle';

        if (file_exists($buildFile)) {
            $this->validateGradleSyntax($buildFile);
        }

        $settingsFile = getcwd() . '/settings.gradle';

        if (file_exists($settingsFile)) {
            $this->validateSettingsSyntax($settingsFile);
        }
    }

    private function validateGradleSyntax(string $buildFile): void
    {
        $command = [
            'gradle',
            '-p', dirname($buildFile),
            'help',
            '--no-daemon'
        ];

        $process = new Process($command);
        $process->setTimeout(60);
        $process->run();

        if ($process->getExitCode() !== 0) {
            $error = $process->getErrorOutput();

            if (str_contains($error, 'Could not compile')) {
                throw new BuildException(
                    "build.gradle has syntax errors: " . substr($error, 0, 500)
                );
            }
        }
    }

    private function validateSettingsSyntax(string $settingsFile): void
    {
        $content = file_get_contents($settingsFile);

        if (str_contains($content, 'include ') && !preg_match('/include\s+["\']/', $content)) {
            throw new BuildException(
                "settings.gradle has invalid include statements"
            );
        }
    }

    private function checkDiskSpace(): void
    {
        $path = getcwd();
        $df = disk_free_space($path);

        if ($df === false) {
            $this->logger->warning("Could not determine disk space for: {$path}");
            return;
        }

        $requiredSpace = 500 * 1024 * 1024;

        if ($df < $requiredSpace) {
            throw new BuildException(
                "Insufficient disk space. Required: 500MB, Available: " . $this->formatBytes($df)
            );
        }
    }

    private function validateNetworkConnectivity(): void
    {
        $testHosts = [
            'services.gradle.org',
            'plugins.gradle.org',
            'repo.maven.apache.org'
        ];

        foreach ($testHosts as $host) {
            $connected = $this->testHostConnection($host, 443);

            if (!$connected) {
                $this->logger->warning("Cannot reach {$host} - network issues may affect build");
            }
        }
    }

    private function testHostConnection(string $host, int $port): bool
    {
        $connection = @fsockopen($host, $port, $errno, $errstr, 5);

        if ($connection) {
            fclose($connection);
            return true;
        }

        return false;
    }

    public function addTask(string $task): self
    {
        $this->tasks[] = $task;

        return $this;
    }

    public function setProperty(string $key, string $value): self
    {
        $this->properties[$key] = $value;

        return $this;
    }

    public function build(string $task = 'build'): BuildResult
    {
        $this->validateBuildEnvironment();

        $startTime = microtime(true);

        $command = $this->buildCommand($task);

        $process = new Process($command);
        $process->setTimeout(600);
        $process->setWorkingDirectory(getcwd());

        $process->run();

        $duration = round(microtime(true) - $startTime, 2);

        $result = new BuildResult(
            success: $process->isSuccessful(),
            command: implode(' ', $command),
            exitCode: $process->getExitCode(),
            output: $process->getOutput(),
            error: $process->getErrorOutput(),
            duration: $duration
        );

        if (!$result->isSuccessful()) {
            $this->logger->error("Build failed with exit code: {$result->getExitCode()}");
        }

        return $result;
    }

    private function buildCommand(string $task): array
    {
        $command = [getcwd() . '/gradlew', '--no-daemon'];

        foreach ($this->properties as $key => $value) {
            $command[] = "-P{$key}={$value}";
        }

        if ($this->environment->isBuildCacheEnabled()) {
            $command[] = '--build-cache';
        }

        if ($this->environment->isParallelEnabled()) {
            $command[] = '--parallel';
        }

        $command[] = $task;

        return $command;
    }

    private function runCommand(array $command): string
    {
        $process = new Process($command);
        $process->setTimeout(30);
        $process->run();

        return $process->getOutput() . $process->getErrorOutput();
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;

        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }
}

class BuildEnvironment
{
    private string $javaHome = '';
    private string $javaVersion = '';
    private string $gradleVersion = '';
    private string $buildFile = '';
    private bool $buildCacheEnabled = true;
    private bool $parallelEnabled = false;

    public function getJavaHome(): string
    {
        return $this->javaHome;
    }

    public function setJavaHome(string $home): void
    {
        $this->javaHome = $home;
    }

    public function getJavaVersion(): string
    {
        return $this->javaVersion;
    }

    public function setJavaVersion(string $version): void
    {
        $this->javaVersion = $version;
    }

    public function getGradleVersion(): string
    {
        return $this->gradleVersion;
    }

    public function setGradleVersion(string $version): void
    {
        $this->gradleVersion = $version;
    }

    public function getBuildFile(): string
    {
        return $this->buildFile;
    }

    public function setBuildFile(string $file): void
    {
        $this->buildFile = $file;
    }

    public function isBuildCacheEnabled(): bool
    {
        return $this->buildCacheEnabled;
    }

    public function isParallelEnabled(): bool
    {
        return $this->parallelEnabled;
    }

    public function enableBuildCache(): self
    {
        $this->buildCacheEnabled = true;
        return $this;
    }

    public function enableParallel(): self
    {
        $this->parallelEnabled = true;
        return $this;
    }
}
