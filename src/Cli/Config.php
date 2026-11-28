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
     * Accepted values of {@see self::$scoreMode} (item 5.1). 'global'
     * is the legacy default and must stay byte-identical everywhere
     * (goldens, stored baselines, gate history); 'per-repo' groups the
     * namespace-spread penalty by repo so cross-repo mirrors are
     * penalized at most once (the double-dip fix); 'typed' additionally
     * rewards cross-repo clusters (audit posture).
     */
    public const SCORE_MODES = ['global', 'per-repo', 'typed'];

    public const SCORE_MODE_GLOBAL = 'global';

    public const SCORE_MODE_PER_REPO = 'per-repo';

    public const SCORE_MODE_TYPED  = 'typed';
    /**
     * @param list<string> $paths
     * @param list<string> $exclude
     * @param list<string> $allowedKinds Empty list = accept all kinds.
     * @param list<string> $accepted Item 4.4: root-only phpdup.json accepted finding ids.
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
        // Trust-posture decision row (shell-S39, php-dup-finder-shell-052(b)):
        // the DEFAULT stays the in-tree `.phpdup-cache`. Moving it (e.g. under
        // a per-user XDG dir) would silently orphan every existing warm cache
        // and change what `--no-cache`/cleanup scripts see — a breaking change
        // no low-severity finding funds, so the posture is instead: keep the
        // location, make the PRIVACY absolute. The dir is created 0700 with a
        // post-mkdir chmod that defeats the umask and a verify that switches
        // the store off rather than replay from a forgeable tree
        // ({@see \Phpdup\Persistence\IndexStore::enforcePrivateCacheDir()}),
        // and every cache blob is written 0600. On a host where in-tree means
        // group-writable, point `cache_dir` at a private path — the config key
        // exists precisely for that decision.
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
        // DOCUMENTED INTENT: don't promote single-statement gaps below this
        // length (in raw stmts) to optional_block holes — too noisy on tiny
        // mismatches. CURRENT TRUTH (2026-11 doc wave): admitted, validated
        // (>= 1) and cache-key-bound, but NEVER READ — the AntiUnifier LCS
        // promotes unmatched template statements of any run length, so today
        // the knob changes nothing observable while still invalidating warm
        // caches. Inert until the Refactor-wave leg implements (or retires)
        // it (php-dup-finder-once-078 DA-06; sibling story of `parallelism`).
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
        // See docs/ML.md (sidecar tiers) and docs/adr/0003-similarity-stack.md
        // (normalization beneath the stack) for the wired surface; the
        // original ORM/DB-semantic-dedup plan document was deleted
        // from the tree in fd74e30, 2026-05 — the option numbers in
        // the docblocks below refer to that retired plan's list.
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
        // Loaded from `phpdup.json -> db_symbols.methods`, or from the
        // `--profile` tier's symbol packs when the file names no such
        // bucket (once-S27 intermediate-tier precedence).
        /** @var array<string,string> */
        public readonly array $dbSymbolsMethods = [],
        // User-extensible DB symbol equivalence registry — function
        // name variant. Lower-cased keys, values as above.
        /** @var array<string,string> */
        public readonly array $dbSymbolsFunctions = [],
        // Scorer mode (option 5 of the retired ORM-dedup plan — see the
        // dbAware docblock above for the plan's retirement note; clusterer
        // wiring). 'default' = stock AST-tier scoring;
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
        // Pair-similarity ML sidecar URL (option 6 of the retired plan
        // above — clusterer wiring per docs/algorithms/clustering.md).
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
        // Low-memory mode: CompactNgramBag + CanonicalNodePool
        // interning for lower RSS at the cost of some speed. When true, the
        // normalizer walks cloned canonical ASTs and interns leaf nodes so
        // identical subtrees are deduplicated across blocks, and the n-gram
        // fingerprinter switches from xxh64 (16-char hex strings) to a compact
        // int-keyed bag via CompactNgramBag::compact() — the key is a
        // masked-positive 63-bit int, (hi & 0x7FFFFFFF) << 32 | lo, over the
        // digest halves (engine-S18b era truth; the old "32-bit fingerprint"
        // wording here was wrong twice: width and construction).
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
        // containing all cluster refactor patches concatenated. The shipped
        // mode is F1b's: real rewrites of existing member call sites (each
        // rewritten to call the extracted abstraction, whose code also lands
        // under refactored/), inside a write-window transaction with read-back
        // verification — actual writes honour the dry-run gate (nothing
        // touches disk unless --dry-run=false; the preview apply.diff carries
        // the same text --patch emits).
        // shell-S27 (php-dup-finder-shell-015/053): the key is now fully
        // admitted end to end — ConfigLoader::validate() shape-checks it
        // (bool or the friendly exit 2), and the report door consumes it as
        // flag OR config: --apply sets overrides['apply']=true (a CLI
        // override, so it wins any disagreement), while "apply": true in the
        // root config file activates apply mode with no flag at all. Before
        // S27 a file value could raw-crash the ctor (no leg) and, when it
        // survived, nothing read it — the mode was flag-only.
        public readonly bool $apply = false,
        // Safety cap on candidate pairs emitted by Clusterer::generateCandidatePairs()
        // (--candidate-budget N). 0 = unlimited — the fail-fast generation guard is
        // opted out entirely. Default mirrors Clusterer::MAX_CANDIDATE_PAIRS
        // (60,000,000); a pinned unit test keeps the two values equal.
        public readonly int $candidateBudget = 60_000_000,
        // Item 0.5 (F3, improve_logic_info.md §3 "The 80 GB memory story"):
        // the Σmin multiset upper-bound pre-test inside candidate generation.
        // For ngram bags A,B the exact shared-mass sum Σ_v min(A(v),B(v))
        // upper-bounds the true multiset intersection, so a pair whose bound
        // (plus the df-cap skipped-mass slack) cannot reach the Jaccard or
        // containment threshold is skipped WITHOUT the intersection scoring
        // walk — measured 42-46x fewer pairs enumerated into the storm tier,
        // 0 of 640k true edges lost (§3's corpus census; losslessness re-
        // pinned in CandidateMassFilterTest against the storm fixture).
        // Default true; this config-only key is the escape hatch: an operator
        // sets "candidate_mass_filter": false to restore the pre-F3
        // enumeration byte-for-byte (max_ted_size precedent: deliberately NO
        // CLI flag — it is an engine-consistency stance, not a per-invocation
        // preference, and a per-directory file would vary it across one
        // shared candidate stream, so it is root-only, refused at discovery).
        public readonly bool $candidateMassFilter = true,
        // Item 5.1: reported-cluster scope filter — 'all' (default),
        // 'single' (one repo per cluster), 'multi' (cross-repo clusters),
        // or any exact repo label (basename of a scan root). Applied in
        // ReportStage between rank and report, so CI gates measure the
        // slice that is reported. Config-file key `scope`; CLI --scope.
        public readonly string $scope = 'all',
        // Item 5.1: scoring stance for cross-repo clones — see
        // {@see self::SCORE_MODES}. 'global' keeps every historical
        // score byte-identical; per-repo/typed only re-weight clusters
        // that carry ≥2 distinct member repo labels. Config-file key
        // `score_mode`; CLI --score-mode.
        public readonly string $scoreMode = self::SCORE_MODE_GLOBAL,
        // Item 1.2: node-count ceiling for APTED/TED judgment (config-file key
        // `max_ted_size`, JSON int; deliberately NO CLI flag). A candidate pair
        // whose LARGER member exceeds this cap skips the tree-edit pass in both
        // scoring engines and is accepted/rejected on jaccard/containment alone,
        // stamped tier `jaccard-unverified`. Default 800 equals the extraction
        // ceiling (max_block_size default) — no block can exceed it, so at the
        // default NOTHING new is compared and every historical score stays
        // byte-identical (inertness triangle pinned in TedSizeGateTest; the
        // engine-S10 cap-default review kept 800 and records why at
        // Clusterer::DEFAULT_MAX_TED_SIZE). Lowering it trades structural
        // certainty for wall time: the engine-010 family that motivated the
        // review (397 blocks near the cap, pairwise scoring > 400 s without
        // finishing) charges per verdict — the old full-grid DP cost measured
        // 161 s / 1.2 GB per pair pre-S09, ~2.3-6 s post-band+memo — and the
        // SUM over the family is now bounded aggregate-side by
        // Clusterer::DEFAULT_MAX_TED_COST on the serial engine (internal knob,
        // no config key by the plan's [OWNER] direction).
        public readonly int $maxTedSize = 800,
        // Items 1.2 + 0.6: oversized-block posture (config-file key
        // `window_oversized`, JSON bool; deliberately NO CLI flag — same
        // stance family as max_ted_size, root phpdup.json only). True (the
        // default) cuts SCAN WINDOWS out of blocks above max_block_size —
        // contiguous statement runs re-wrapped under the cap, minted with
        // the synthetic 'window' kind that every AST-addressing surface
        // refuses (WindowApplyRefusal) — so a 1500-statement function is no
        // longer invisible: its fragments fingerprint, pair, and cluster
        // like anything else. The default-ON posture is an owner-ratified
        // behavior change (owner exec order 2026-11-28, "do all remaining
        // items"): it CHANGES reports on corpora containing any oversized
        // declaration (new window species means new clusters). It is not
        // prescribed by improve_logic_info.md §2-1.2 — its §8.4 "1.2/1.3:
        // default-off" re-run line predates the window mechanism (it
        // described the max_ted_size-only v1 cure of §5 ruling (g)), so it
        // does not govern this default. False restores the historic
        // drop-everything posture. Corpora with nothing oversized are
        // byte-identical under either setting — the window path only fires
        // on nodes the ceiling was about to reject. The bound is
        // unconditional on the extraction path: the three-key law
        // (PreprocessWorker $toolFor, PreprocessStage::indexStoreConfigKey,
        // ClusterStage::cacheConfigKey) invalidates warm caches when the
        // posture flips. What stays invisible feeds the 0.6 counter at the
        // honest granularity: fully-dropped parents (unwindowable kinds,
        // windowing off, or zero surviving windows) count ONCE, statements
        // a SALVAGED parent's window packing silently gave up on (invalid
        // line-geometry, sub-floor remainders) count individually; the
        // disclosed lone-giant residual stays invisible to v1 windowing:
        // summary.blocks_oversized.
        public readonly bool $windowOversized = true,
        // Item 5.2's .gitignore leg (W2a): respect .gitignore files — the
        // scan root's and every nested one the descent reaches — while
        // walking. Root config + CLI flag only (`honor_gitignore` /
        // --honor-gitignore; refused per-directory, a whole-run stance):
        // the exclude-list channel keeps priority and the two never
        // override each other. Default OFF — the historical walk is
        // byte-identical until the operator asks; the flag is what "auto-
        // excluding vendored cruft" means for a repo that ships its own
        // ignore rules.
        public readonly bool $honorGitignore = false,
        // Item 5.4: coverage ceiling above which a cluster's signature is
        // marked `re_expressed` and a hole-free `hole_fragment` is surfaced
        // (config-file key `max_hole_coverage`, CLI --max-hole-coverage,
        // root-only — a threshold is a stance about the ONE final report).
        // Default 1.0 = the rule never fires: today's effective behavior,
        // every report byte-identical until the operator asks for it. The
        // old plan draft said ">40% holes → drop the cluster"; dropping
        // evidence silently is the failure mode this whole item exists to
        // kill, so exceeding the threshold marks-and-annotates instead.
        public readonly float $maxHoleCoverage = 1.0,
        // once-S54 (php-dup-finder-once-084): opt-in admitting ml_pair_url
        // sidecars in the PRIVATE address families (loopback, RFC 1918,
        // CGNAT, link-local, ULA) — the documented local-sidecar
        // topology. Default false keeps the full refused-space posture
        // ([OWNER] ruling: opt-in only). Root-only config key
        // `ml_pair_allow_private` (a subtree .phpdup.json is refused);
        // the argv flag --ml-pair-allow-private is NOT yet registered in
        // the Command.php option roster — no argv spelling exists at this
        // tip (Symfony refuses it as an unknown option) and lands with a
        // later option-roster leg, matching the schema description.
        // Reserved non-private spaces
        // (0.0.0.0/8, 192.0.0.0/24, 198.18.0.0/15, multicast) stay
        // refused either way; see MlPairClient::pairUrlAdmissionError().
        public readonly bool $mlPairAllowPrivate = false,
        // Item 3.1 ingestion (MINE-D): the idiom-census demand knob
        // (config-file key `idiom_mining`, JSON bool; deliberately NO CLI
        // flag — the Command.php option roster is another lane's live file,
        // same staging posture as ml_pair_allow_private's argv leg). True:
        // PreprocessStage arms the census — the worker mines every parsed
        // file's statement lists through SubtreeMiner (riding the SAME
        // one-walker capture as the 2.1 fragment leg, no second traversal,
        // §5c), the 'idiom' mined-row flavor ingests into the run's
        // SequenceIndex (armIdioms + recordIdiomFile), and the JSON report
        // gains the trailing conditional `summary.idiom_census` block.
        // Precondition, enforced HERE as a construction invariant: the
        // kinds roster must explicitly admit 'fragment' — statement-list
        // capture is fragment-gated in the extractor (the substrate's
        // documented precondition), and the EMPTY roster means "all the
        // default kinds", which excludes fragment by the 2.1 opt-in
        // posture. Arming without it would mine nothing while reporting a
        // quiet empty census — the exact silent-misfeed this family
        // refuses. Root config only (refused per-directory: one census per
        // run, never one per subtree) and bound into the IndexStore config
        // key: flipping the demand invalidates warm caches once — an armed
        // run must cold-reprocess every file, because cache-reused files
        // emit NO mined rows (the warm asymmetry law) and a partial census
        // served as complete would lie.
        public readonly bool $idiomMining = false,
        // Item 4.4 APPLY half: root-only phpdup.json "accepted" list of
        // stable finding ids the team decided to keep. Suppressed from
        // the main report into the baseline appendix (BaselineFilter);
        // never honored from per-directory configs (attacker-controlled
        // suppression was the exact hazard of the per-dir gate keys).
        public readonly array $accepted = [],
    ) {
        if (!in_array($normalizationMode, ['strict', 'default', 'aggressive'], true)) {
            throw new \InvalidArgumentException("Invalid normalization mode: $normalizationMode");
        }
        if (!is_finite($similarityThreshold) || $similarityThreshold < 0 || $similarityThreshold > 1) {
            throw new \InvalidArgumentException("similarity_threshold out of range (must be a finite number in [0, 1], got $similarityThreshold)");
        }
        if (!is_finite($treeThreshold) || $treeThreshold < 0 || $treeThreshold > 1) {
            throw new \InvalidArgumentException("tree_threshold out of range (must be a finite number in [0, 1], got $treeThreshold)");
        }
        if (!is_finite($optionalBlocksContainment) || $optionalBlocksContainment < 0 || $optionalBlocksContainment > 1) {
            throw new \InvalidArgumentException("optional_blocks_containment out of range (must be a finite number in [0, 1], got $optionalBlocksContainment)");
        }
        if (!is_finite($optionalBlocksMinOverlap) || $optionalBlocksMinOverlap < 0 || $optionalBlocksMinOverlap > 1) {
            throw new \InvalidArgumentException("optional_blocks_min_overlap out of range (must be a finite number in [0, 1], got $optionalBlocksMinOverlap)");
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
        if (!is_finite($irThreshold) || $irThreshold < 0 || $irThreshold > 1) {
            throw new \InvalidArgumentException("ir_threshold out of range (must be a finite number in [0, 1], got $irThreshold)");
        }
        if (!is_finite($mlPairThreshold) || $mlPairThreshold < 0 || $mlPairThreshold > 1) {
            throw new \InvalidArgumentException("ml_pair_threshold out of range (must be a finite number in [0, 1], got $mlPairThreshold)");
        }
        if ($candidateBudget < 0) {
            throw new \InvalidArgumentException("candidate_budget must be >= 0 (0 = unlimited pair generation)");
        }
        if (!in_array($scoreMode, self::SCORE_MODES, true)) {
            throw new \InvalidArgumentException(
                "score_mode must be one of " . implode('|', self::SCORE_MODES) . ", got: $scoreMode"
            );
        }
        if ($scope === '') {
            throw new \InvalidArgumentException("scope must be a non-empty string (use 'all' to disable filtering)");
        }
        if ($maxTedSize < 1) {
            throw new \InvalidArgumentException("max_ted_size must be >= 1 (0 or negative would gate every pair)");
        }
        if (!is_finite($maxHoleCoverage) || $maxHoleCoverage < 0 || $maxHoleCoverage > 1) {
            throw new \InvalidArgumentException("max_hole_coverage must be a finite number within 0..1 (got $maxHoleCoverage)");
        }
        if ($mlPairUrl !== '') {
            // once-S54: shape-only admission, zero resolver calls — the
            // old shared-gate check turned construction (and every CLI
            // validation of an attacker-supplied URL) into a blocking
            // DNS lookup. Refusals name the refused space + the opt-in.
            $mlPairRefusal = \Phpdup\Ml\MlPairClient::pairUrlAdmissionError(
                rtrim($mlPairUrl, '/') . '/score-pair',
                $mlPairAllowPrivate,
            );
            if ($mlPairRefusal !== null) {
                throw new \InvalidArgumentException("$mlPairRefusal (url: $mlPairUrl)");
            }
        }
        if ($idiomMining && !\in_array(\Phpdup\Extraction\BlockExtractor::FRAGMENT_KIND, $allowedKinds, true)) {
            // Item 3.1 substrate precondition, stated once HERE so every
            // route (CLI, config file, library, serve) fails the same way:
            // the miner enumerates the statement lists the extractor
            // captures, and that capture is gated on the explicit
            // 'fragment' kind (the empty all-default roster does not mine).
            // An armed census over a non-mining roster is a quiet zero,
            // and a quiet zero is exactly what the demand gate exists to
            // prevent.
            throw new \InvalidArgumentException(
                'idiom_mining requires "kinds" to explicitly include "fragment": the idiom miner rides the '
                . "fragment-gated statement-list capture, and the default (empty) kinds roster deliberately "
                . 'excludes fragment. Add "fragment" to kinds, or leave idiom_mining off.'
            );
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
     * shell-035 memo: derived Configs keyed by MATCHED-GROUP IDENTITY —
     * the length-sorted list of override keys effectiveFor() matched for
     * a path. Every file whose chain resolves to the same group reuses
     * one derived instance, so the 60-field withOverrides() rebuild runs
     * once per distinct group per process instead of once per file
     * (measured ~2ms/file on the real repo before this memo existed).
     *
     * Keyed by the group itself, not by directory, so the match set stays
     * a per-path computation exactly as before: even a degenerate
     * file-shaped override key (impossible from discovery, only from a
     * hand-built Config) gets its own group and can never bleed into its
     * directory siblings. Group strings join keys with NUL, which cannot
     * appear in a path key, so no two groups can alias.
     *
     * @var array<string, self>
     */
    private array $effectiveMemo = [];

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
     *
     * Memoized per matched group (shell-035): the hot callers (the
     * forked PreprocessWorker and BlockAstLoader::normalizerFor) ask per
     * file, and consecutive files overwhelmingly share one group — the
     * memo turns those repeats into an O(1) instance reuse with zero
     * behavior delta (derived Configs are pure functions of the group,
     * so a reused instance is value-identical to a rebuilt one).
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

        // shell-035: the sorted key list IS the group identity — PHP 8's
        // stable sort makes it deterministic per match set, so every file
        // in the group takes the identical cache slot.
        $groupKey = implode("\0", array_keys($matches));
        $memo = $this->effectiveMemo[$groupKey] ?? null;
        if ($memo !== null) {
            return $memo;
        }

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
        $derived = $this->withOverrides($merged);
        $this->effectiveMemo[$groupKey] = $derived;
        return $derived;
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
     * The converse direction is programmatic-only by design: shell-S22
     * (shell-026) dropped the path/ref-bearing family (baseline,
     * baseline_out, diff_base, debug_log) from the per-directory admit
     * list, so their isset() legs below can no longer be reached through
     * discovery — per-file derived configs never carry a subtree-chosen
     * write target. They survive for hand-built callers, joining the
     * consumed-but-not-admitted shape already occupied by db_aware,
     * scorer and apply.
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
            // once-S54: root-level opt-in; hand-built callers (pipeline /
            // serve) thread it here, per-directory routes never admit it.
            mlPairAllowPrivate:             isset($overrides['ml_pair_allow_private']) ? (bool)$overrides['ml_pair_allow_private'] : $this->mlPairAllowPrivate,
            // shell-S22 (shell-026): debug_log, baseline, baseline_out and
            // diff_base are ROOT-ONLY on the per-directory route — discovery
            // refuses them in any .phpdup.json and shapeOverrides() no longer
            // admits them, so a per-file derived Config can never receive a
            // subtree-chosen write target. The isset() legs below survive for
            // hand-built callers only (pipeline tests and the serve/watch
            // payload construct configs programmatically), the same
            // consumed-but-not-admitted shape as db_aware / scorer / apply.
            debugLog:                       isset($overrides['debug_log']) ? (string)$overrides['debug_log'] : $this->debugLog,
            lowMemory:                      isset($overrides['low_memory']) ? (bool)$overrides['low_memory'] : $this->lowMemory,
            failOnImpact:                   isset($overrides['fail_on_impact']) ? (int)$overrides['fail_on_impact'] : $this->failOnImpact,
            maxClusters:                    isset($overrides['max_clusters']) ? (int)$overrides['max_clusters'] : $this->maxClusters,
            baselineFile:                    isset($overrides['baseline']) ? (string)$overrides['baseline'] : $this->baselineFile,
            baselineOutFile:                 isset($overrides['baseline_out']) ? (string)$overrides['baseline_out'] : $this->baselineOutFile,
            diffBase:                        isset($overrides['diff_base']) ? (string)$overrides['diff_base'] : $this->diffBase,
            apply:                           isset($overrides['apply']) ? (bool)$overrides['apply'] : $this->apply,
            candidateBudget:                 isset($overrides['candidate_budget']) ? (int)$overrides['candidate_budget'] : $this->candidateBudget,
            // Item 5.1: report-level knobs, copied — never replaced —
            // per-directory. A scope filter or score stance is a fact
            // about the ONE final report; letting per-dir files vary it
            // would make one run answer to many scopes. Not admitted by
            // shapeOverrides() at all (same posture as cache_dir /
            // parallelism / workers), so a per-directory file carrying
            // these keys drops them silently at the shape step.
            scope:                           $this->scope,
            scoreMode:                       $this->scoreMode,
            // Item 1.2: root-only by design — per-directory files carrying
            // max_ted_size are refused at discovery (a pair spans two dirs, so
            // a per-dir cap is ambiguous — same silent-misfeed class as
            // fingerprint_id_mode). Copied, never replaced.
            maxTedSize:                      $this->maxTedSize,
            // Items 1.2 + 0.6: root-only, copied — never replaced — per-
            // directory (a per-dir window posture would mint two block
            // species from one cluster pool — same silent-misfeed class as
            // max_ted_size; refused at discovery in
            // ConfigLoader::discoverPerDirectoryOverrides()).
            windowOversized:                 $this->windowOversized,
            honorGitignore:                  $this->honorGitignore,
            // Item 5.4: root-only, copied — never replaced — per-directory
            // (same silent-misfeed class as max_ted_size; refused at
            // discovery in ConfigLoader::discoverPerDirectoryOverrides()).
            maxHoleCoverage:                 $this->maxHoleCoverage,
            // Item 0.5 (F3): root-only, copied — a candidate pair spans two
            // directories, so a per-directory filter stance is ambiguous
            // (same class as max_ted_size / candidate_budget; refused at
            // discovery). The one enumeration stream must carry one stance.
            candidateMassFilter:             $this->candidateMassFilter,
            // Item 3.1 ingestion: root-only, copied — never replaced — per-
            // directory (one census per run: a subtree flip would mint rows
            // for some files and silently skip others; refused at discovery
            // in ConfigLoader::discoverPerDirectoryOverrides()).
            idiomMining:                     $this->idiomMining,
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
