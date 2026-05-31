<?php

declare(strict_types=1);

namespace App\Build\AssetPipeline;

interface AssetProcessorInterface
{
    public function process(string $content, array $options = []): string;
    public function supports(string $extension): bool;
    public function getPriority(): int;
}

interface AssetPipelineInterface
{
    public function compile(string $inputPath, string $outputPath): CompilationResult;
    public function addProcessor(AssetProcessorInterface $processor): void;
}

abstract class AbstractAssetProcessor implements AssetProcessorInterface
{
    protected LoggerInterface $logger;
    protected int $priority = 0;

    public function getPriority(): int
    {
        return $this->priority;
    }

    abstract public function process(string $content, array $options = []): string;

    abstract public function supports(string $extension): bool;
}

class MinifyProcessor extends AbstractAssetProcessor
{
    public function supports(string $extension): bool
    {
        return in_array($extension, ['js', 'ts', 'css', 'scss']);
    }

    public function process(string $content, array $options = []): string
    {
        $type = $options['type'] ?? 'js';

        $tempInput = tempnam('/tmp', 'minify_');
        $tempOutput = tempnam('/tmp', 'minify_');

        file_put_contents($tempInput, $content);

        $command = $this->buildCommand($type, $tempInput, $tempOutput);
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

    private function buildCommand(string $type, string $input, string $output): array
    {
        return match($type) {
            'js', 'ts' => ['npx', 'terser', $input, '-o', $output, '--compress', '--mangle'],
            'css', 'scss' => ['npx', 'cleancss', '-o', $output, $input],
            default => throw new \InvalidArgumentException("Unsupported type: {$type}")
        };
    }
}

class ImportResolverProcessor extends AbstractAssetProcessor
{
    private string $baseDir;

    public function supports(string $extension): bool
    {
        return in_array($extension, ['js', 'ts', 'css', 'scss']);
    }

    public function process(string $content, array $options = []): string
    {
        $this->baseDir = $options['baseDir'] ?? getcwd();

        return $this->resolveImports($content, $extension);
    }

    private function resolveImports(string $content, string $extension): string
    {
        $pattern = $extension === 'js' || $extension === 'ts'
            ? '/import\s+.*?from\s+["\']([^"\']+)["\']/'
            : '/@import\s+["\']([^"\']+)["\']/';

        preg_match_all($pattern, $content, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $resolved = $this->resolvePath($match[1]);

            if ($resolved !== null) {
                $importContent = file_get_contents($resolved);
                $content = str_replace($match[0], $importContent, $content);
            }
        }

        return $content;
    }

    private function resolvePath(string $import): ?string
    {
        if (str_starts_with($import, '.')) {
            $path = realpath($this->baseDir . '/' . $import);
            return $path !== false ? $path : null;
        }

        $nodeModules = getcwd() . '/node_modules/' . $import;
        return file_exists($nodeModules) ? $nodeModules : null;
    }
}

class AssetPipeline implements AssetPipelineInterface
{
    private array $processors = [];
    private CacheManager $cache;
    private LoggerInterface $logger;

    public function __construct(CacheManager $cache, LoggerInterface $logger)
    {
        $this->cache = $cache;
        $this->logger = $logger;

        $this->registerDefaultProcessors();
    }

    private function registerDefaultProcessors(): void
    {
        $this->addProcessor(new ImportResolverProcessor());
        $this->addProcessor(new MinifyProcessor());
    }

    public function addProcessor(AssetProcessorInterface $processor): void
    {
        $this->processors[] = $processor;

        usort($this->processors, fn($a, $b) => $a->getPriority() <=> $b->getPriority());
    }

    public function compile(string $inputPath, string $outputPath): CompilationResult
    {
        $cacheKey = $this->generateCacheKey($inputPath);

        if ($cached = $this->cache->get($cacheKey)) {
            return new CompilationResult(success: true, outputPath: $cached, fromCache: true);
        }

        $content = file_get_contents($inputPath);
        $extension = pathinfo($inputPath, PATHINFO_EXTENSION);

        foreach ($this->processors as $processor) {
            if ($processor->supports($extension)) {
                $content = $processor->process($content, [
                    'type' => $extension,
                    'baseDir' => dirname($inputPath)
                ]);
            }
        }

        file_put_contents($outputPath, $content);
        $this->cache->set($cacheKey, $outputPath);

        return new CompilationResult(success: true, outputPath: $outputPath, fromCache: false);
    }

    private function generateCacheKey(string $path): string
    {
        return substr(md5_file($path), 0, 16);
    }
}
