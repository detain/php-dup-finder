<?php

declare(strict_types=1);

namespace App\BuildSystem\Maven;

class MavenBuildExecutor
{
    private const MVN_WRAPPER = './mvnw';
    private const MVN_SETTINGS = '.mvn/maven.config';

    private MavenEnvironment $env;
    private array $goals = [];
    private array $profiles = [];
    private array $systemProperties = [];

    public function __construct(MavenEnvironment $env)
    {
        $this->env = $env;
    }

    public function validateEnvironment(): void
    {
        $this->checkMavenInstallation();
        $this->checkMavenWrapper();
        $this->checkJavaHome();
        $this->verifyProjectPom();
        $this->checkLocalRepository();
        $this->validateNetworkSettings();
        $this->checkDiskSpace();
    }

    private function checkMavenInstallation(): void
    {
        $mvnCommand = getenv('MAVEN_HOME')
            ? getenv('MAVEN_HOME') . '/bin/mvn'
            : 'mvn';

        $command = [$mvnCommand, '-version'];

        $process = new Process($command);
        $process->setTimeout(30);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new MavenBuildException(
                "Maven is not installed or not in PATH"
            );
        }

        $output = $process->getOutput();

        if (preg_match('/Apache Maven (\d+\.\d+\.\d+)/', $output, $matches)) {
            $version = $matches[1];

            if (version_compare($version, '3.8.0', '<')) {
                throw new MavenBuildException(
                    "Maven 3.8.0+ required, found: {$version}"
                );
            }

            $this->env->setMavenVersion($version);
        }
    }

    private function checkMavenWrapper(): void
    {
        $wrapperScript = getcwd() . '/mvnw';

        if (!file_exists($wrapperScript)) {
            $this->logger->info("Maven wrapper not found, using system Maven");
            return;
        }

        if (!is_executable($wrapperScript)) {
            chmod($wrapperScript, 0755);
        }

        $process = new Process([$wrapperScript, '-version']);
        $process->setTimeout(30);
        $process->run();

        if ($process->isSuccessful()) {
            $this->env->setUsesWrapper(true);
        }
    }

    private function checkJavaHome(): void
    {
        $javaHome = getenv('JAVA_HOME');

        if (empty($javaHome)) {
            $javaHome = $this->detectJavaHome();
        }

        if (empty($javaHome) || !is_dir($javaHome)) {
            throw new MavenBuildException(
                "JAVA_HOME is not set or not a valid directory: " . ($javaHome ?? 'null')
            );
        }

        $javaExecutable = "{$javaHome}/bin/java";

        if (!file_exists($javaExecutable)) {
            throw new MavenBuildException(
                "Java executable not found at: {$javaExecutable}"
            );
        }

        $version = $this->getJavaVersion($javaExecutable);

        if (!preg_match('/^(\d+)\./', $version, $matches)) {
            throw new MavenBuildException("Failed to detect Java version");
        }

        $majorVersion = (int)$matches[1];

        if ($majorVersion < 11 || $majorVersion > 21) {
            throw new MavenBuildException(
                "Java 11-21 required, found: {$version}"
            );
        }

        $this->env->setJavaHome($javaHome);
        $this->env->setJavaVersion($version);
    }

    private function detectJavaHome(): ?string
    {
        $searchPaths = [
            '/usr/lib/jvm',
            '/opt/java',
            '/usr/local/java'
        ];

        foreach ($searchPaths as $basePath) {
            if (!is_dir($basePath)) {
                continue;
            }

            $entries = scandir($basePath);

            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $javaPath = "{$basePath}/{$entry}/bin/java";

                if (file_exists($javaPath)) {
                    return "{$basePath}/{$entry}";
                }
            }
        }

        $javaHomeFromJenv = trim(shell_exec('jenv prefix 2>/dev/null') ?: '');

        if (!empty($javaHomeFromJenv) && is_dir($javaHomeFromJenv)) {
            return $javaHomeFromJenv;
        }

        return null;
    }

    private function getJavaVersion(string $javaExecutable): string
    {
        $process = new Process([$javaExecutable, '-version', '2>&1']);
        $process->setTimeout(10);
        $process->run();

        $output = $process->getOutput();

        if (preg_match('/version\s+"([^"]+)"/', $output, $matches)) {
            return $matches[1];
        }

        if (preg_match('/version\s+"(\d+\.\d+\.\d+[^\"]*)"/', $output, $matches)) {
            return $matches[1];
        }

        return 'unknown';
    }

    private function verifyProjectPom(): void
    {
        $pomFiles = ['pom.xml', 'pom.xml.dev', 'pom.xml.staging'];

        $foundPom = null;

        foreach ($pomFiles as $pomFile) {
            $path = getcwd() . '/' . $pomFile;

            if (file_exists($path)) {
                $foundPom = $pomFile;
                break;
            }
        }

        if ($foundPom === null) {
            throw new MavenBuildException(
                "No pom.xml found in: " . getcwd()
            );
        }

        $this->validatePomStructure(getcwd() . '/' . $foundPom);

        $this->env->setPomFile($foundPom);
    }

    private function validatePomStructure(string $pomPath): void
    {
        $content = file_get_contents($pomPath);

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($content);

        if ($xml === false) {
            $errors = libxml_get_errors();
            libxml_clear_errors();

            throw new MavenBuildException(
                "pom.xml is not valid XML: " . ($errors[0]->message ?? 'Unknown error')
            );
        }

        if (!$xml->getNamespaces()) {
            $this->logger->warning("pom.xml does not declare XML namespace");
        }

        $requiredElements = ['groupId', 'artifactId', 'version'];

        foreach ($requiredElements as $element) {
            if (!$xml->{$element}) {
                throw new MavenBuildException(
                    "pom.xml missing required element: <{$element}>"
                );
            }
        }
    }

    private function checkLocalRepository(): void
    {
        $localRepo = $this->env->getLocalRepositoryPath();

        if (!is_dir($localRepo)) {
            if (!mkdir($localRepo, 0755, true)) {
                throw new MavenBuildException(
                    "Failed to create local repository: {$localRepo}"
                );
            }
        }

        if (!is_writable($localRepo)) {
            throw new MavenBuildException(
                "Local repository is not writable: {$localRepo}"
            );
        }
    }

    private function validateNetworkSettings(): void
    {
        $proxyHost = getenv('MAVEN_PROXY_HOST');
        $proxyPort = getenv('MAVEN_PROXY_PORT');

        if (!empty($proxyHost)) {
            $this->env->setProxy($proxyHost, (int)$proxyPort);
        }

        $mirrors = $this->env->getMirrors();

        if (empty($mirrors)) {
            $this->logger->info("No Maven mirrors configured");
        }
    }

    private function checkDiskSpace(): void
    {
        $path = getcwd();
        $freeSpace = disk_free_space($path);

        if ($freeSpace === false) {
            $this->logger->warning("Could not determine disk space for: {$path}");
            return;
        }

        $minRequired = 1024 * 1024 * 1024;

        if ($freeSpace < $minRequired) {
            throw new MavenBuildException(
                "Insufficient disk space. Required: 1GB, Available: " . $this->formatBytes($freeSpace)
            );
        }
    }

    private function formatBytes(int $bytes): string
    {
        return round($bytes / (1024 * 1024 * 1024), 2) . ' GB';
    }

    public function addGoal(string $goal): self
    {
        $this->goals[] = $goal;

        return $this;
    }

    public function addProfile(string $profile): self
    {
        $this->profiles[] = $profile;

        return $this;
    }

    public function addSystemProperty(string $key, string $value): self
    {
        $this->systemProperties[$key] = $value;

        return $this;
    }

    public function execute(string $goal = 'package'): MavenBuildResult
    {
        $this->validateEnvironment();

        $startTime = microtime(true);

        $command = $this->buildCommand($goal);

        $process = new Process($command);
        $process->setTimeout(1800);
        $process->setWorkingDirectory(getcwd());

        $process->run();

        $duration = round(microtime(true) - $startTime, 2);

        $result = new MavenBuildResult(
            success: $process->isSuccessful(),
            command: implode(' ', array_map('escapeshellarg', $command)),
            exitCode: $process->getExitCode(),
            output: $process->getOutput(),
            error: $process->getErrorOutput(),
            duration: $duration
        );

        if ($result->isSuccessful()) {
            $this->parseBuildArtifacts();
        }

        return $result;
    }

    private function buildCommand(string $goal): array
    {
        $mvn = $this->env->usesMavenWrapper() ? self::MVN_WRAPPER : 'mvn';

        $command = [$mvn];

        if ($this->env->isOfflineMode()) {
            $command[] = '-o';
        }

        foreach ($this->profiles as $profile) {
            $command[] = '-P' . $profile;
        }

        foreach ($this->systemProperties as $key => $value) {
            $command[] = "-D{$key}={$value}";
        }

        if ($this->env->isQuietMode()) {
            $command[] = '-q';
        }

        if ($this->env->isVerboseMode()) {
            $command[] = '-X';
        }

        $command[] = '-f';
        $command[] = $this->env->getPomFile();

        $command[] = $goal;

        return $command;
    }

    private function parseBuildArtifacts(): void
    {
        $targetDir = getcwd() . '/target';

        if (!is_dir($targetDir)) {
            return;
        }

        $jarFiles = glob($targetDir . '/*.jar');

        foreach ($jarFiles as $jar) {
            $this->env->addArtifact(basename($jar), $jar);
        }

        $warFiles = glob($targetDir . '/*.war');

        foreach ($warFiles as $war) {
            $this->env->addArtifact(basename($war), $war);
        }
    }
}

