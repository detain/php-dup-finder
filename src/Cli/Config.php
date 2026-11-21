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
     * Accepted values of {@see self::$fingerprintIdMode} (item 1.1,
     * §5a). 'numbered' is the legacy default and must stay
     * byte-identical everywhere (bags, caches, goldens, CLI output);
     * 'un-numbered' is the drift-tolerant mode; 'realnames' is the
     * planned 1.1-follow-up third value (not accepted yet — adding it
     * without the Normalizer work behind it would silently alias to
     * 'un-numbered' semantics).
     */
    public const FINGERPRINT_ID_MODES = ['numbered', 'un-numbered'];

    public const FINGERPRINT_ID_NUMBERED = 'numbered';

    public const FINGERPRINT_ID_UNNUMBERED = 'un-numbered';
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
        // Item 1.1 (§5a): identifier-numbering mode for the similarity
        // fingerprints. 'numbered' = legacy behaviour (the aggressive
        // Normalizer's `__V3`/`__CALL12` first-occurrence counters ride
        // into the n-gram bag and the TED labels). 'un-numbered' strips
        // the counters in lockstep from both (NgramFingerprint +
        // AptedDistance via Util\IdentifierIds), making the fingerprint
        // tolerant of guard insertions that renumber every later
        // identifier. Structural hashes, anti-unification and ML pair
        // features deliberately KEEP numbered input. Root config only —
        // a per-directory .phpdup.json carrying this key is refused:
        // mixed modes across dirs would poison the shared bag/tier
        // comparisons and warm caches. 'realnames' (1.1-follow-up) will
        // add a third value. Config-file key `fingerprint_id_mode`;
        // no CLI flag in v1.
        public readonly string $fingerprintIdMode = 'numbered',
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
        // Low-memory mode: CompactNgramBag (32-bit fingerprint) + CanonicalNodePool
        // interning for lower RSS at the cost of some speed. When true, the
        // normalizer walks cloned canonical ASTs and interns leaf nodes so
        // identical subtrees are deduplicated across blocks, and the n-gram
        // fingerprinter switches from xxh64 (16-char hex strings) to a compact
        // int-keyed bag via CompactNgramBag::compact().
        public readonly bool $lowMemory = false,
        // CI gate: exit code 3 when total cluster impact exceeds this value.
        // 0 = disabled. CLI: --fail-on-impact.
        public readonly int $failOnImpact = 0,
        // CI gate: exit code 3 when cluster count exceeds this value.
        // 0 = disabled. CLI: --max-clusters.
        public readonly int $maxClusters = 0,
        // Baseline file path for CI incremental gating (--baseline FILE).
        // If file exists: load baseline and exit 4 if new clusters found.
        // If file doesn't exist: behave like --baseline-out (write baseline, exit 0).
        public readonly ?string $baselineFile = null,
        // Baseline output path (--baseline-out FILE).
        // Write current clusters as a baseline snapshot to FILE.
        // Overwrites if FILE exists.
        public readonly ?string $baselineOutFile = null,
        // Git ref for diff-scoped analysis (--diff-base=REF). When set,
        // ScanningStage filters the scanned file set to only the changed
        // files from `git diff --name-only <ref>..HEAD`, and ClusterStage
        // expands this to the "clone cohort" — all files sharing n-gram
        // fingerprints with the changed files. Null = full corpus scan.
        public readonly ?string $diffBase = null,
        // When true, write a cumulative apply.diff to the refactored/ directory
        // containing all cluster refactor patches concatenated. F1a produces
        // the same output as --patch; F1b will add actual file rewrite.
        public readonly bool $apply = false,
        // Safety cap on candidate pairs emitted by Clusterer::generateCandidatePairs()
        // (--candidate-budget N). 0 = unlimited — the fail-fast generation guard is
        // opted out entirely. Default mirrors Clusterer::MAX_CANDIDATE_PAIRS
        // (60,000,000); a pinned unit test keeps the two values equal.
        public readonly int $candidateBudget = 60_000_000,
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
        if (!in_array($fingerprintIdMode, self::FINGERPRINT_ID_MODES, true)) {
            throw new \InvalidArgumentException(
                "fingerprint_id_mode must be one of " . implode('|', self::FINGERPRINT_ID_MODES)
                . ", got: $fingerprintIdMode"
            );
        }
        if ($irThreshold < 0 || $irThreshold > 1) {
            throw new \InvalidArgumentException("ir_threshold out of range");
        }
        if ($mlPairThreshold < 0 || $mlPairThreshold > 1) {
            throw new \InvalidArgumentException("ml_pair_threshold out of range");
        }
        if ($candidateBudget < 0) {
            throw new \InvalidArgumentException("candidate_budget must be >= 0 (0 = unlimited pair generation)");
        }
        if ($mlPairUrl !== '' && !\Phpdup\Ml\MlClient::isAllowedUrl(rtrim($mlPairUrl, '/') . '/score-pair')) {
            throw new \InvalidArgumentException("ml_pair_url failed validation: $mlPairUrl");
        }
        if ($failOnImpact < 0) {
            throw new \InvalidArgumentException("fail_on_impact must be >= 0");
        }
        if ($maxClusters < 0) {
            throw new \InvalidArgumentException("max_clusters must be >= 0");
        }
    }

    /**
     * True when the similarity fingerprints (n-gram bag AND TED labels)
     * run un-numbered — the single conversion point from the string knob
     * to the boolean constructors of NgramFingerprint / TreeEditDistance.
     */
    public function fingerprintIdsUnnumbered(): bool
    {
        return $this->fingerprintIdMode === self::FINGERPRINT_ID_UNNUMBERED;
    }

    /** @param list<string> $paths */
    public static function defaults(array $paths): self
    {
        // 7.2(a): *.tpl.php is deliberately NOT excluded any more — PHP code
        // inside template-suffixed files is real duplicate surface and must
        // be scanned by default. Plain *.tpl (and every other non-PHP
        // extension) is still invisible until FileScanner::looksLikePhp()
        // is widened, which is an owner-gated decision (plan §6 #6).
        //
        // 5.2: the generic build/vendor-style dirs are promoted to `**`/x/**
        // (any depth) now that FileScanner's glob compiler is segment-safe.
        // The old root-anchored `vendor/**` missed nested per-package vendor
        // trees entirely (phlix measured 57,120 vs 1,727 files); the old
        // `**` → `.*` compiler had made a deep pattern unusable because it
        // over-matched `myvendor/`. With both halves fixed, deep is strictly
        // more correct. `"exclude_reset": true` is the documented opt-out.
        return new self(
            paths: $paths,
            exclude: [
                '**/vendor/**', '**/node_modules/**', '**/logs/**', '**/cache/**',
                '**/storage/**', '**/build/**', '**/dist/**',
                '**/.phpdup-cache/**', '**/phpdup-report/**',
            ],
        );
    }

    /**
     * Build the effective Config for a given file path by overlaying any
     * per-directory `.phpdup.json` overrides whose directory is an
     * ancestor of $filePath. Deeper overrides win over shallower ones —
     * except the list-valued `exclude`, which APPENDS along the chain
     * (item 5.2; `"exclude_reset": true` is the documented opt-out).
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
            $resetHere = (bool)($overrides['exclude_reset'] ?? false);
            if ($resetHere) {
                // Item 5.2: a layer that resets discards BOTH the root list
                // (sticky flag, consumed by mergeExclude below) and anything
                // its ancestors accumulated so far. A later `false` cannot
                // un-reset — the opt-out is one-way per chain.
                if (isset($merged['exclude']) && is_array($merged['exclude'])) {
                    $merged['exclude'] = [];
                }
                $merged['exclude_reset'] = true;
            }
            foreach ($overrides as $k => $v) {
                if ($k === 'exclude_reset') {
                    continue; // handled layer-by-layer above
                }
                if ($k === 'exclude' && is_array($v) && is_array($merged['exclude'] ?? null)) {
                    // `exclude` is the one list-valued key that ACCUMULATES
                    // downward instead of per-key-overwriting: a deeper
                    // .phpdup.json APPENDS to its ancestors' excludes (same
                    // append posture as db_symbols_* in withOverrides()).
                    $merged['exclude'] = array_merge($merged['exclude'], $v);
                } else {
                    $merged[$k] = $v;
                }
            }
        }
        return $this->withOverrides($merged);
    }

    /**
     * Build a new Config that copies $this but replaces only the keys
     * named in $overrides. Admission is not consumption: a key that
     * ConfigLoader::shapeOverrides() maps but this method does not
     * consume falls back to its root value. The emitted-vs-consumed delta
     * is pinned by tests/Unit/Cli/PerDirOverrideBeltTest.php; every
     * currently non-consumed emitted key sits in that test's BANKED
     * allowlist under owner-gate R8 (ft-r2 M-1). New admitted keys must be
     * consumed here or consciously banked there — never silently ignored.
     *
     * @param array<string, mixed> $overrides
     */
    public function withOverrides(array $overrides): self
    {
        return new self(
            paths:                          $this->paths,
            exclude:                        self::mergeExclude($this->exclude, $overrides),
            minBlockSize:                   isset($overrides['min_block_size'])      ? (int)$overrides['min_block_size']      : $this->minBlockSize,
            maxBlockSize:                   isset($overrides['max_block_size'])      ? (int)$overrides['max_block_size']      : $this->maxBlockSize,
            normalizationMode:              isset($overrides['normalization_mode'])  ? (string)$overrides['normalization_mode']: $this->normalizationMode,
            similarityThreshold:            isset($overrides['similarity_threshold'])? (float)$overrides['similarity_threshold']: $this->similarityThreshold,
            treeThreshold:                  isset($overrides['tree_threshold'])      ? (float)$overrides['tree_threshold']    : $this->treeThreshold,
            minClusterImpact:               isset($overrides['min_cluster_impact'])  ? (int)$overrides['min_cluster_impact']  : $this->minClusterImpact,
            maxDocumentFrequency:           isset($overrides['max_df'])              ? (float)$overrides['max_df']            : $this->maxDocumentFrequency,
            ngramSize:                      isset($overrides['ngram_size'])          ? (int)$overrides['ngram_size']          : $this->ngramSize,
            // Root-only by design (item 1.1): per-directory files that
            // carry fingerprint_id_mode are refused at discovery time, so
            // this knob never arrives through $overrides — it is copied,
            // never replaced. Mixed modes across dirs would poison the
            // shared bag/TED tier comparisons and warm caches.
            fingerprintIdMode:              $this->fingerprintIdMode,
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
            debugLog:                       isset($overrides['debug_log']) ? (string)$overrides['debug_log'] : $this->debugLog,
            lowMemory:                      isset($overrides['low_memory']) ? (bool)$overrides['low_memory'] : $this->lowMemory,
            failOnImpact:                   isset($overrides['fail_on_impact']) ? (int)$overrides['fail_on_impact'] : $this->failOnImpact,
            maxClusters:                    isset($overrides['max_clusters']) ? (int)$overrides['max_clusters'] : $this->maxClusters,
            baselineFile:                    isset($overrides['baseline']) ? (string)$overrides['baseline'] : $this->baselineFile,
            baselineOutFile:                 isset($overrides['baseline_out']) ? (string)$overrides['baseline_out'] : $this->baselineOutFile,
            diffBase:                        isset($overrides['diff_base']) ? (string)$overrides['diff_base'] : $this->diffBase,
            apply:                           isset($overrides['apply']) ? (bool)$overrides['apply'] : $this->apply,
            candidateBudget:                 isset($overrides['candidate_budget']) ? (int)$overrides['candidate_budget'] : $this->candidateBudget,
        );
    }

    /**
     * Resolve the `exclude` value of an override dict against an inherited
     * exclude list (item 5.2 — append-merge, never an accidental wipe).
     *
     * By default an explicit `exclude` list APPENDS to $inherited (deduped,
     * inherited order first) at every layer: root config file, CLI dict and
     * per-directory files alike. `"exclude_reset": true` is the documented
     * opt-out: the inherited list is discarded and only the explicit list
     * from this dict survives (empty explicit list ⇒ scan everything).
     *
     * @param list<string>                    $inherited
     * @param array<string, mixed>            $overrides
     * @return list<string>
     */
    private static function mergeExclude(array $inherited, array $overrides): array
    {
        $explicit = isset($overrides['exclude']) && is_array($overrides['exclude'])
            ? array_values(array_filter($overrides['exclude'], 'is_string'))
            : [];
        if ((bool)($overrides['exclude_reset'] ?? false)) {
            return $explicit;
        }
        return array_values(array_unique(array_merge($inherited, $explicit)));
    }
}
