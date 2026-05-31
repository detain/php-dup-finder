<?php

declare(strict_types=1);

namespace App\Build\Webpack;

class WebpackBundleProcessor
{
    private const BUILD_DIR = 'assets/dist';
    private const HASH_LENGTH = 8;

    private BundleConfig $config;
    private ManifestGenerator $manifest;
    private array $processedBundles = [];

    public function __construct(BundleConfig $config)
    {
        $this->config = $config;
        $this->manifest = new ManifestGenerator();
    }

    public function processBundle(string $entryPoint, array $loaders): BundleResult
    {
        $this->validateEntryPoint($entryPoint);

        $startTime = microtime(true);

        $content = $this->readEntryFile($entryPoint);

        $content = $this->applyLoaders($content, $loaders);

        $content = $this->transformContent($content);

        $outputPath = $this->generateOutputPath($entryPoint);

        $this->writeOutput($outputPath, $content);

        $this->manifest->addEntry($entryPoint, $outputPath);

        $this->processedBundles[$entryPoint] = $outputPath;

        $duration = round(microtime(true) - $startTime, 2);

        return new BundleResult(
            entry: $entryPoint,
            outputPath: $outputPath,
            size: strlen($content),
            duration: $duration,
            success: true
        );
    }

    private function validateEntryPoint(string $entry): void
    {
        if (empty($entry)) {
            throw new \InvalidArgumentException('Entry point cannot be empty');
        }

        if (!preg_match('/^[a-zA-Z0-9_\-\/]+(\.[a-zA-Z0-9]+)?$/', $entry)) {
            throw new \InvalidArgumentException(
                'Entry point contains invalid characters'
            );
        }
    }

    private function readEntryFile(string $entry): string
    {
        $fullPath = getcwd() . '/resources/js/' . $entry . '.js';

        if (!file_exists($fullPath)) {
            $fullPath = getcwd() . '/resources/assets/' . $entry . '.js';
        }

        if (!file_exists($fullPath)) {
            throw new \RuntimeException("Entry file not found: {$entry}");
        }

        return file_get_contents($fullPath);
    }

    private function applyLoaders(string $content, array $loaders): string
    {
        foreach ($loaders as $loader) {
            $content = $this->applyLoader($content, $loader);
        }

        return $content;
    }

    private function applyLoader(string $content, array $loader): string
    {
        $loaderType = $loader['type'] ?? 'babel';

        return match($loaderType) {
            'babel' => $this->applyBabelLoader($content, $loader),
            'ts' => $this->applyTypeScriptLoader($content, $loader),
            'css' => $this->applyCssLoader($content, $loader),
            'vue' => $this->applyVueLoader($content, $loader),
            'react' => $this->applyReactLoader($content, $loader),
            default => $content
        };
    }

    private function applyBabelLoader(string $content, array $config): string
    {
        $presets = $config['presets'] ?? [
            ['@babel/preset-env', ['targets' => 'defaults']],
            '@babel/preset-typescript'
        ];

        $tempInput = tempnam('/tmp', 'babel_');
        $tempOutput = tempnam('/tmp', 'babel_');

        file_put_contents($tempInput, $content);

        $command = [
            'npx', 'babel',
            $tempInput,
            '--out-file', $tempOutput
        ];

        foreach ($presets as $preset) {
            if (is_array($preset)) {
                $command[] = '--preset';
                $command[] = $preset[0];
                foreach ($preset[1] as $key => $value) {
                    $command[] = "--preset-options[{$key}]={$value}";
                }
            } else {
                $command[] = '--preset';
                $command[] = $preset;
            }
        }

        $process = new Process($command);
        $process->setTimeout(120);
        $process->run();

        if ($process->isSuccessful()) {
            $content = file_get_contents($tempOutput);
        }

        @unlink($tempInput);
        @unlink($tempOutput);

        return $content;
    }

    private function applyTypeScriptLoader(string $content, array $config): string
    {
        $tempInput = tempnam('/tmp', 'ts_');
        $tempOutput = tempnam('/tmp', 'ts_');

        file_put_contents($tempInput, $content);

        $command = [
            'npx', 'tsc',
            $tempInput,
            '--outFile', $tempOutput,
            '--module', 'ESNext',
            '--target', 'ES2020',
            '--strict',
            '--esModuleInterop'
        ];

        $process = new Process($command);
        $process->setTimeout(60);
        $process->run();

        if ($process->isSuccessful()) {
            $content = file_get_contents($tempOutput);
        }

        @unlink($tempInput);
        @unlink($tempOutput);

        return $content;
    }

