<?php

declare(strict_types=1);

namespace App\Assets;

class AssetPipeline
{
    private const OUTPUT_DIR = 'public/build';
    private const MANIFEST_FILE = 'public/build/mix-manifest.json';

    private array $config;
    private AssetManifest $manifest;
    private array $compiledAssets = [];

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'enable_source_maps' => true,
            'minify' => true,
            'hash_filename' => true,
            'max_workers' => 4,
            'cache_bust' => true
        ], $config);

        $this->manifest = new AssetManifest();
    }

    public function compile(array $assets): AssetCompilationResult
    {
        $this->validateAssets($assets);

        $startTime = microtime(true);

        try {
            $this->ensureOutputDirectory();

            $bundles = $this->groupAssetsByType($assets);

            foreach ($bundles as $type => $assetList) {
                $this->compileAssetType($type, $assetList);
            }

            $this->generateSourceMaps();
            $this->writeManifest();

            $duration = round(microtime(true) - $startTime, 2);

            return new AssetCompilationResult(
                success: true,
                outputDir: self::OUTPUT_DIR,
                files: $this->compiledAssets,
                duration: $duration
            );
        } catch (\Throwable $e) {
            return new AssetCompilationResult(
                success: false,
                outputDir: self::OUTPUT_DIR,
                files: [],
                duration: 0,
                error: $e->getMessage()
            );
        }
    }

    private function validateAssets(array $assets): void
    {
        foreach ($assets as $asset) {
            if (!isset($asset['source'], $asset['type'])) {
                throw new \InvalidArgumentException(
                    'Asset must have source and type properties'
                );
            }

            if (!file_exists($asset['source'])) {
                throw new \RuntimeException(
                    "Asset source file not found: {$asset['source']}"
                );
            }

            $supportedTypes = ['js', 'css', 'scss', 'less', 'ts', 'vue', 'jsx'];

            if (!in_array($asset['type'], $supportedTypes, true)) {
                throw new \InvalidArgumentException(
                    "Unsupported asset type: {$asset['type']}"
                );
            }
        }
    }

    private function ensureOutputDirectory(): void
    {
        $basePath = getcwd();

        foreach (['js', 'css', 'images', 'fonts'] as $subdir) {
            $path = "{$basePath}/" . self::OUTPUT_DIR . "/{$subdir}";

            if (!is_dir($path)) {
                mkdir($path, 0755, true);
            }
        }
    }

    private function groupAssetsByType(array $assets): array
    {
        $grouped = [];

        foreach ($assets as $asset) {
            $type = $asset['type'];
            $grouped[$type][] = $asset;
        }

        return $grouped;
    }

    private function compileAssetType(string $type, array $assets): void
    {
        foreach ($assets as $asset) {
            $outputName = $this->processAsset($asset);

            $this->compiledAssets[$asset['source']] = $outputName;

            $this->manifest->add($asset['source'], $outputName);

            $this->logger->info("Compiled asset: {$asset['source']} -> {$outputName}");
        }
    }

    private function processAsset(array $asset): string
    {
        $content = file_get_contents($asset['source']);

        $content = $this->resolveDependencies($content, $asset['type']);

        if ($this->config['minify']) {
            $content = $this->minify($content, $asset['type']);
        }

        if ($this->config['enable_source_maps']) {
            $content = $this->appendSourceMap($content, $asset);
        }

        $outputName = $this->generateOutputName($asset);

        $outputPath = getcwd() . '/' . self::OUTPUT_DIR . "/{$outputName}";

        file_put_contents($outputPath, $content);

        return $outputName;
    }

    private function resolveDependencies(string $content, string $type): string
    {
        $importPattern = '/@import\s+["\']([^"\']+)["\'];?/';

        if ($type === 'css' || $type === 'scss' || $type === 'less') {
            preg_match_all($importPattern, $content, $matches);

            foreach ($matches[1] as $importPath) {
                $fullPath = $this->resolveImportPath($importPath);

                if (file_exists($fullPath)) {
                    $importContent = file_get_contents($fullPath);
                    $content = str_replace(
                        "@import \"{$importPath}\";",
                        $importContent,
                        $content
                    );
                }
            }
        }

        return $content;
    }

    private function resolveImportPath(string $path): string
    {
        $basePath = getcwd();
        $possiblePaths = [
            "{$basePath}/resources/assets/{$path}",
            "{$basePath}/resources/assets/scss/{$path}",
            "{$basePath}/node_modules/{$path}"
        ];

        foreach ($possiblePaths as $possiblePath) {
            if (file_exists($possiblePath)) {
                return $possiblePath;
            }
        }

        return $path;
    }

    private function minify(string $content, string $type): string
    {
        if ($type === 'js' || $type === 'ts') {
            return $this->minifyJs($content);
        }

        if ($type === 'css' || $type === 'scss') {
            return $this->minifyCss($content);
        }

        return $content;
    }

    private function minifyJs(string $content): string
    {
        $tempInput = tempnam('/tmp', 'js_minify_');
        $tempOutput = tempnam('/tmp', 'js_minify_');

        file_put_contents($tempInput, $content);

        $command = [
            'node',
            '/usr/local/bin/terser',
            $tempInput,
            '-o', $tempOutput,
            '--compress',
            '--mangle',
            '--comments', 'false'
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

    private function minifyCss(string $content): string
    {
        $tempInput = tempnam('/tmp', 'css_minify_');
        $tempOutput = tempnam('/tmp', 'css_minify_');

        file_put_contents($tempInput, $content);

        $command = [
            'node',
            '/usr/local/bin/clean-css-cli',
            $tempInput,
            '-o', $tempOutput
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

    private function generateOutputName(array $asset): string
    {
        $extension = $this->getOutputExtension($asset['type']);
        $baseName = pathinfo($asset['source'], PATHINFO_FILENAME);

        if ($this->config['hash_filename']) {
            $hash = substr(md5_file($asset['source']), 0, 8);
            return "{$baseName}.{$hash}.{$extension}";
        }

        return "{$baseName}.{$extension}";
    }

    private function getOutputExtension(string $type): string
    {
        return match($type) {
            'ts', 'tsx', 'jsx' => 'js',
            'scss', 'less' => 'css',
            default => $type
        };
    }

    private function appendSourceMap(string $content, array $asset): string
    {
        $sourceMapPath = $this->generateSourceMapPath($asset);
        $sourceMapContent = $this->generateSourceMapContent($asset);

        $sourceMapFile = getcwd() . '/' . self::OUTPUT_DIR . "/{$sourceMapPath}";
        file_put_contents($sourceMapFile, $sourceMapContent);

        return $content . "\n/*# sourceMappingURL={$sourceMapPath} */";
    }

    private function generateSourceMapPath(array $asset): string
    {
        $outputName = $this->generateOutputName($asset);
        return "{$outputName}.map";
    }

    private function generateSourceMapContent(array $asset): string
    {
        $outputName = $this->generateOutputName($asset);
        $sourcePath = $asset['source'];

        $sourceMap = [
            'version' => 3,
            'file' => $outputName,
            'sources' => [$sourcePath],
            'sourcesContent' => [file_get_contents($sourcePath)],
            'mappings' => ''
        ];

        return json_encode($sourceMap);
    }

    private function generateSourceMaps(): void
    {
        foreach ($this->compiledAssets as $source => $output) {
            $sourceMapPath = "{$output}.map";
            $sourceMapFile = getcwd() . '/' . self::OUTPUT_DIR . "/{$sourceMapPath}";

            if (file_exists($sourceMapFile)) {
                $content = file_get_contents($sourceMapFile);
                $decoded = json_decode($content, true);

                if (isset($decoded['sourcesContent'][0])) {
                    $decoded['sourcesContent'][0] = file_get_contents($source);
                    file_put_contents($sourceMapFile, json_encode($decoded));
                }
            }
        }
    }

    private function writeManifest(): void
    {
        $manifestPath = getcwd() . '/' . self::MANIFEST_FILE;

        $manifestDir = dirname($manifestPath);
        if (!is_dir($manifestDir)) {
            mkdir($manifestDir, 0755, true);
        }

        file_put_contents(
            $manifestPath,
            json_encode($this->manifest->toArray(), JSON_PRETTY_PRINT)
        );
    }
}

class AssetManifest
{
    private array $mappings = [];

    public function add(string $source, string $output): void
    {
        $this->mappings[$source] = $output;
    }

    public function toArray(): array
    {
        return $this->mappings;
    }

    public function get(string $source): ?string
    {
        return $this->mappings[$source] ?? null;
    }
}
