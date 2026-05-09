<?php
declare(strict_types=1);

namespace Phpdup\Cli;

/**
 * Resolved, validated runtime configuration.
 *
 * Constructed by ConfigLoader from a phpdup.json file plus CLI overrides.
 */
final class Config
{
    /**
     * @param list<string> $paths
     * @param list<string> $exclude
     * @param list<string> $allowedKinds Empty list = accept all kinds.
     */
    public function __construct(
        public readonly array $paths,
        public readonly array $exclude,
        public readonly int $minBlockSize = 8,
        public readonly int $maxBlockSize = 800,
        public readonly string $normalizationMode = 'aggressive',
        public readonly float $similarityThreshold = 0.80,
        public readonly float $treeThreshold = 0.85,
        public readonly int $minClusterImpact = 20,
        public readonly float $maxDocumentFrequency = 0.01,
        public readonly int $ngramSize = 5,
        public readonly string $cacheDir = '.phpdup-cache',
        public readonly string $parallelism = 'auto',
        public readonly ?string $htmlReportDir = null,
        public readonly ?string $jsonReportFile = null,
        public readonly int $workers = 0,
        public readonly bool $incremental = true,
        public readonly bool $lazyAst = true,
        public readonly array $allowedKinds = [],
    ) {
        if (!in_array($normalizationMode, ['strict', 'default', 'aggressive'], true)) {
            throw new \InvalidArgumentException("Invalid normalization mode: $normalizationMode");
        }
        if ($similarityThreshold < 0 || $similarityThreshold > 1) {
            throw new \InvalidArgumentException("similarity_threshold out of range");
        }
        if ($treeThreshold < 0 || $treeThreshold > 1) {
            throw new \InvalidArgumentException("tree_threshold out of range");
        }
    }

    /** @param list<string> $paths */
    public static function defaults(array $paths): self
    {
        return new self(
            paths: $paths,
            exclude: [
                'vendor/**', 'node_modules/**', 'logs/**', 'cache/**',
                'storage/**', 'build/**', 'dist/**', '**/*.tpl.php',
                '**/.phpdup-cache/**', '**/phpdup-report/**',
            ],
        );
    }
}
