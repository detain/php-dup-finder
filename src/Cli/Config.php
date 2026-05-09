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
        // Type-3 / "optional segment" detection: cluster pairs where one block is a
        // near-subset of the other (extra statements not present in every member),
        // then synthesize a boolean parameter for each optional segment.
        public readonly bool $optionalBlocksEnabled = true,
        // Containment threshold: similarity ≥ this AND size_ratio ≥ minOverlap means
        // "near-subset" — a candidate pair Jaccard would have rejected.
        public readonly float $optionalBlocksContainment = 0.85,
        // Minimum size ratio (smaller / larger by n-gram mass) for a near-subset
        // pair to be kept. Prevents pairing a 1-line block with a 100-line block.
        public readonly float $optionalBlocksMinOverlap = 0.6,
        // Cap optional segments per cluster so over-flexible clusters don't bloat
        // the suggested signature. When the count would exceed, AntiUnifier falls
        // back to the legacy whole-array hole.
        public readonly int $optionalBlocksMaxPerCluster = 3,
        // Don't promote single-statement gaps below this length (in raw stmts) to
        // optional_block holes — too noisy on tiny mismatches.
        public readonly int $optionalBlocksMinSegmentLength = 1,
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
        if ($optionalBlocksContainment < 0 || $optionalBlocksContainment > 1) {
            throw new \InvalidArgumentException("optional_blocks_containment out of range");
        }
        if ($optionalBlocksMinOverlap < 0 || $optionalBlocksMinOverlap > 1) {
            throw new \InvalidArgumentException("optional_blocks_min_overlap out of range");
        }
        if ($optionalBlocksMaxPerCluster < 0) {
            throw new \InvalidArgumentException("optional_blocks_max_per_cluster must be >= 0");
        }
        if ($optionalBlocksMinSegmentLength < 1) {
            throw new \InvalidArgumentException("optional_blocks_min_segment_length must be >= 1");
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
