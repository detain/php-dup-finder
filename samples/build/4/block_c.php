<?php

declare(strict_types=1);

namespace App\Build;

class AssetCompilerService
{
    private const CACHE_DIR = 'var/cache/assets';
    private const HASH_ALGO = 'md5';

    private CompilerOptions $options;
    private CacheManager $cache;
    private array $compiledFiles = [];

    public function __construct(CompilerOptions $options, CacheManager $cache)
    {
        $this->options = $options;
        $this->cache = $cache;
    }

    public function compileFile(string $inputPath, string $outputPath): CompilationResult
    {
        $this->validateInputPath($inputPath);

        $cacheKey = $this->generateCacheKey($inputPath);
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null && $this->options->useCache()) {
            $this->logger->info("Using cached compilation for: {$inputPath}");

            return new CompilationResult(
                success: true,
                inputPath: $inputPath,
                outputPath: $cached,
                fromCache: true
            );
        }

        $startTime = microtime(true);

        $content = file_get_contents($inputPath);

        $content = $this->resolveImports($content, $inputPath);

        $content = $this->processTransformers($content, $inputPath);

        if ($this->options->shouldMinify()) {
            $content = $this->minifyContent($content, $inputPath);
        }

        $this->ensureDirectoryExists(dirname($outputPath));

        file_put_contents($outputPath, $content);

        $this->cache->set($cacheKey, $outputPath);

        $this->compiledFiles[$inputPath] = $outputPath;

        $duration = round(microtime(true) - $startTime, 2);

        $this->logger->info("Compiled: {$inputPath} -> {$outputPath} ({$duration}s)");

