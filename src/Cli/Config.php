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
        // Cluster sort spec (e.g. "impact:desc", "members:desc", "block-size:asc").
        // Format: KEY[:DIRECTION]. See \Phpdup\Reporting\ClusterSort::ALL_KEYS.
        // Default preserves the long-standing "biggest impact first" ordering.
        public readonly string $sort = 'impact:desc',
        // Tree-edit cost model: 'default' = unit costs (legacy), 'semantic'
        // weights method calls / control flow heavier than literals.
        public readonly string $tedWeights = 'default',
        /** @var list<string> Fully-qualified class names that implement
         *                    {@see \Phpdup\Normalization\NormalizationPlugin}.
         *                    Resolved at preprocess-stage time. */
        public readonly array $normalizationPlugins = [],
        // Per-directory overrides discovered by ConfigLoader from
        // `.phpdup.json` files placed inside the scanned tree. Keys are
        // **realpath()-resolved** absolute directory paths (no trailing
        // slash). Values are loose Config-shaped override dicts (the same
        // shape ConfigLoader::load accepts as $overrides).
        //
        // {@see effectiveFor()} merges all overrides whose directory key
        // is a prefix of the target path, deeper overrides winning over
        // shallower ones, so children inherit from their parents.
        /** @var array<string, array<string, mixed>> */
        public readonly array $perDirectoryOverrides = [],
        // ORM- / DB-aware semantic deduplication (option 1).
        // When true, the Normalizer runs DbOpCanonicalizer as a pre-pass
        // so recognised database calls across ORMs, query builders, and
        // raw SQL drivers fold to identical canonical tokens
        // (`__DB_FIND__`, `__DB_QUERY__`, `__DB_WRITE__`, …) and cluster
        // together. Off by default — enabling it is opt-in via
        // `--db-aware` or `db_aware: true` in phpdup.json.
        // See docs/plans/orm-db-semantic-dedup.md for the full plan.
        public readonly bool $dbAware = false,
        // Trinity-collapse (option 2): when true, the Normalizer runs
        // TrinityCollapser as a pre-pass, detecting the canonical
        // CRUD trinity (read → mutate → save) and rewriting the
        // three-statement sequence as a single `__DB_UPSERT__("entity")`
        // synthetic call so ORM upserts cluster with raw `UPDATE`
        // queries. Independent of `$dbAware` — the two flags compose;
        // typical usage enables both via `--db-aware --trinity-collapse`.
        public readonly bool $trinityCollapse = false,
        // User-extensible DB symbol equivalence registry (option 4).
        // Custom method-name → canonical-op map merged on top of the
        // stock {@see \Phpdup\Normalization\DbOpRegistry} entries.
        // Lower-cased keys, values are one of the `db.*` op constants
        // (`db.read`, `db.write`, `db.delete`, `db.execute`, `db.query`).
        // Loaded from `phpdup.json -> db_symbols.methods` plus any
        // symbol packs merged via `--profile`.
        /** @var array<string,string> */
        public readonly array $dbSymbolsMethods = [],
        // User-extensible DB symbol equivalence registry — function
        // name variant. Lower-cased keys, values as above.
        /** @var array<string,string> */
        public readonly array $dbSymbolsFunctions = [],
        // Scorer mode (option 5 of docs/plans/orm-db-semantic-dedup.md
        // — clusterer wiring). 'default' = stock AST-tier scoring;
        // 'ir' = IR-tier fallback enabled, lifting both blocks via
        // {@see \Phpdup\Ir\IrLifter} and producing a token-bag for
        // Jaccard scoring after the AST-level Jaccard has rejected.
        // Off by default; CLI: `--scorer=ir`.
        public readonly string $scorer = 'default',
        // IR-tier Jaccard threshold (option 5). Pairs whose IR token
        // multiset Jaccard meets-or-exceeds this score are emitted
        // as edges with the IR similarity as the edge weight, after
        // AST Jaccard + TED + containment have all rejected.
        public readonly float $irThreshold = 0.85,
        // Pair-similarity ML sidecar URL (option 6 of
        // docs/plans/orm-db-semantic-dedup.md — clusterer wiring).
        // Empty = disabled (the default). When set, the Clusterer
        // and PairScoreWorker fall through to MlPairClient::score()
        // as the *last* tier — after structural-hash, AST Jaccard +
        // TED, containment, and IR have all rejected. The client
        // returns null on transport failure so unavailability never
        // breaks the run; pairs at or above mlPairThreshold form
        // edges weighted by the model's similarity score. CLI:
        // --ml-pair-url.
        public readonly string $mlPairUrl = '',
        // Similarity threshold for the option-6 ML pair tier. Pairs
        // whose model-returned similarity meets-or-exceeds this
        // value emit edges weighted by that similarity. CLI:
        // --ml-pair-threshold.
        public readonly float $mlPairThreshold = 0.80,
        // Path to append debug (vvv) messages to. Null = disabled.
        public readonly ?string $debugLog = null,
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
        // Validate the sort spec eagerly so misconfigured CLI/config calls
        // fail at load time instead of inside the Ranker.
        \Phpdup\Reporting\ClusterSort::parse($sort);
        if (!in_array($tedWeights, \Phpdup\Similarity\EditCostModel::MODELS, true)) {
            throw new \InvalidArgumentException(
                "ted_weights must be one of " . implode('|', \Phpdup\Similarity\EditCostModel::MODELS)
            );
        }
        if (!in_array($scorer, ['default', 'ir'], true)) {
            throw new \InvalidArgumentException("scorer must be one of default|ir, got: $scorer");
        }
        if ($irThreshold < 0 || $irThreshold > 1) {
            throw new \InvalidArgumentException("ir_threshold out of range");
        }
        if ($mlPairThreshold < 0 || $mlPairThreshold > 1) {
            throw new \InvalidArgumentException("ml_pair_threshold out of range");
        }
        if ($mlPairUrl !== '' && !\Phpdup\Ml\MlClient::isAllowedUrl(rtrim($mlPairUrl, '/') . '/score-pair')) {
            throw new \InvalidArgumentException("ml_pair_url failed validation: $mlPairUrl");
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

    /**
     * Build the effective Config for a given file path by overlaying any
     * per-directory `.phpdup.json` overrides whose directory is an
     * ancestor of $filePath. Deeper overrides win over shallower ones.
     *
     * Falls back to $this when there are no per-directory overrides
     * (the common case), so callers can call this unconditionally and
     * pay no cost when the feature is unused.
     */
    public function effectiveFor(string $filePath): self
    {
        if ($this->perDirectoryOverrides === []) {
            return $this;
        }
        $real = realpath($filePath);
        $abs  = $real !== false ? $real : $filePath;

        // Pick all overrides whose key is a path prefix of $abs, then
        // merge them shortest-first so deeper keys overwrite shallower.
        $matches = [];
        foreach ($this->perDirectoryOverrides as $dir => $overrides) {
            if ($dir === '' || $abs === $dir || str_starts_with($abs, $dir . '/')) {
                $matches[$dir] = $overrides;
            }
        }
        if ($matches === []) {
            return $this;
        }
        uksort($matches, static fn(string $a, string $b) => strlen($a) <=> strlen($b));

        $merged = [];
        foreach ($matches as $overrides) {
            foreach ($overrides as $k => $v) {
                $merged[$k] = $v;
            }
        }
        return $this->withOverrides($merged);
    }

    /**
     * Build a new Config that copies $this but replaces only the keys
     * named in $overrides. Unknown keys are silently ignored — the
     * caller has already validated the shape via ConfigLoader::validate.
     *
     * @param array<string, mixed> $overrides
     */
    public function withOverrides(array $overrides): self
    {
        return new self(
            paths:                          $this->paths,
            exclude:                        $this->exclude,
            minBlockSize:                   isset($overrides['min_block_size'])      ? (int)$overrides['min_block_size']      : $this->minBlockSize,
            maxBlockSize:                   isset($overrides['max_block_size'])      ? (int)$overrides['max_block_size']      : $this->maxBlockSize,
            normalizationMode:              isset($overrides['normalization_mode'])  ? (string)$overrides['normalization_mode']: $this->normalizationMode,
            similarityThreshold:            isset($overrides['similarity_threshold'])? (float)$overrides['similarity_threshold']: $this->similarityThreshold,
            treeThreshold:                  isset($overrides['tree_threshold'])      ? (float)$overrides['tree_threshold']    : $this->treeThreshold,
            minClusterImpact:               isset($overrides['min_cluster_impact'])  ? (int)$overrides['min_cluster_impact']  : $this->minClusterImpact,
            maxDocumentFrequency:           isset($overrides['max_df'])              ? (float)$overrides['max_df']            : $this->maxDocumentFrequency,
            ngramSize:                      isset($overrides['ngram_size'])          ? (int)$overrides['ngram_size']          : $this->ngramSize,
            cacheDir:                       $this->cacheDir,
            parallelism:                    $this->parallelism,
            htmlReportDir:                  $this->htmlReportDir,
            jsonReportFile:                 $this->jsonReportFile,
            workers:                        $this->workers,
            incremental:                    $this->incremental,
            lazyAst:                        $this->lazyAst,
            allowedKinds:                   array_key_exists('allowed_kinds', $overrides) ? $overrides['allowed_kinds'] : $this->allowedKinds,
            optionalBlocksEnabled:          $this->optionalBlocksEnabled,
            optionalBlocksContainment:      $this->optionalBlocksContainment,
            optionalBlocksMinOverlap:       $this->optionalBlocksMinOverlap,
            optionalBlocksMaxPerCluster:    $this->optionalBlocksMaxPerCluster,
            optionalBlocksMinSegmentLength: $this->optionalBlocksMinSegmentLength,
            sort:                           $this->sort,
            tedWeights:                     $this->tedWeights,
            normalizationPlugins:           $this->normalizationPlugins,
            perDirectoryOverrides:          $this->perDirectoryOverrides,
            dbAware:                        isset($overrides['db_aware'])         ? (bool)$overrides['db_aware']         : $this->dbAware,
            trinityCollapse:                isset($overrides['trinity_collapse']) ? (bool)$overrides['trinity_collapse'] : $this->trinityCollapse,
            dbSymbolsMethods:               isset($overrides['db_symbols_methods'])
    ? array_merge($this->dbSymbolsMethods, $overrides['db_symbols_methods'])
    : $this->dbSymbolsMethods,
            dbSymbolsFunctions:             isset($overrides['db_symbols_functions'])
    ? array_merge($this->dbSymbolsFunctions, $overrides['db_symbols_functions'])
    : $this->dbSymbolsFunctions,
            scorer:                         isset($overrides['scorer']) ? (string)$overrides['scorer']     : $this->scorer,
            irThreshold:                    isset($overrides['ir_threshold']) ? (float)$overrides['ir_threshold'] : $this->irThreshold,
            mlPairUrl:                      isset($overrides['ml_pair_url']) ? (string)$overrides['ml_pair_url']  : $this->mlPairUrl,
            mlPairThreshold:                isset($overrides['ml_pair_threshold']) ? (float)$overrides['ml_pair_threshold'] : $this->mlPairThreshold,
        );
    }
}
