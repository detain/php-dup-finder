<?php

declare(strict_types=1);

namespace App\Build\Python;

class PythonPackageBuilder
{
    private const PYPI_URL = 'https://upload.pypi.org/legacy/';
    private const TEST_PYPI_URL = 'https://test.pypi.org/legacy/';

    private Config $config;
    private HttpClient $httpClient;
    private array $builtPackages = [];

    public function __construct(Config $config, HttpClient $httpClient)
    {
        $this->config = $config;
        $this->httpClient = $httpClient;
    }

    public function buildPackage(string $packageDir, PythonBuildOptions $options): PythonBuildResult
    {
        $this->validatePackageDir($packageDir);
        $this->validateSetupPy($packageDir);

        $startTime = microtime(true);

        $this->cleanPreviousBuild($packageDir);
        $this->installBuildDependencies($packageDir, $options);
        $this->generateAstParser($packageDir);
        $this->runTestSuite($packageDir, $options);
        $this->buildDistribution($packageDir, $options);
        $packagePath = $this->findBuiltPackage($packageDir);

        $metadata = $this->extractPackageMetadata($packageDir);

        $this->builtPackages[] = $packagePath;

        $duration = round(microtime(true) - $startTime, 2);

        $this->logger->info(
            "Package built successfully: {$metadata['name']} v{$metadata['version']}"
        );

        return new PythonBuildResult(
            success: true,
            packagePath: $packagePath,
            duration: $duration,
            metadata: $metadata
        );
    }

    private function validatePackageDir(string $dir): void
    {
        if (!is_dir($dir)) {
            throw new \InvalidArgumentException("Package directory does not exist: {$dir}");
        }

        $setupFiles = ['setup.py', 'setup.cfg', 'pyproject.toml'];
        $hasSetup = false;

        foreach ($setupFiles as $file) {
            if (file_exists($dir . '/' . $file)) {
                $hasSetup = true;
                break;
            }
        }

        if (!$hasSetup) {
            throw new \InvalidArgumentException(
                "No setup.py, setup.cfg, or pyproject.toml found in: {$dir}"
            );
        }
    }

    private function validateSetupPy(string $dir): void
    {
        $setupPy = $dir . '/setup.py';

        if (!file_exists($setupPy)) {
            return;
        }

        $content = file_get_contents($setupPy);

        if (str_contains($content, 'setuptools')) {
            return;
        }

        $this->logger->warning("setup.py does not import setuptools");
    }

    private function cleanPreviousBuild(string $packageDir): void
    {
        $this->logger->info("Cleaning previous build artifacts...");

        $dirsToClean = [
            'build',
            'dist',
            '*.egg-info',
            '__pycache__'
        ];

        foreach ($dirsToClean as $pattern) {
            if (str_contains($pattern, '*')) {
                $files = glob($packageDir . '/' . $pattern);

                foreach ($files as $file) {
                    if (is_dir($file)) {
                        $this->removeDirectory($file);
                    } else {
                        unlink($file);
                    }
                }
            } else {
                $path = $packageDir . '/' . $pattern;

                if (is_dir($path)) {
                    $this->removeDirectory($path);
                } elseif (file_exists($path)) {
                    unlink($path);
                }
            }
        }

        $this->recursiveCleanCache($packageDir);

        $this->logger->info("Previous build artifacts removed");
    }