    private function applyCssLoader(string $content, array $config): string
    {
        $importPattern = '/import\s+["\']([^"\']+\.css)["\']/';

        preg_match_all($importPattern, $content, $matches);

        foreach ($matches[1] as $cssFile) {
            $cssPath = $this->resolveCssPath($cssFile);
            $cssContent = file_get_contents($cssPath);

            $content = str_replace(
                "import '{$cssFile}';",
                "<style>{$cssContent}</style>",
                $content
            );
        }

        return $content;
    }

    private function resolveCssPath(string $file): string
    {
        $paths = [
            getcwd() . '/resources/css/' . $file,
            getcwd() . '/resources/assets/css/' . $file,
            getcwd() . '/node_modules/' . $file
        ];

        foreach ($paths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        throw new \RuntimeException("CSS file not found: {$file}");
    }

    private function applyVueLoader(string $content, array $config): string
    {
        $tempInput = tempnam('/tmp', 'vue_');
        $tempOutput = tempnam('/tmp', 'vue_');

        file_put_contents($tempInput, $content);

        $command = [
            'npx', 'vue-loader',
            $tempInput,
            '--output', $tempOutput
        ];

        $process = new Process($command);
        $process->setTimeout(60);
        $process->run();

        if ($process->isSuccessful()) {
            $content = file_get_contents($tempOutput);
        }

        @unlink($tempInput);
        @unlink($tempOutput);

        return $content;
    }

    private function applyReactLoader(string $content, array $config): string
    {
        $tempInput = tempnam('/tmp', 'react_');
        $tempOutput = tempnam('/tmp', 'react_');

        file_put_contents($tempInput, $content);

        $command = [
            'npx', 'babel',
            $tempInput,
            '--out-file', $tempOutput,
            '--preset', '@babel/preset-react'
        ];

        $process = new Process($command);
        $process->setTimeout(60);
        $process->run();

        if ($process->isSuccessful()) {
            $content = file_get_contents($tempOutput);
        }

        @unlink($tempInput);
        @unlink($tempOutput);

        return $content;
    }

    private function transformContent(string $content): string
    {
        if ($this->config->shouldMinify()) {
            $content = $this->minifyJs($content);
        }

        if ($this->config->shouldTreeShake()) {
            $content = $this->treeShake($content);
        }

        return $content;
    }

    private function minifyJs(string $content): string
    {
        $tempInput = tempnam('/tmp', 'minify_');
        $tempOutput = tempnam('/tmp', 'minify_');

        file_put_contents($tempInput, $content);

        $command = [
            'npx', 'terser',
            $tempInput,
            '--output', $tempOutput,
            '--compress',
            '--mangle'
        ];

        $process = new Process($command);
        $process->setTimeout(60);
        $process->run();

        if ($process->isSuccessful()) {
            $content = file_get_contents($tempOutput);
        }

        @unlink($tempInput);
        @unlink($tempOutput);

        return $content;
    }

    private function treeShake(string $content): string
    {
        return $content;
    }

    private function generateOutputPath(string $entry): string
    {
        $baseName = basename($entry);
        $hash = $this->generateFileHash($entry);

        return self::BUILD_DIR . "/{$baseName}.{$hash}.js";
    }

    private function generateFileHash(string $entry): string
    {
        $filePath = getcwd() . '/resources/js/' . $entry . '.js';

        if (file_exists($filePath)) {
            return substr(md5_file($filePath), 0, self::HASH_LENGTH);
        }

        return substr(md5($entry), 0, self::HASH_LENGTH);
    }

    private function writeOutput(string $path, string $content): void
    {
        $fullPath = getcwd() . '/' . $path;
        $dir = dirname($fullPath);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($fullPath, $content);
    }

    public function getManifest(): array
    {
        return $this->manifest->toArray();
    }
}

class ManifestGenerator
{
    private array $entries = [];

    public function addEntry(string $entry, string $output): void
    {
        $this->entries[$entry] = $output;
    }

    public function toArray(): array
    {
        return $this->entries;
    }

    public function get(string $entry): ?string
    {
        return $this->entries[$entry] ?? null;
    }
}

class BundleConfig
{
    private bool $minify;
    private bool $sourceMaps;
    private bool $treeShake;
    private array $loaders;

    public function shouldMinify(): bool
    {
        return $this->minify;
    }

    public function shouldTreeShake(): bool
    {
        return $this->treeShake;
    }

    public function shouldSourceMaps(): bool
    {
        return $this->sourceMaps;
    }
}