        return new CompilationResult(
            success: true,
            inputPath: $inputPath,
            outputPath: $outputPath,
            fromCache: false,
            duration: $duration,
            size: strlen($content)
        );
    }

    private function validateInputPath(string $path): void
    {
        if (!file_exists($path)) {
            throw new \RuntimeException("Input file not found: {$path}");
        }

        $allowedExtensions = ['js', 'ts', 'jsx', 'tsx', 'css', 'scss', 'sass', 'less'];

        $extension = pathinfo($path, PATHINFO_EXTENSION);

        if (!in_array($extension, $allowedExtensions, true)) {
            throw new \InvalidArgumentException(
                "Unsupported file extension: {$extension}"
            );
        }
    }

    private function generateCacheKey(string $path): string
    {
        $mtime = filemtime($path);
        $hash = hash(self::HASH_ALGO, "{$path}:{$mtime}");

        return substr($hash, 0, 16);
    }

    private function resolveImports(string $content, string $sourceFile): string
    {
        $importPatterns = [
            '/import\s+(?:{\s*)?([\w*\s,]+)\s*(?:})?\s*from\s+["\']([^"\']+)["\']/',
            '/require\s*\(\s*["\']([^"\']+)["\']\s*\)/',
            '/@import\s+["\']([^"\']+)["\']/'
        ];

        $baseDir = dirname($sourceFile);
        $contentDir = getcwd() . '/resources/assets';

        foreach ($importPatterns as $pattern) {
            preg_match_all($pattern, $content, $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                $importPath = $match[1];

                $resolvedPath = $this->resolveImportPath($importPath, $baseDir, $contentDir);

                if ($resolvedPath !== null && file_exists($resolvedPath)) {
                    $importContent = file_get_contents($resolvedPath);

                    if ($this->options->shouldMinifyImports()) {
                        $importContent = $this->minifyImportContent($importContent, $resolvedPath);
                    }

                    $content = str_replace($match[0], $importContent, $content);
                }
            }
        }

        return $content;
    }

    private function resolveImportPath(string $importPath, string $baseDir, string $contentDir): ?string
    {
        if (str_starts_with($importPath, '.')) {
            $absolutePath = realpath($baseDir . '/' . $importPath);
            if ($absolutePath !== false && file_exists($absolutePath)) {
                return $absolutePath;
            }
        }

        $nodeModulesPath = getcwd() . '/node_modules/' . $importPath;
        if (file_exists($nodeModulesPath)) {
            return $nodeModulesPath;
        }

        $contentPath = $contentDir . '/' . $importPath;
        if (file_exists($contentPath)) {
            return $contentPath;
        }

        return null;
    }

    private function processTransformers(string $content, string $sourceFile): string
    {
        $extension = pathinfo($sourceFile, PATHINFO_EXTENSION);

        $transformers = $this->options->getTransformers($extension);

        foreach ($transformers as $transformer) {
            $content = $this->applyTransformer($content, $transformer);
        }

        return $content;
    }

    private function applyTransformer(string $content, string $transformer): string
    {
        $tempInput = tempnam('/tmp', 'transform_');
        $tempOutput = tempnam('/tmp', 'transform_');

        file_put_contents($tempInput, $content);

        $command = $this->buildTransformerCommand($transformer, $tempInput, $tempOutput);

        $process = new Process($command);
        $process->setTimeout(60);
        $process->run();

        if ($process->isSuccessful()) {
            $content = file_get_contents($tempOutput);
        } else {
            $this->logger->warning(
                "Transformer {$transformer} failed: " . $process->getErrorOutput()
            );
        }

        @unlink($tempInput);
        @unlink($tempOutput);

        return $content;
    }

    private function buildTransformerCommand(string $transformer, string $input, string $output): array
    {
        return match($transformer) {
            'babel' => ['npx', 'babel', $input, '--out-file', $output],
            'postcss' => ['npx', 'postcss', $input, '-o', $output],
            'sass' => ['npx', 'sass', $input, $output],
            'terser' => ['npx', 'terser', $input, '-o', $output],
            default => throw new \InvalidArgumentException("Unknown transformer: {$transformer}")
        };
    }

    private function minifyContent(string $content, string $sourceFile): string
    {
        $extension = pathinfo($sourceFile, PATHINFO_EXTENSION);

        $minifier = $this->getMinifier($extension);

        if ($minifier === null) {
            return $content;
        }

        $tempInput = tempnam('/tmp', 'minify_');
        $tempOutput = tempnam('/tmp', 'minify_');

        file_put_contents($tempInput, $content);

        $command = $this->buildMinifierCommand($minifier, $tempInput, $tempOutput);

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

    private function getMinifier(string $extension): ?string
    {
        return match($extension) {
            'js', 'ts', 'jsx', 'tsx' => 'terser',
            'css', 'scss', 'sass' => 'clean-css',
            'less' => 'less',
            default => null
        };
    }

    private function buildMinifierCommand(string $minifier, string $input, string $output): array
    {
        return match($minifier) {
            'terser' => [
                'npx', 'terser', $input,
                '--output', $output,
                '--compress',
                '--mangle'
            ],
            'clean-css' => [
                'npx', 'cleancss',
                '-o', $output,
                $input
            ],
            'less' => [
                'npx', 'lessc',
                $input, $output,
                '--compress'
            ],
            default => throw new \InvalidArgumentException("Unknown minifier: {$minifier}")
        };
    }

    private function minifyImportContent(string $content, string $sourceFile): string
    {
        $extension = pathinfo($sourceFile, PATHINFO_EXTENSION);

        if ($extension === 'css') {
            return preg_replace('/\s+/', ' ', $content);
        }

        return $content;
    }

    private function ensureDirectoryExists(string $path): void
    {
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }
    }

    public function compileMultiple(array $files): array
    {
        $results = [];

        foreach ($files as $file) {
            $outputPath = $this->generateOutputPath($file);

            $results[] = $this->compileFile($file, $outputPath);
        }

        return $results;
    }

    private function generateOutputPath(string $inputPath): string
    {
        $baseName = pathinfo($inputPath, PATHINFO_FILENAME);
        $extension = pathinfo($inputPath, PATHINFO_EXTENSION);

        $hash = substr(md5_file($inputPath), 0, 8);

        return "public/build/{$baseName}.{$hash}.{$extension}";
    }
}

class CacheManager
{
    private string $cacheDir;

    public function __construct(string $cacheDir)
    {
        $this->cacheDir = $cacheDir;
    }

    public function get(string $key): ?string
    {
        $path = $this->getCachePath($key);

        if (!file_exists($path)) {
            return null;
        }

        $data = json_decode(file_get_contents($path), true);

        if ($data['expires'] < time()) {
            @unlink($path);
            return null;
        }

        return $data['output'];
    }

    public function set(string $key, string $output): void
    {
        $path = $this->getCachePath($key);

        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($path, json_encode([
            'output' => $output,
            'expires' => time() + 86400
        ]));
    }

    private function getCachePath(string $key): string
    {
        return "{$this->cacheDir}/{$key}.json";
    }
}