    private function recursiveCleanCache(string $dir): void
    {
        $cacheDirs = ['__pycache__', '.pytest_cache', '.mypy_cache', '.tox'];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $path) {
            $basename = basename($path);

            if (in_array($basename, $cacheDirs, true)) {
                if ($path->isDir()) {
                    $this->removeDirectory((string)$path);
                } else {
                    unlink((string)$path);
                }
            }
        }
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                rmdir((string)$file);
            } else {
                unlink((string)$file);
            }
        }

        rmdir($path);
    }

    private function installBuildDependencies(string $packageDir, PythonBuildOptions $options): void
    {
        $this->logger->info("Installing build dependencies...");

        $buildDeps = $this->getBuildDependencies($packageDir);

        if (empty($buildDeps)) {
            $this->logger->info("No build dependencies to install");
            return;
        }

        $command = array_merge(
            ['pip', 'install', '--upgrade'],
            $buildDeps
        );

        $process = new Process($command);
        $process->setTimeout(300);
        $process->setWorkingDirectory($packageDir);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(
                "Failed to install build dependencies: " . $process->getErrorOutput()
            );
        }

        $this->logger->info("Build dependencies installed successfully");
    }

    private function getBuildDependencies(string $packageDir): array
    {
        $pyprojectToml = $packageDir . '/pyproject.toml';

        if (file_exists($pyprojectToml)) {
            $content = file_get_contents($pyprojectToml);

            preg_match_all('/^requires\s*=\s*\[(.*?)\]/ms', $content, $matches);

            if (!empty($matches[1])) {
                $deps = preg_split('/,\s*/', trim($matches[1][0], "[]\n "));

                return array_filter($deps, fn($dep) => !empty(trim($dep)));
            }
        }

        return ['setuptools>=45', 'wheel', 'twine'];
    }

    private function generateAstParser(string $packageDir): void
    {
        $this->logger->info("Generating AST parser...");

        $pyprojectToml = $packageDir . '/pyproject.toml';

        if (!file_exists($pyprojectToml)) {
            $setupPy = $packageDir . '/setup.py';
            $content = file_get_contents($setupPy);

            preg_match_all('/install_requires\s*=\s*\[(.*?)\]/s', $content, $matches);

            if (!empty($matches[1])) {
                $deps = $this->parseRequirements($matches[1][0]);

                $this->logger->info("Found dependencies: " . implode(', ', $deps));
            }
        }

        $this->logger->info("AST parsing setup complete");
    }

    private function parseRequirements(string $reqString): array
    {
        $reqString = trim($reqString, "[]\n ");
        $reqString = str_replace(['"', "'"], '', $reqString);

        $reqs = preg_split('/,\s*/', $reqString);

        return array_filter($reqs, fn($req) => !empty(trim($req)));
    }

    private function runTestSuite(string $packageDir, PythonBuildOptions $options): void
    {
        if (!$options->shouldRunTests()) {
            return;
        }

        $this->logger->info("Running test suite...");

        $testDirs = ['tests', 'test', 'Tests', 'Test'];

        foreach ($testDirs as $testDir) {
            $fullPath = $packageDir . '/' . $testDir;

            if (is_dir($fullPath)) {
                $this->runPytest($fullPath, $options);
                break;
            }
        }

        $this->logger->info("Tests completed");
    }

    private function runPytest(string $testDir, PythonBuildOptions $options): void
    {
        $command = [
            'python', '-m', 'pytest',
            $testDir,
            '-v',
            '--tb=short',
            '--color=yes'
        ];

        if ($options->getCoverage()) {
            $command[] = '--cov';
            $command[] = 'src';
            $command[] = '--cov-report=term-missing';
        }

        $process = new Process($command);
        $process->setTimeout(300);
        $process->setWorkingDirectory(dirname($testDir));
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(
                "Tests failed: " . $process->getErrorOutput()
            );
        }
    }

    private function buildDistribution(string $packageDir, PythonBuildOptions $options): void
    {
        $this->logger->info("Building distribution packages...");

        $command = [
            'python', '-m', 'build',
            '--outdir', 'dist',
            '.'
        ];

        $process = new Process($command);
        $process->setTimeout(300);
        $process->setWorkingDirectory($packageDir);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(
                "Build failed: " . $process->getErrorOutput()
            );
        }

        $this->logger->info("Distribution packages built");
    }

    private function findBuiltPackage(string $packageDir): string
    {
        $distDir = $packageDir . '/dist';

        if (!is_dir($distDir)) {
            throw new \RuntimeException("dist directory not found after build");
        }

        $packages = glob($distDir . '/*.whl');

        if (empty($packages)) {
            $packages = glob($distDir . '/*.tar.gz');
        }

        if (empty($packages)) {
            throw new \RuntimeException("No package files found in dist/");
        }

        return $packages[0];
    }

    private function extractPackageMetadata(string $packageDir): array
    {
        $pyprojectToml = $packageDir . '/pyproject.toml';

        if (file_exists($pyprojectToml)) {
            $content = file_get_contents($pyprojectToml);

            preg_match('/name\s*=\s*["\']([^"\']+)["\']/', $content, $nameMatch);
            preg_match('/version\s*=\s*["\']([^"\']+)["\']/', $content, $versionMatch);

            return [
                'name' => $nameMatch[1] ?? basename($packageDir),
                'version' => $versionMatch[1] ?? 'unknown'
            ];
        }

        $setupPy = $packageDir . '/setup.py';

        if (file_exists($setupPy)) {
            $content = file_get_contents($setupPy);

            preg_match('/name\s*=\s*["\']([^"\']+)["\']/', $content, $nameMatch);
            preg_match('/version\s*=\s*["\']([^"\']+)["\']/', $content, $versionMatch);

            return [
                'name' => $nameMatch[1] ?? basename($packageDir),
                'version' => $versionMatch[1] ?? 'unknown'
            ];
        }

        return ['name' => basename($packageDir), 'version' => 'unknown'];
    }

    public function uploadToTestPyPI(string $packagePath): void
    {
        $this->uploadPackage($packagePath, self::TEST_PYPI_URL, 'test');
    }

    public function uploadToPyPI(string $packagePath, string $apiToken): void
    {
        $this->uploadPackage($packagePath, self::PYPI_URL, 'production', $apiToken);
    }

    private function uploadPackage(
        string $packagePath,
        string $url,
        string $environment,
        ?string $apiToken = null
    ): void {
        $this->logger->info("Uploading package to {$environment} PyPI...");

        $command = [
            'python', '-m', 'twine',
            'upload',
            $packagePath,
            '--repository-url', $url
        ];

        if ($apiToken !== null) {
            $command[] = '--username';
            $command[] = '__token__';
            $command[] = '--password';
            $command[] = $apiToken;
        }

        $process = new Process($command);
        $process->setTimeout(120);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(
                "Upload failed: " . $process->getErrorOutput()
            );
        }

        $this->logger->info("Package uploaded successfully to {$environment}");
    }
}
