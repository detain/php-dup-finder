<?php
declare(strict_types=1);

namespace Phpdup\Cli;

final class ConfigLoader
{
    /**
     * shell-029 — hard ceiling for every config file read: the root
     * phpdup.json AND each auto-discovered per-directory .phpdup.json.
     * Config files are small declarative data; anything larger is an
     * accident or an attack (the FUZZ probe measured a 50 MB input at
     * 65.4 s / 438 MB RSS). Over-cap files are refused (root) or skipped
     * (discovery), and the READ is bounded, so oversized input never
     * enters memory whole.
     */
    private const MAX_CONFIG_FILE_BYTES = 1_048_576;

    /** @var list<string> skip notes recorded during the most recent load() */
    private array $skipNotes = [];

    /** @var list<string> admission notes recorded during the most recent load() */
    private array $loadNotes = [];

    /**
     * @param bool $warnOnSkip  emit each recorded skip note as a
     *                          "phpdup: warning: ..." line on STDERR, the
     *                          same contract FileScanner keeps. Notes are
     *                          collected in skipNotes() regardless, so
     *                          tests can assert them without STDERR
     *                          capture.
     */
    public function __construct(private readonly bool $warnOnSkip = true)
    {
    }

    /**
     * Paths-and-reasons of per-directory configs the last load() discovery
     * walk had to skip, in discovery order (shell-010). Unusable discovered
     * configs no longer abort the run, so this is how callers SEE them.
     *
     * @return list<string>
     */
    public function skipNotes(): array
    {
        return $this->skipNotes;
    }

    /**
     * Admission notes recorded during the most recent load() that are NOT
     * discovery skips (shell-038: the workers-ceiling clamp note is the
     * first member). A large but valid `workers` value stays admitted — the
     * pool clamps it — and this is where the user learns the clamp fired.
     * Kept separate from skipNotes() so the "clean run records nothing"
     * discovery contract is untouched. Mirrors the skip-note echo: always
     * collected; warnOnSkip also writes it to STDERR.
     *
     * @return list<string>
     */
    public function loadNotes(): array
    {
        return $this->loadNotes;
    }

    private function addLoadNote(string $note): void
    {
        $this->loadNotes[] = $note;
        if ($this->warnOnSkip) {
            fwrite(STDERR, "phpdup: warning: {$note}\n");
        }
    }

    /**
     * Record one discovery skip; mirrors FileScanner::addSkipNote()'s
     * contract: the note is always collected, warnOnSkip also echoes it.
     */
    private function addSkipNote(string $note): void
    {
        $this->skipNotes[] = $note;
        if ($this->warnOnSkip) {
            fwrite(STDERR, "phpdup: warning: {$note}\n");
        }
    }