class MavenEnvironment
{
    private string $mavenVersion = '';
    private string $javaHome = '';
    private string $javaVersion = '';
    private string $pomFile = 'pom.xml';
    private string $localRepositoryPath = '';
    private bool $usesWrapper = false;
    private bool $offlineMode = false;
    private bool $quietMode = false;
    private bool $verboseMode = false;
    private array $artifacts = [];
    private ?string $proxy = null;
    private array $mirrors = [];

    public function __construct()
    {
        $this->localRepositoryPath = getenv('HOME') . '/.m2/repository';
    }

    public function getMavenVersion(): string
    {
        return $this->mavenVersion;
    }

    public function setMavenVersion(string $version): void
    {
        $this->mavenVersion = $version;
    }

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

    public function getPomFile(): string
    {
        return $this->pomFile;
    }

    public function setPomFile(string $file): void
    {
        $this->pomFile = $file;
    }

    public function getLocalRepositoryPath(): string
    {
        return $this->localRepositoryPath;
    }

    public function usesMavenWrapper(): bool
    {
        return $this->usesWrapper;
    }

    public function setUsesWrapper(bool $uses): void
    {
        $this->usesWrapper = $uses;
    }

    public function isOfflineMode(): bool
    {
        return $this->offlineMode;
    }

    public function isQuietMode(): bool
    {
        return $this->quietMode;
    }

    public function isVerboseMode(): bool
    {
        return $this->verboseMode;
    }

    public function enableQuietMode(): self
    {
        $this->quietMode = true;
        return $this;
    }

    public function enableVerboseMode(): self
    {
        $this->verboseMode = true;
        return $this;
    }

    public function getMirrors(): array
    {
        return $this->mirrors;
    }

    public function setProxy(string $host, int $port): void
    {
        $this->proxy = "{$host}:{$port}";
    }

    public function addArtifact(string $name, string $path): void
    {
        $this->artifacts[$name] = $path;
    }

    public function getArtifacts(): array
    {
        return $this->artifacts;
    }
}