    /**
     * @param list<string> $paths
     * @param array<string,mixed> $overrides
     * @param list<string>|null $profileExclude  Profile-supplied exclude globs.
     *        Under the item-5.2 append semantics these ADD to the stock
     *        defaults (alongside any config-file / overrides excludes);
     *        only "exclude_reset": true discards the inherited lists.
     */
    public function load(array $paths, ?string $configFile, array $overrides = [], ?array $profileExclude = null): Config
    {
        $this->skipNotes = [];
        $this->loadNotes = [];
        $base = Config::defaults($paths);
        $data = [];

        if ($configFile !== null) {
            if (!is_file($configFile)) {
                throw new \RuntimeException("Config file not found: $configFile");
            }
            // shell-029: bounded size cap + JSON_THROW_ON_ERROR so an
            // oversized, malformed or over-nested root config fails with the
            // real reason (e.g. "Maximum stack depth exceeded") instead of a
            // full unbounded decode or a bare "not valid JSON". Root-config
            // problems are user errors: they stay fatal, and the CLI layer
            // maps the RuntimeException to the documented exit 2.
            $payload = self::decodeConfigFile($configFile);
            if (is_string($payload)) {
                throw new \RuntimeException("Config file {$payload}: $configFile");
            }
            $this->validate($payload, $configFile);
            $data = $payload;
        }

        $flatKeys = [
            'min_block_size', 'max_block_size', 'normalization_mode',
            'similarity_threshold', 'tree_threshold', 'min_cluster_impact',
            'max_df', 'candidate_budget', 'ngram_size', 'cache_dir', 'parallelism',
            'workers', 'incremental', 'lazy_ast',
            'sort', 'ted_weights', 'scorer',
            'fingerprint_id_mode',
            'scope', 'score_mode',
            'max_ted_size',
            'max_hole_coverage',
            'ir_threshold', 'ml_pair_threshold', 'debug_log',
            'low_memory', 'fail_on_impact', 'max_clusters',
            'optional_blocks_containment',
            'baseline', 'baseline_out', 'diff_base', 'apply',
        ];
        $resolver = new OverrideResolver($flatKeys);
        // shell-S24 (php-dup-finder-shell-014): optional_blocks.containment
        // joins the coerced numeric family. The config-file layer spells it
        // nested, the CLI flag and per-dir shaping flatten it to the snake
        // key, and validate() has long pinned the nested file leg — but the
        // raw CLI string used to reach Config through a blind (float) cast,
        // so 'hot' silently became 0.0 and the run exited 0. Hoisting the
        // nested section and aliasing it into the data layer puts every
        // source through the ONE coercion boundary, precedence unchanged
        // (CLI override > config file > defaults).
        $optBlock = is_array($data['optional_blocks'] ?? null) ? $data['optional_blocks'] : [];
        $flatData = $data;
        if (array_key_exists('containment', $optBlock)) {
            $flatData['optional_blocks_containment'] = $optBlock['containment'];
        }
        $flat = $this->coerceNumerics($resolver->resolve($overrides, $flatData, [
            'min_block_size' => $base->minBlockSize,
            'max_block_size' => $base->maxBlockSize,
            'normalization_mode' => $base->normalizationMode,
            'similarity_threshold' => $base->similarityThreshold,
            'tree_threshold' => $base->treeThreshold,
            'min_cluster_impact' => $base->minClusterImpact,
            'max_df' => $base->maxDocumentFrequency,
            'candidate_budget' => $base->candidateBudget,
            'ngram_size' => $base->ngramSize,
            'cache_dir' => $base->cacheDir,
            'parallelism' => $base->parallelism,
            'workers' => $base->workers,
            'incremental' => $base->incremental,
            'lazy_ast' => $base->lazyAst,
            'sort' => $base->sort,
            'ted_weights' => $base->tedWeights,
            'scorer' => $base->scorer,
            'fingerprint_id_mode' => $base->fingerprintIdMode,
            'scope' => $base->scope,
            'score_mode' => $base->scoreMode,
            'max_ted_size' => $base->maxTedSize,
            'max_hole_coverage' => $base->maxHoleCoverage,
            'ir_threshold' => $base->irThreshold,
            'ml_pair_threshold' => $base->mlPairThreshold,
            'debug_log' => $base->debugLog,
            'low_memory' => $base->lowMemory,
            'fail_on_impact' => $base->failOnImpact,
            'max_clusters' => $base->maxClusters,
            'optional_blocks_containment' => $base->optionalBlocksContainment,
            'baseline' => $base->baselineFile,
            'baseline_out' => $base->baselineOutFile,
            'diff_base' => $base->diffBase,
            'apply' => $base->apply,
        ]));

        $report = is_array($data['report'] ?? null) ? $data['report'] : [];
        $htmlOverride = $overrides['html'] ?? ($report['html'] ?? null);
        $jsonOverride = $overrides['json'] ?? ($report['json'] ?? null);

        // shell-038 / shell-S10 lane ruling (pinned in NumericFlagCoercionTest):
        // an above-ceiling `workers` value is NOT invalid input — it stays
        // admitted (exit 0 posture, ConfigLoaderTest's exit-2 pins cover only
        // genuinely invalid values). The enforcement is the pool's clamp
        // (WorkerPool::clampWorkers, min(requested, 4 × CPUs)), and the
        // user-visible counterpart of that silent clamp is this note:
        // collected in loadNotes() and echoed to STDERR under warnOnSkip,
        // exactly like the discovery skip notes.
        $workersRequested = (int)($flat['workers'] ?? 0);
        $workersCeiling   = \Phpdup\Parallel\WorkerPool::workersCeiling();
        if ($workersRequested > $workersCeiling) {
            $this->addLoadNote(sprintf(
                'workers=%d exceeds the parallel ceiling of %d (%d x %d detected CPUs); '
                . 'the worker pool clamps every run to %d concurrent children.',
                $workersRequested,
                $workersCeiling,
                \Phpdup\Parallel\WorkerPool::WORKERS_CEILING_MULTIPLIER,
                \Phpdup\Parallel\WorkerPool::detectCpuCount(),
                $workersCeiling,
            ));
        }

        $allowedKinds = $base->allowedKinds;
        if (array_key_exists('allowed_kinds', $overrides)) {
            $allowedKinds = $overrides['allowed_kinds'];
        } elseif (array_key_exists('kinds', $data)) {
            $allowedKinds = $data['kinds'];
        }

        $resolvedPaths = !empty($data['paths']) ? $data['paths'] : $base->paths;
        // Item 5.2 — exclude resolution is APPEND, never wipe: profile,
        // config-file and programmatic (CLI-dict) lists stack on top of the
        // stock defaults, in precedence order, deduped. `"exclude_reset":
        // true` in the config file (or the overrides dict) is the documented
        // opt-out: every INHERITED layer below the resetting one (defaults
        // AND profile) is dropped, only the config-file/dict lists survive.
        // An explicit exclude from ANY source now actually lands (the old
        // chain silently dropped $overrides['exclude'] entirely and let one
        // list replace the rest).
        $configLayer = is_array($data['exclude'] ?? null) ? $data['exclude'] : [];
        $dictLayer = isset($overrides['exclude']) && is_array($overrides['exclude']) ? $overrides['exclude'] : [];
        $excludeReset = (bool)($data['exclude_reset'] ?? false) || (bool)($overrides['exclude_reset'] ?? false);
        $resolvedExclude = array_values(array_unique($excludeReset
            ? array_merge($configLayer, $dictLayer)
            : array_merge($base->exclude, $profileExclude ?? [], $configLayer, $dictLayer)));

        return new Config(
            paths: $resolvedPaths,
            exclude: $resolvedExclude,
            minBlockSize:                   $flat['min_block_size'],
            maxBlockSize:                   $flat['max_block_size'],
            normalizationMode:              $flat['normalization_mode'],
            similarityThreshold:            $flat['similarity_threshold'],
            treeThreshold:                  $flat['tree_threshold'],
            minClusterImpact:               $flat['min_cluster_impact'],
            maxDocumentFrequency:           $flat['max_df'],
            ngramSize:                      $flat['ngram_size'],
            fingerprintIdMode:              $flat['fingerprint_id_mode'],
            cacheDir:                       $flat['cache_dir'],
            parallelism:                    $flat['parallelism'],
            htmlReportDir: $htmlOverride !== null ? (string)$htmlOverride : null,
            jsonReportFile: $jsonOverride !== null ? (string)$jsonOverride : null,
            workers:                        $flat['workers'],
            incremental:                    $flat['incremental'],
            lazyAst:                        $flat['lazy_ast'],
            allowedKinds: $allowedKinds,
            optionalBlocksEnabled:        (bool)($overrides['optional_blocks_enabled'] ?? $optBlock['enabled'] ?? $base->optionalBlocksEnabled),
            optionalBlocksContainment:    $flat['optional_blocks_containment'],
            optionalBlocksMinOverlap:     (float)($overrides['optional_blocks_min_overlap'] ?? $optBlock['min_overlap'] ?? $base->optionalBlocksMinOverlap),
            optionalBlocksMaxPerCluster:  (int)($overrides['optional_blocks_max_per_cluster'] ?? $optBlock['max_per_cluster'] ?? $base->optionalBlocksMaxPerCluster),
            optionalBlocksMinSegmentLength: (int)($overrides['optional_blocks_min_segment_length'] ?? $optBlock['min_segment_length'] ?? $base->optionalBlocksMinSegmentLength),
            sort:                          $flat['sort'],
            tedWeights:                    $flat['ted_weights'],
            normalizationPlugins: $this->extractNormalizationPlugins($data),
            perDirectoryOverrides: $this->discoverPerDirectoryOverrides($resolvedPaths, $resolvedExclude),
            dbAware: (bool)($overrides['db_aware'] ?? $data['db_aware'] ?? $base->dbAware),
            trinityCollapse: (bool)($overrides['trinity_collapse'] ?? $data['trinity_collapse'] ?? $base->trinityCollapse),
            dbSymbolsMethods: $this->extractDbSymbols($data, $overrides, 'methods'),
            dbSymbolsFunctions: $this->extractDbSymbols($data, $overrides, 'functions'),
            scorer:                         $flat['scorer'],
            irThreshold:                    $flat['ir_threshold'],
            mlPairUrl: (string)($overrides['ml_pair_url'] ?? $data['ml_pair_url'] ?? $base->mlPairUrl),
            mlPairAllowPrivate: (bool)($overrides['ml_pair_allow_private'] ?? $data['ml_pair_allow_private'] ?? $base->mlPairAllowPrivate),
            mlPairThreshold:               $flat['ml_pair_threshold'],
            debugLog:                      $flat['debug_log'],
            lowMemory:                     $flat['low_memory'],
            failOnImpact:                  $flat['fail_on_impact'],
            maxClusters:                   $flat['max_clusters'],
            baselineFile:                  $flat['baseline'],
            baselineOutFile:               $flat['baseline_out'],
            diffBase:                      $flat['diff_base'],
            apply:                         $flat['apply'],
            candidateBudget:               $flat['candidate_budget'],
            scope:                         $flat['scope'],
            scoreMode:                     $flat['score_mode'],
            maxTedSize:                    $flat['max_ted_size'],
            maxHoleCoverage:               $flat['max_hole_coverage'],
        );
    }

    /**
     * Numeric flat keys and their valid domain, mirroring
     * {@see validate()} / docs/config-schema.json: key => [kind, min, max|null].
     *
     * Symfony Console hands every VALUE_REQUIRED option to us as a raw
     * string, while {@see Config} is strict-typed with int/float params;
     * without coercion `--min-block-size 10` dies with a TypeError. This
     * table is the single boundary that parses CLI override strings back
     * into their declared types before Config is constructed.
     */
    private const NUMERIC_KEYS = [
        'min_block_size'       => ['int',   1,    null],
        'max_block_size'       => ['int',   1,    null],
        'ngram_size'           => ['int',   2,    10],
        'min_cluster_impact'   => ['int',   0,    null],
        'workers'              => ['int',   0,    null],
        'fail_on_impact'       => ['int',   0,    null],
        'max_clusters'         => ['int',   0,    null],
        'candidate_budget'     => ['int',   0,    null],
        'max_ted_size'         => ['int',   1,    null],
        'max_hole_coverage'    => ['float', 0.0,  1.0],
        'similarity_threshold' => ['float', 0.0,  1.0],
        'tree_threshold'       => ['float', 0.0,  1.0],
        'max_df'               => ['float', 0.0,  1.0],
        'ir_threshold'         => ['float', 0.0,  1.0],
        'ml_pair_threshold'    => ['float', 0.0,  1.0],
        // shell-S24 (shell-014): the type-3 containment knob rides the same
        // [0,1] domain validate() pins for optional_blocks.containment.
        'optional_blocks_containment' => ['float', 0.0, 1.0],
    ];

    /**
     * shell-S24 (php-dup-finder-shell-013/014): the coercion boundary for
     * numeric CLI flags that never enter Config — `--limit`, `--min-safety`
     * and `--max-memory` are render-only presentation settings (they stay
     * out of the override dict; see CliDefaultShadowingTest), so they never
     * travel through load()/OverrideResolver. Their raw Symfony strings
     * still must pass ONE parsing discipline, and that discipline lives
     * here: these helpers delegate to the exact private coercers the
     * NUMERIC_KEYS loop uses — same trim policy, same strict numeric-string
     * rule, same int64-overflow guard, same key-named
     * {@see \InvalidArgumentException}, which the CLI layer renders as the
     * conventional friendly exit 2.
     */
    public static function coerceRuntimeInt(string $key, mixed $value, int|float $min, int|float|null $max = null): int
    {
        return self::coerceInt($key, $value, $min, $max);
    }

    public static function coerceRuntimeFloat(string $key, mixed $value, int|float $min, int|float|null $max): float
    {
        return self::coerceFloat($key, $value, $min, $max);
    }

    /**
     * Parse the resolved numeric flat keys into real int/float values.
     *
     * Passes native int/float values through untouched (JSON configs and
     * profiles already decode to native types), casts strictly-typed
     * numeric strings, and rejects anything else with an
     * {@see \InvalidArgumentException} that the CLI layer turns into the
     * conventional friendly exit 2. Range validation in Config's
     * constructor remains the last-resort backstop.
     *
     * @param array<string,mixed> $flat
     * @return array<string,mixed>
     */
    private function coerceNumerics(array $flat): array
    {
        foreach (self::NUMERIC_KEYS as $key => $domain) {
            if (!array_key_exists($key, $flat)) {
                continue;
            }
            $value = $flat[$key];
            if ($value === null) {
                continue; // nullable handling stays with the Config defaults
            }
            [$kind, $min, $max] = $domain;
            $flat[$key] = $kind === 'int'
                ? self::coerceInt($key, $value, $min, $max)
                : self::coerceFloat($key, $value, $min, $max);
        }
        return $flat;
    }

    private static function coerceInt(string $key, mixed $value, int|float $min, int|float|null $max): int
    {
        if (is_int($value)) {
            $parsed = $value;
        } elseif (is_string($value) && preg_match('/^[+-]?\d+$/', trim($value)) === 1) {
            $text = trim($value);
            if (self::textBeyondInt64($text)) {
                // ft-n1: the regex proves shape, not magnitude. (int) on a
                // 20-nine string saturates silently to PHP_INT_MAX, which
                // guards like the candidate-budget read as "effectively
                // unlimited" — the knob disarms itself. Anything outside
                // the signed 64-bit range fails loud here instead, and the
                // CLI layer turns it into the conventional friendly exit 2.
                throw new \InvalidArgumentException(
                    sprintf('%s must fit a signed 64-bit integer, got %s', $key, self::describeValue($value))
                );
            }
            $parsed = (int)$text;
        } else {
            throw new \InvalidArgumentException(
                sprintf('%s must be an integer, got %s', $key, self::describeValue($value))
            );
        }
        self::assertDomain($key, $parsed, $min, $max);
        return $parsed;
    }

    /**
     * Whether a signed decimal string (regex shape already proven: optional
     * single sign, one or more digits) lies outside the (int) range. The
     * magnitude compare is string-vs-string because the number, if out of
     * range, no longer exists inside a PHP scalar. Exact boundaries stay
     * in range: PHP_INT_MAX, and the 9223372036854775808 that PHP_INT_MIN
     * owns.
     */
    private static function textBeyondInt64(string $text): bool
    {
        $negative = $text[0] === '-';
        $digits = $text[0] === '-' || $text[0] === '+' ? substr($text, 1) : $text;
        $digits = ltrim($digits, '0');
        $limit = $negative ? '9223372036854775808' : (string)PHP_INT_MAX;
        if (strlen($digits) !== strlen($limit)) {
            return strlen($digits) > strlen($limit);
        }
        return strcmp($digits, $limit) > 0;
    }

    private static function coerceFloat(string $key, mixed $value, int|float $min, int|float|null $max): float
    {
        if (is_int($value) || is_float($value)) {
            $parsed = (float)$value;
        } elseif (is_string($value) && is_numeric(trim($value))) {
            $parsed = (float)trim($value);
        } else {
            throw new \InvalidArgumentException(
                sprintf('%s must be a number, got %s', $key, self::describeValue($value))
            );
        }
        self::assertDomain($key, $parsed, $min, $max);
        return $parsed;
    }

    /**
     * Every float key in NUMERIC_KEYS spans the unit interval, so its
     * violation message mirrors validate()'s "must be in [0, 1]" wording;
     * int keys report the single bound they broke.
     */
    private static function assertDomain(string $key, int|float $value, int|float $min, int|float|null $max): void
    {
        $violated = $value < $min || ($max !== null && $value > $max);
        if (!$violated) {
            return;
        }
        if ($max !== null && (is_float($min) || is_float($value))) {
            throw new \InvalidArgumentException(sprintf('%s must be in [%s, %s]', $key, $min, $max));
        }
        if ($value < $min) {
            throw new \InvalidArgumentException(sprintf('%s must be >= %d', $key, $min));
        }
        throw new \InvalidArgumentException(sprintf('%s must be <= %d', $key, $max));
    }

    private static function describeValue(mixed $value): string
    {
        if (is_string($value)) {
            $shown = strlen($value) > 40 ? substr($value, 0, 40) . '…' : $value;
            return "'$shown'";
        }
        return get_debug_type($value);
    }

    /**
     * Extract a DB-symbol equivalence map (option 4 of
     * docs/plans/orm-db-semantic-dedup.md) from a parsed config and
     * the override dict, lower-casing keys and discarding entries
     * whose op-tag is not one of the recognised `db.*` constants.
     *
     * The override path accepts a single composite key
     * (`db_symbols_methods` / `db_symbols_functions`) so callers
     * built from CLI flags or programmatic config can flatten the
     * nested shape.
     *
     * @param array<string,mixed> $data
     * @param array<string,mixed> $overrides
     * @return array<string,string>
     */
    private function extractDbSymbols(array $data, array $overrides, string $bucket): array
    {
        $direct = $overrides['db_symbols_' . $bucket] ?? null;
        if (!is_array($direct)) {
            $section = $data['db_symbols'] ?? null;
            $direct  = is_array($section) && is_array($section[$bucket] ?? null) ? $section[$bucket] : [];
        }
        $allowed = [
            \Phpdup\Normalization\DbOpRegistry::OP_READ,
            \Phpdup\Normalization\DbOpRegistry::OP_WRITE,
            \Phpdup\Normalization\DbOpRegistry::OP_DELETE,
            \Phpdup\Normalization\DbOpRegistry::OP_EXECUTE,
            \Phpdup\Normalization\DbOpRegistry::OP_QUERY,
        ];
        $out = [];
        foreach ($direct as $name => $op) {
            if (!is_string($name) || $name === '' || !is_string($op)) {
                continue;
            }
            if (!in_array($op, $allowed, true)) {
                continue;
            }
            $out[strtolower($name)] = $op;
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $data
     * @return list<string>
     */
    private function extractNormalizationPlugins(array $data): array
    {
        $norm = $data['normalization'] ?? null;
        if (!is_array($norm)) return [];
        $list = $norm['plugins'] ?? null;
        if (!is_array($list)) return [];
        $out = [];
        foreach ($list as $entry) {
            if (is_string($entry) && $entry !== '') {
                $out[] = $entry;
            }
        }
        return $out;
    }

    /**
     * Walk each scan path looking for `.phpdup.json` files and return a map
     * of {realpath(dir) → override-dict}. The dicts are validated via
     * {@see validate()} so misconfigured per-directory files fail loudly
     * at load time rather than mid-pipeline.
     *
     * shell-010 — two walk fixes:
     *  - EXCLUDES ARE HONOURED. Every entry is tested against the SAME
     *    resolved exclude list (hence the same segment-safe glob dialect:
     *    the decision goes through FileScanner::isExcluded(), not a second
     *    compiler) that the scanner will later apply, so ConfigLoader never
     *    reads files or directories the scan will refuse. Excluded trees are
     *    pruned BEFORE descent: a stock run no longer costs 5.8× a whole
     *    FileScanner pass just to discover (452.7 ms vs 77.5 ms on a
     *    25.5k-entry tree), and a third-party package that ships its own
     *    stale or broken `.phpdup.json` inside vendor/ can no longer poison
     *    the user's run.
     *  - UNUSABLE FILES DEGRADE, THEY DO NOT ABORT. A discovered config that
     *    cannot be consumed (oversized, unreadable, broken JSON, not a JSON
     *    object) is skipped with a note (skipNotes() + STDERR) and the run
     *    continues — the same environment-skip policy the walk already
     *    keeps for unreadable directories. This is a deliberate, disclosed
     *    user-visible change: such a file inside an INCLUDED directory used
     *    to throw and exit 2. Valid-but-misconfigured files (unknown keys,
     *    out-of-range values, the root-only stance-key refusals below) keep
     *    throwing: once excluded trees are never read, failing loudly on a
     *    subtree the user DID ask to scan carries no false-positive risk,
     *    and shell-S22's root-only posture pins the throw. Root-only now
     *    covers two families: report-level stance keys (refused above) and
     *    the path/ref-bearing write-target keys baseline/baseline_out/
     *    diff_base/debug_log (shell-026), likewise refused here and absent
     *    from the per-dir admit list in shapeOverrides().
     *
     * Found files override only the subtree rooted at their directory;
     * the actual layered merge happens in {@see Config::effectiveFor()}.
     * once-S28 (php-dup-finder-once-032): a found file's `exclude` and
     * `exclude_reset` are now ALSO applied at scan time — ScanningStage
     * feeds the map to {@see \Phpdup\Scanning\FileScanner} as a
     * subtree-scoped overlay, so an excluded nested subtree never enters
     * the scan census and a resetting subtree re-roots. Discovery itself
     * stays governed by the resolved ROOT exclude list only: a config
     * inside a root-excluded tree is never read, noted, or able to
     * resurrect the pruned tree from within it.
     *
     * @param list<string> $paths
     * @param list<string> $excludeGlobs  the exact resolved list Config hands
     *        to FileScanner (append-merge semantics already applied), so the
     *        two walks prune identically
     * @return array<string, array<string, mixed>>
     */
    private function discoverPerDirectoryOverrides(array $paths, array $excludeGlobs): array
    {
        $out = [];
        // One shared compiled exclude gate for every root. warnOnSkip: false —
        // this instance only answers isExcluded(); every note below comes from
        // ConfigLoader's own recorder.
        $gate = new \Phpdup\Scanning\FileScanner($excludeGlobs, warnOnSkip: false);
        foreach ($paths as $root) {
            // Same yardstick as FileScanner::scan(): entries are matched
            // relative to a '/'-trimmed root (a lone "/" root kept whole).
            $root = strlen($root) > 1 ? rtrim($root, '/') : $root;
            if (!is_dir($root)) {
                continue;
            }
            try {
                $dirIt = new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS);
            } catch (\UnexpectedValueException $e) {
                // Root exists but cannot be opened (permissions, mid-walk race):
                // skip this path. An escaping SPL UnexpectedValueException (a
                // RuntimeException) would otherwise be misclassified by the CLI
                // layer as invalid config and re-badged as exit 2.
                continue;
            }
            // Env robustness: real-world scan trees legitimately contain
            // subtrees this user cannot read (other users' dirs, chmod-000
            // seats). Descent is limited to readable directories so an
            // unreadable branch is SKIPPED, never fatal.
            $filter = new \RecursiveCallbackFilterIterator(
                $dirIt,
                static function (\RecursiveDirectoryIterator|\SplFileInfo|string $current) use ($gate, $root): bool {
                    // Psalm models every value the inner iterator may hand a
                    // RecursiveCallbackFilterIterator callback (entry, child
                    // iterator, string key); only SplFileInfo carries
                    // permissions, everything else passes through untouched.
                    if (!$current instanceof \SplFileInfo) {
                        return true;
                    }
                    // shell-010: prune anything the scanner would exclude.
                    // A pruned directory is never descended, so configs
                    // inside it are never read, never validated, never noted.
                    if (self::isExcludedUnder($gate, $root, $current)) {
                        return false;
                    }
                    return $current->isDir() ? $current->isReadable() : true;
                }
            );
            $iter = new \RecursiveIteratorIterator($filter);
            try {
                foreach ($iter as $file) {
                    if (!$file instanceof \SplFileInfo) continue;
                    if ($file->getBasename() !== '.phpdup.json') continue;
                    if (!$file->isFile()) continue;

                    // shell-010 + shell-029: an unusable discovered config —
                    // oversized, unreadable (the n-R2-2 race case; the reason
                    // is now stated instead of swallowed), broken JSON, or a
                    // non-object — skips with a note and the run continues.
                    $payload = self::decodeConfigFile($file->getPathname());
                    if (is_string($payload)) {
                        $this->addSkipNote(
                            'skipped unusable per-directory config ' . $file->getPathname() . " ($payload)"
                        );
                        continue;
                    }
                    // Re-use the same validator so per-directory files can't
                    // smuggle in misspelled keys or out-of-range values.
                    $this->validate($payload, $file->getPathname());

                    // Item 1.1 (§5a): fingerprint_id_mode is ROOT-ONLY, and
                    // the key is deliberately absent from shapeOverrides().
                    // Refused here (not silently dropped) because per-dir
                    // mode mixing would mint two incompatible bag species
                    // for one shared candidate index / TED gate comparison
                    // and one warm cache — the exact silent-misfeed class
                    // this knob's cache-key packet exists to prevent.
                    if (array_key_exists('fingerprint_id_mode', $payload)) {
                        throw new \RuntimeException(
                            "fingerprint_id_mode is not a per-directory key (root phpdup.json only): "
                            . $file->getPathname()
                        );
                    }

                    // Item 5.1 (review m-1): scope and score_mode are
                    // report-level stances chosen once per run — like
                    // fingerprint_id_mode, they are deliberately absent from
                    // shapeOverrides() and refused here instead of silently
                    // dropped. A misplaced per-directory "scope" would
                    // otherwise pass validate(), evaporate at shaping, and
                    // leave the user believing the subtree was scoped or
                    // re-scored when it never was.
                    foreach (['scope', 'score_mode'] as $stanceKey) {
                        if (array_key_exists($stanceKey, $payload)) {
                            throw new \RuntimeException(
                                $stanceKey . ' is not a per-directory key (root phpdup.json only): '
                                . $file->getPathname()
                            );
                        }
                    }

                    // Item 1.2: max_ted_size joins the root-only stance family
                    // (same posture as fingerprint_id_mode — absent from
                    // shapeOverrides(), refused loudly here rather than
                    // silently dropped at the shape step). A candidate pair
                    // spans TWO blocks that may live in different directories,
                    // so a per-directory TED cap is ambiguous by construction:
                    // whichever dir's value won, the other dir's blocks would
                    // be judged under a cap its author never chose — the exact
                    // silent-misfeed class these refusals exist to prevent.
                    if (array_key_exists('max_ted_size', $payload)) {
                        throw new \RuntimeException(
                            'max_ted_size is not a per-directory key (root phpdup.json only): '
                            . $file->getPathname()
                        );
                    }

                    // Item 5.4: max_hole_coverage rides the same root-only
                    // posture — a cluster spans members from many dirs, and
                    // the re-expression threshold is a stance about the ONE
                    // final report, not about a subtree.
                    if (array_key_exists('max_hole_coverage', $payload)) {
                        throw new \RuntimeException(
                            'max_hole_coverage is not a per-directory key (root phpdup.json only): '
                            . $file->getPathname()
                        );
                    }

                    // once-S54: the private-range SSRF opt-in is ROOT-
                    // ONLY. Discovery lets any directory plant a file,
                    // so a subtree flip of ml_pair_allow_private would
                    // silently relax the posture of a URL the operator
                    // (or the root config) chose. Refused loudly, same
                    // stance family as the knobs above; absent from
                    // shapeOverrides().
                    if (array_key_exists('ml_pair_allow_private', $payload)) {
                        throw new \RuntimeException(
                            'ml_pair_allow_private is not a per-directory key (root phpdup.json only): '
                            . $file->getPathname()
                        );
                    }

                    // shell-S22 (shell-026, ENGINE-SEC out-of-group 2): the
                    // PATH/REF-BEARING family is root-only. These four keys
                    // steer WRITES and EXTERNAL references — debug_log names
                    // the file the run logs into, baseline/baseline_out name
                    // files read and overwritten, diff_base becomes argv of a
                    // git call. No subtree config may choose them: the
                    // discovery walk lets any directory plant such a file, so
                    // a future per-dir-aware consumer would inherit
                    // attacker-chosen file targets. Refused loudly (same
                    // posture as the stance family above), and the keys are
                    // absent from shapeOverrides() — the derived per-file
                    // Config copy can no longer receive them at all.
                    foreach (['baseline', 'baseline_out', 'diff_base', 'debug_log'] as $pathKey) {
                        if (array_key_exists($pathKey, $payload)) {
                            throw new \RuntimeException(
                                $pathKey . ' is not a per-directory key (root phpdup.json only): '
                                . $file->getPathname()
                            );
                        }
                    }

                    $dir = realpath($file->getPath());
                    if ($dir === false) {
                        continue;
                    }
                    $out[$dir] = $this->shapeOverrides($payload);
                }
            } catch (\UnexpectedValueException $e) {
                // A directory went unreadable/vanished between list and descend
                // (race): keep whatever this root yielded so far and move on.
                // Misconfigured per-directory configs still throw
                // RuntimeException above (config errors stay exit 2); directory
                // environment errors stay silent, unusable CONFIG FILE errors
                // are noted (shell-010).
                continue;
            }
        }
        return $out;
    }

    /**
     * Read and JSON-decode a config file under the size cap (shell-029).
     * Shared by both consumers: the root leg of {@see load()} turns a
     * failure into a RuntimeException (invalid config → documented exit 2),
     * discovery degrades it to a skip note (shell-010).
     *
     * The cap is enforced by BOUNDED READ — an oversized file is detected
     * without ever being slurped whole. JSON_THROW_ON_ERROR makes the real
     * failure (e.g. "Maximum stack depth exceeded", "Syntax error") part of
     * the reason instead of the old blanket "not valid JSON", and the
     * depth limit keeps a pathological nesting from recursing into the
     * parser's own stack exhaustion.
     *
     * @return array<array-key,mixed>|string the decoded payload on success,
     *         or a human-readable reason WITHOUT the path on failure (the
     *         callers compose it; per-directory notes name the file once)
     */
    private static function decodeConfigFile(string $path): array|string
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return 'could not be read';
        }
        $bytes = @stream_get_contents($handle, self::MAX_CONFIG_FILE_BYTES + 1);
        fclose($handle);
        if ($bytes === false) {
            return 'could not be read';
        }
        if (strlen($bytes) > self::MAX_CONFIG_FILE_BYTES) {
            return sprintf('exceeds the size cap of %d bytes', self::MAX_CONFIG_FILE_BYTES);
        }
        try {
            $decoded = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return sprintf('is not valid JSON (%s)', $e->getMessage());
        }
        if (!is_array($decoded)) {
            return sprintf('is not a JSON object (got %s)', get_debug_type($decoded));
        }
        return $decoded;
    }

    /**
     * Whether a walked entry matches the exclude list the scanner will
     * apply (shell-010). The match string is built exactly like
     * FileScanner's own filter callback does it — root-relative, a
     * trailing '/' on directories — and the decision goes through
     * FileScanner::isExcluded(), so the two walks cannot drift apart:
     * what the scan will not read, discovery will not read either.
     */
    private static function isExcludedUnder(
        \Phpdup\Scanning\FileScanner $gate,
        string $root,
        \SplFileInfo $entry
    ): bool {
        $rel = self::relpathUnder($entry->getPathname(), $root);
        if ($rel === '') {
            return false;
        }
        return $entry->isDir() ? $gate->isExcluded($rel . '/') : $gate->isExcluded($rel);
    }

    /**
     * Path of a walked entry relative to its scan root — mirrors the
     * private FileScanner::relpath() by construction (both walks must
     * measure "relative" the same way or excludes drift).
     */
    private static function relpathUnder(string $abs, string $root): string
    {
        if (str_starts_with($abs, $root . '/')) {
            return substr($abs, strlen($root) + 1);
        }
        if ($abs === $root) {
            return '';
        }
        return $abs;
    }

    /**
     * Map the on-disk JSON shape (e.g. `optional_blocks: { containment: 0.9 }`)
     * to the flat override-dict shape that {@see Config::withOverrides()} accepts
     * (e.g. `optional_blocks_containment => 0.9`).
     *
     * The loop below IS the per-directory admit list. Keys that steer writes
     * or external references (baseline, baseline_out, diff_base, debug_log)
     * were removed from it by shell-S22 (shell-026): they are root-config
     * knobs, discovery refuses them in any `.phpdup.json`, and a per-file
     * derived Config therefore never copies them. The belt guard
     * (tests/Unit/Cli/PerDirOverrideBeltTest.php) pins the emitted set here.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function shapeOverrides(array $data): array
    {
        $out = [];
        foreach (['min_block_size', 'max_block_size', 'normalization_mode',
                  'similarity_threshold', 'tree_threshold', 'min_cluster_impact',
                  'max_df', 'ngram_size', 'sort',
                  'fail_on_impact', 'max_clusters', 'candidate_budget',
                  'exclude', 'exclude_reset'] as $k) {
            if (array_key_exists($k, $data)) {
                $out[$k] = $data[$k];
            }
        }
        if (array_key_exists('kinds', $data)) {
            $out['allowed_kinds'] = $data['kinds'];
        }
        if (isset($data['optional_blocks']) && is_array($data['optional_blocks'])) {
            $ob = $data['optional_blocks'];
            if (array_key_exists('enabled', $ob))            $out['optional_blocks_enabled']            = $ob['enabled'];
            if (array_key_exists('containment', $ob))        $out['optional_blocks_containment']        = $ob['containment'];
            if (array_key_exists('min_overlap', $ob))        $out['optional_blocks_min_overlap']        = $ob['min_overlap'];
            if (array_key_exists('max_per_cluster', $ob))    $out['optional_blocks_max_per_cluster']    = $ob['max_per_cluster'];
            if (array_key_exists('min_segment_length', $ob)) $out['optional_blocks_min_segment_length'] = $ob['min_segment_length'];
        }
        if (isset($data['db_symbols']) && is_array($data['db_symbols'])) {
            $ds = $data['db_symbols'];
            if (isset($ds['methods']) && is_array($ds['methods'])) {
                $out['db_symbols_methods'] = $ds['methods'];
            }
            if (isset($ds['functions']) && is_array($ds['functions'])) {
                $out['db_symbols_functions'] = $ds['functions'];
            }
        }
        // shell-S22: debug_log is NOT admitted (path/ref-bearing root-only
        // family, shell-026) — discovery refuses it before this method ever
        // sees such a payload, and the key stays out of the emit list so the
        // per-file derived Config never copies it.
        if (array_key_exists('low_memory', $data)) {
            $out['low_memory'] = $data['low_memory'];
        }
        return $out;
    }

    /**
     * Validate a parsed config payload against the documented schema.
     *
     * Mirrors docs/config-schema.json — kept in sync by hand because we don't
     * want a JSON-Schema validator dependency just for config loading.
     *
     * @param array<string,mixed> $data
     * @throws \RuntimeException with a field-path on the first violation found.
     */
    public function validate(array $data, ?string $source = null): void
    {
        $where = $source !== null ? " in $source" : '';

        $known = [
            'paths', 'exclude', 'exclude_reset',
            'min_block_size', 'max_block_size',
            'normalization_mode',
            'similarity_threshold', 'tree_threshold',
            'min_cluster_impact', 'max_df', 'ngram_size',
            'cache_dir', 'parallelism', 'workers',
            'incremental', 'lazy_ast',
            'kinds',
            'optional_blocks',
            'sort',
            'ted_weights',
            'report',
            'normalization',
            'db_aware',
            'trinity_collapse',
            'db_symbols',
            'scorer',
            'fingerprint_id_mode',
            'scope',
            'score_mode',
            'ir_threshold',
            'ml_pair_url',
            'ml_pair_threshold',
            'ml_pair_allow_private',
            'debug_log',
            'low_memory',
            'fail_on_impact',
            'max_clusters',
            'candidate_budget',
            'max_ted_size',
            'max_hole_coverage',
            'baseline',
            'baseline_out',
            'diff_base', 'apply',
        ];
        foreach (array_keys($data) as $k) {
            if (!in_array($k, $known, true)) {
                throw new \RuntimeException("Unknown config key '$k'$where");
            }
        }

        $assertListOfStrings = static function (mixed $v, string $path) use ($where): void {
            if (!is_array($v)) {
                throw new \RuntimeException("$path must be an array$where");
            }
            $i = 0;
            foreach ($v as $key => $item) {
                if ($key !== $i++) {
                    throw new \RuntimeException("$path must be a list (no string keys)$where");
                }
                if (!is_string($item) || $item === '') {
                    throw new \RuntimeException("$path must contain non-empty strings$where");
                }
            }
        };

        if (array_key_exists('paths', $data)) {
            $assertListOfStrings($data['paths'], 'paths');
            if ($data['paths'] === []) {
                throw new \RuntimeException("paths must not be empty$where");
            }
        }
        if (array_key_exists('exclude', $data)) {
            $assertListOfStrings($data['exclude'], 'exclude');
        }
        if (array_key_exists('kinds', $data)) {
            $assertListOfStrings($data['kinds'], 'kinds');
            foreach ($data['kinds'] as $k) {
                if (!in_array($k, \Phpdup\Extraction\BlockExtractor::ALL_KINDS, true)) {
                    throw new \RuntimeException(
                        "kinds[] entry '$k' must be one of "
                        . implode('|', \Phpdup\Extraction\BlockExtractor::ALL_KINDS)
                        . $where
                    );
                }
            }
        }

        $assertInt = static function (mixed $v, string $path, int $min, ?int $max = null) use ($where): void {
            if (!is_int($v)) {
                throw new \RuntimeException("$path must be an integer$where");
            }
            if ($v < $min) {
                throw new \RuntimeException("$path must be >= $min$where");
            }
            if ($max !== null && $v > $max) {
                throw new \RuntimeException("$path must be <= $max$where");
            }
        };
        $assertFloat01 = static function (mixed $v, string $path) use ($where): void {
            if (!is_int($v) && !is_float($v)) {
                throw new \RuntimeException("$path must be a number$where");
            }
            $f = (float)$v;
            if ($f < 0.0 || $f > 1.0) {
                throw new \RuntimeException("$path must be in [0, 1]$where");
            }
        };
        $assertEnum = static function (mixed $v, string $path, array $allowed) use ($where): void {
            if (!in_array($v, $allowed, true)) {
                throw new \RuntimeException(
                    "$path must be one of " . implode('|', $allowed) . "$where"
                );
            }
        };

        $hasMin = array_key_exists('min_block_size', $data);
        $hasMax = array_key_exists('max_block_size', $data);
        if ($hasMin) {
            $assertInt($data['min_block_size'], 'min_block_size', 1);
        }
        if ($hasMax) {
            $assertInt($data['max_block_size'], 'max_block_size', 1);
        }
        if ($hasMin && $hasMax && (int)$data['min_block_size'] > (int)$data['max_block_size']) {
            throw new \RuntimeException("min_block_size must be <= max_block_size$where");
        }
        if (array_key_exists('normalization_mode', $data)) {
            $assertEnum($data['normalization_mode'], 'normalization_mode', ['strict', 'default', 'aggressive']);
        }
        if (array_key_exists('similarity_threshold', $data)) {
            $assertFloat01($data['similarity_threshold'], 'similarity_threshold');
        }
        if (array_key_exists('tree_threshold', $data)) {
            $assertFloat01($data['tree_threshold'], 'tree_threshold');
        }
        if (array_key_exists('min_cluster_impact', $data)) {
            $assertInt($data['min_cluster_impact'], 'min_cluster_impact', 0);
        }
        if (array_key_exists('max_df', $data)) {
            $assertFloat01($data['max_df'], 'max_df');
        }
        if (array_key_exists('ngram_size', $data)) {
            $assertInt($data['ngram_size'], 'ngram_size', 2, 10);
        }
        if (array_key_exists('cache_dir', $data) && !is_string($data['cache_dir'])) {
            throw new \RuntimeException("cache_dir must be a string$where");
        }
        if (array_key_exists('parallelism', $data)) {
            $assertEnum($data['parallelism'], 'parallelism', ['auto', 'off', 'manual']);
        }
        if (array_key_exists('workers', $data)) {
            $assertInt($data['workers'], 'workers', 0);
        }
        if (array_key_exists('candidate_budget', $data)) {
            $assertInt($data['candidate_budget'], 'candidate_budget', 0);
        }
        if (array_key_exists('max_ted_size', $data)) {
            $assertInt($data['max_ted_size'], 'max_ted_size', 1);
        }
        if (array_key_exists('max_hole_coverage', $data)) {
            $assertFloat01($data['max_hole_coverage'], 'max_hole_coverage');
        }
        foreach (['incremental', 'lazy_ast'] as $boolKey) {
            if (array_key_exists($boolKey, $data) && !is_bool($data[$boolKey])) {
                throw new \RuntimeException("$boolKey must be a boolean$where");
            }
        }
        if (array_key_exists('optional_blocks', $data)) {
            if (!is_array($data['optional_blocks'])) {
                throw new \RuntimeException("optional_blocks must be an object$where");
            }
            $allowed = ['enabled', 'containment', 'min_overlap', 'max_per_cluster', 'min_segment_length'];
            foreach ($data['optional_blocks'] as $k => $v) {
                if (!in_array($k, $allowed, true)) {
                    throw new \RuntimeException("Unknown config key 'optional_blocks.$k'$where");
                }
            }
            if (array_key_exists('enabled', $data['optional_blocks']) && !is_bool($data['optional_blocks']['enabled'])) {
                throw new \RuntimeException("optional_blocks.enabled must be a boolean$where");
            }
            foreach (['containment', 'min_overlap'] as $k) {
                if (array_key_exists($k, $data['optional_blocks'])) {
                    $assertFloat01($data['optional_blocks'][$k], "optional_blocks.$k");
                }
            }
            if (array_key_exists('max_per_cluster', $data['optional_blocks'])) {
                $assertInt($data['optional_blocks']['max_per_cluster'], 'optional_blocks.max_per_cluster', 0);
            }
            if (array_key_exists('min_segment_length', $data['optional_blocks'])) {
                $assertInt($data['optional_blocks']['min_segment_length'], 'optional_blocks.min_segment_length', 1);
            }
        }
        if (array_key_exists('sort', $data)) {
            if (!is_string($data['sort']) || $data['sort'] === '') {
                throw new \RuntimeException("sort must be a non-empty string$where");
            }
            try {
                \Phpdup\Reporting\ClusterSort::parse($data['sort']);
            } catch (\InvalidArgumentException $e) {
                throw new \RuntimeException("sort: {$e->getMessage()}$where");
            }
        }
        if (array_key_exists('ted_weights', $data)) {
            $assertEnum($data['ted_weights'], 'ted_weights', \Phpdup\Similarity\EditCostModel::MODELS);
        }
        if (array_key_exists('db_aware', $data) && !is_bool($data['db_aware'])) {
            throw new \RuntimeException("db_aware must be a boolean$where");
        }
        if (array_key_exists('trinity_collapse', $data) && !is_bool($data['trinity_collapse'])) {
            throw new \RuntimeException("trinity_collapse must be a boolean$where");
        }
        if (array_key_exists('exclude_reset', $data) && !is_bool($data['exclude_reset'])) {
            throw new \RuntimeException("exclude_reset must be a boolean$where");
        }
        if (array_key_exists('low_memory', $data) && !is_bool($data['low_memory'])) {
            throw new \RuntimeException("low_memory must be a boolean$where");
        }
        if (array_key_exists('scorer', $data)) {
            $assertEnum($data['scorer'], 'scorer', ['default', 'ir']);
        }
        if (array_key_exists('fingerprint_id_mode', $data)) {
            // 'numbered' keeps every legacy byte stable; 'un-numbered' is
            // the item 1.1 (§5a) drift-tolerant mode. The third planned
            // value 'realnames' is NOT admitted until 1.1-follow-up ships
            // the normalization work behind it.
            $assertEnum($data['fingerprint_id_mode'], 'fingerprint_id_mode', \Phpdup\Cli\Config::FINGERPRINT_ID_MODES);
        }
        if (array_key_exists('score_mode', $data)) {
            // Item 5.1: 'global' is the byte-identical legacy stance;
            // per-repo groups the ns double-dip by repo; typed rewards
            // cross-repo clones (audit posture).
            $assertEnum($data['score_mode'], 'score_mode', \Phpdup\Cli\Config::SCORE_MODES);
        }
        if (array_key_exists('scope', $data)) {
            // Item 5.1: open-set value (any non-empty string): 'all',
            // 'single', 'multi', or an exact repo label. Empty is the
            // only shape error the loader can see here.
            if (!is_string($data['scope']) || $data['scope'] === '') {
                throw new \RuntimeException("scope must be a non-empty string$where");
            }
        }
        if (array_key_exists('ir_threshold', $data)) {
            $assertFloat01($data['ir_threshold'], 'ir_threshold');
        }
        if (array_key_exists('ml_pair_allow_private', $data) && !is_bool($data['ml_pair_allow_private'])) {
            // once-S54: shape-asserted BEFORE the URL leg below so the
            // key consulted for policy is already known boolean.
            throw new \RuntimeException("ml_pair_allow_private must be a boolean$where");
        }
        if (array_key_exists('ml_pair_url', $data)) {
            if (!is_string($data['ml_pair_url'])) {
                throw new \RuntimeException("ml_pair_url must be a string$where");
            }
            if ($data['ml_pair_url'] !== '') {
                // once-S54 (php-dup-finder-once-076/083/084): shape-only
                // admission — zero resolver calls, so validating an
                // attacker-supplied URL can never stall or exfil over
                // DNS. Names are decided by the dial, fail-closed
                // there. Refusals name the space and the opt-in.
                $mlPairRefusal = \Phpdup\Ml\MlPairClient::pairUrlAdmissionError(
                    rtrim($data['ml_pair_url'], '/') . '/score-pair',
                    (bool)($data['ml_pair_allow_private'] ?? false),
                );
                if ($mlPairRefusal !== null) {
                    throw new \RuntimeException("$mlPairRefusal$where");
                }
            }
        }
        if (array_key_exists('ml_pair_threshold', $data)) {
            $assertFloat01($data['ml_pair_threshold'], 'ml_pair_threshold');
        }
        if (array_key_exists('fail_on_impact', $data)) {
            $assertInt($data['fail_on_impact'], 'fail_on_impact', 0);
        }
        if (array_key_exists('max_clusters', $data)) {
            $assertInt($data['max_clusters'], 'max_clusters', 0);
        }
        if (array_key_exists('baseline', $data)) {
            if (!is_string($data['baseline']) || $data['baseline'] === '') {
                throw new \RuntimeException("baseline must be a non-empty string$where");
            }
        }
        if (array_key_exists('baseline_out', $data)) {
            if (!is_string($data['baseline_out']) || $data['baseline_out'] === '') {
                throw new \RuntimeException("baseline_out must be a non-empty string$where");
            }
        }
        if (array_key_exists('db_symbols', $data)) {
            if (!is_array($data['db_symbols'])) {
                throw new \RuntimeException("db_symbols must be an object$where");
            }
            $allowedBuckets = ['methods', 'functions'];
            foreach (array_keys($data['db_symbols']) as $bucket) {
                if (!in_array($bucket, $allowedBuckets, true)) {
                    throw new \RuntimeException(
                        "Unknown config key 'db_symbols.$bucket' (allowed: methods|functions)$where",
                    );
                }
                if (!is_array($data['db_symbols'][$bucket])) {
                    throw new \RuntimeException("db_symbols.$bucket must be an object$where");
                }
                $allowedOps = [
                    \Phpdup\Normalization\DbOpRegistry::OP_READ,
                    \Phpdup\Normalization\DbOpRegistry::OP_WRITE,
                    \Phpdup\Normalization\DbOpRegistry::OP_DELETE,
                    \Phpdup\Normalization\DbOpRegistry::OP_EXECUTE,
                    \Phpdup\Normalization\DbOpRegistry::OP_QUERY,
                ];
                foreach ($data['db_symbols'][$bucket] as $name => $op) {
                    if (!is_string($name) || $name === '') {
                        throw new \RuntimeException("db_symbols.$bucket keys must be non-empty strings$where");
                    }
                    if (!is_string($op) || !in_array($op, $allowedOps, true)) {
                        throw new \RuntimeException(
                            "db_symbols.$bucket.$name must be one of " . implode('|', $allowedOps) . "$where",
                        );
                    }
                }
            }
        }
        if (array_key_exists('normalization', $data)) {
            if (!is_array($data['normalization'])) {
                throw new \RuntimeException("normalization must be an object$where");
            }
            foreach ($data['normalization'] as $k => $v) {
                if ($k !== 'plugins') {
                    throw new \RuntimeException("Unknown config key 'normalization.$k'$where");
                }
            }
            if (isset($data['normalization']['plugins'])) {
                $assertListOfStrings($data['normalization']['plugins'], 'normalization.plugins');
            }
        }
        if (array_key_exists('report', $data)) {
            if (!is_array($data['report'])) {
                throw new \RuntimeException("report must be an object$where");
            }
            foreach ($data['report'] as $k => $v) {
                if (!in_array($k, ['html', 'json'], true)) {
                    throw new \RuntimeException("Unknown config key 'report.$k'$where");
                }
                if (!is_string($v)) {
                    throw new \RuntimeException("report.$k must be a string$where");
                }
            }
        }
        if (array_key_exists('debug_log', $data)) {
            if (!is_string($data['debug_log']) || $data['debug_log'] === '') {
                throw new \RuntimeException("debug_log must be a non-empty string$where");
            }
        }
        if (array_key_exists('diff_base', $data)) {
            if (!is_string($data['diff_base']) || $data['diff_base'] === '') {
                throw new \RuntimeException("diff_base must be a non-empty string$where");
            }
        }
    }
}
