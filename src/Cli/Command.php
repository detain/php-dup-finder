<?php
declare(strict_types=1);

namespace Phpdup\Cli;

use Phpdup\Pipeline\Pipeline;
use Phpdup\Pipeline\ProgressListener;
use Phpdup\Tui\PhpdupModel;
use Phpdup\Tui\TuiRunner;
use Phpdup\Watch\WatchRunner;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\Stage;
use Phpdup\Pipeline\Stages\ClusterStage;
use Phpdup\Pipeline\Stages\PreprocessStage;
use Phpdup\Pipeline\Stages\RefactorStage;
use Phpdup\Pipeline\Stages\ReportStage;
use Phpdup\Pipeline\Stages\ScanningStage;
use Phpdup\Reporting\BaselineStore;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `phpdup analyze` entry-point.
 *
 * execute() delegates to six private methods that each own one logical phase.
 * Keeping them private enforces a strict call order and makes the flow
 * easier to follow than a long linear method:
 *
 *  1. parseCliOverrides()   — validate and collect every CLI flag into a
 *                             flat override dict; returns [] on error.
 *  2. resolveProfile()       — load a --profile preset and merge its values
 *                             into $overrides (only for unset keys).
 *  3. resolveAutoTune()      — probe the corpus and append auto-suggested
 *                             overrides; also returns whether --exact-only
 *                             was forced by the tuner.
 *  4. ConfigLoader::load()   — applies the three-tier precedence chain
 *                             (overrides → config file → defaults) and
 *                             returns a frozen Config object.
 *  5. parseRuntimeOptions()  — extract options that control pipeline runtime
 *                             behaviour (cache, TUI, watch, output formats).
 *  6. buildPipelineFactory() — build the Pipeline with resolved options.
 *  7. dispatch()             — run the pipeline directly, via TUI, or via
 *                             watch-mode, and return the process exit code.
 */
final class Command extends SymfonyCommand
{
    protected function configure(): void
    {
        $this->setName('analyze')
            ->setDescription('Recursively scan PHP source roots for duplicated logic.')
            ->setHelp($this->buildGroupedHelp())
            ->addArgument('paths', InputArgument::IS_ARRAY, 'One or more paths to scan', []);

        // ── Configuration ──────────────────────────────────────────────────
        $this
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Path to phpdup.json config file')
            ->addOption('validate-config', null, InputOption::VALUE_NONE, 'Validate the --config file against the documented schema and exit (no analysis is run)');

        // ── Detection tuning ───────────────────────────────────────────────
        $this
            ->addOption('min-block-size', null, InputOption::VALUE_REQUIRED, 'Minimum AST node count for a block')
            ->addOption('mode', null, InputOption::VALUE_REQUIRED, 'Normalization mode: strict|default|aggressive')
            ->addOption('similarity', null, InputOption::VALUE_REQUIRED, 'Jaccard similarity threshold (0..1)')
            ->addOption('max-df', null, InputOption::VALUE_REQUIRED, 'Max document-frequency (0..1) for n-grams to be candidate-pair seeds. 0.01 default suits real codebases; bump to ~0.5 for small fixtures.')
            ->addOption('optional-blocks', null, InputOption::VALUE_REQUIRED, 'Type-3 / "optional segment" detection: on|off (default on). When on, blocks whose statements differ in length but share a common skeleton cluster together with bool $include* params for the absent-from-some-members segments.')
            ->addOption('optional-blocks-containment', null, InputOption::VALUE_REQUIRED, 'Containment threshold (0..1) for the type-3 fallback path. Default 0.85.')
            ->addOption('min-impact', null, InputOption::VALUE_REQUIRED, 'Minimum cluster impact to report')
            ->addOption('exact-only', null, InputOption::VALUE_NONE, 'Skip near-duplicate detection (faster)')
            ->addOption('kinds', null, InputOption::VALUE_REQUIRED, 'Comma-separated block kinds to include (e.g. method,closure). Default: all of method|function|closure|arrow|if|for|foreach|while|do|try|switch|match')
            ->addOption('auto-tune', null, InputOption::VALUE_NONE, 'Probe the corpus before analysis and pick min-block-size / max-df / min-impact based on size; --exact-only is forced on for very large trees. Picked profile is printed; explicit CLI overrides take precedence.')
            ->addOption('profile', null, InputOption::VALUE_REQUIRED, 'Apply a project profile (laravel|symfony|drupal|wordpress|generic|db-aware-laravel|db-aware-doctrine|db-aware-cake|db-aware-thinkorm|db-aware-medoo|db-aware-propel|db-aware-redbean|db-aware-cycle|db-aware-phpactiverecord|auto) to seed framework-aware excludes + tuning. db-aware-* profiles ship symbol-equivalence packs for various ORMs. "auto" sniffs the scan path for known markers. Explicit CLI flags + --config win over profile values.')
            ->addOption('min-safety', null, InputOption::VALUE_REQUIRED, 'Drop clusters whose refactor-safety score (0..1) is below this threshold. 0 = report all (default).')
            ->addOption('ted-weights', null, InputOption::VALUE_REQUIRED, 'Tree-edit cost model: default|semantic. semantic weights method calls / control flow heavier than literals.')
            ->addOption('db-aware', null, InputOption::VALUE_NONE, 'ORM-/DB-aware semantic dedup: rewrite recognised database calls (Eloquent, Doctrine, PDO, mysqli, pg_*, raw SQL) to canonical __DB_<OP>__ tokens during normalisation so equivalent ORM and raw-SQL variants cluster together. Off by default — opt-in for ORM-heavy codebases. See docs/plans/orm-db-semantic-dedup.md.')
            ->addOption('trinity-collapse', null, InputOption::VALUE_NONE, 'Detect the canonical CRUD trinity (read → mutate → save) and rewrite the three-statement sequence as a single __DB_UPSERT__("entity") synthetic call so ORM upserts cluster with raw UPDATE queries. Composes with --db-aware. Off by default. See option 2 of docs/plans/orm-db-semantic-dedup.md.')
            ->addOption('scorer', null, InputOption::VALUE_REQUIRED, 'Scoring tier set: default | ir. "ir" enables option-5 IR-tier fallback — when AST Jaccard / TED / containment all reject a pair, lift both blocks to the canonical IR (Phpdup\\Ir\\IrLifter) and Jaccard their token bags. Pairs at or above --ir-threshold form edges weighted by the IR similarity. Off by default.', 'default')
            ->addOption('ir-threshold', null, InputOption::VALUE_REQUIRED, 'IR-tier multiset-Jaccard threshold (0..1). Pairs at or above this score form edges. Default 0.85.')
            ->addOption('ml-pair-url', null, InputOption::VALUE_REQUIRED, 'External pair-similarity ML sidecar URL (option 6 of docs/plans/orm-db-semantic-dedup.md). Empty = disabled. When set, the very last clustering tier — runs after structural-hash, AST Jaccard + TED, containment, and IR all reject a pair. Posts a PairFeatures vector to <URL>/score-pair and accepts pairs at or above --ml-pair-threshold. Returns null on transport failure so unavailability never breaks the run. http(s) only.')
            ->addOption('ml-pair-threshold', null, InputOption::VALUE_REQUIRED, 'Similarity threshold (0..1) for the option-6 ML pair tier. Pairs whose model-returned similarity meets this value emit edges. Default 0.80.')
            ->addOption('fail-on-impact', null, InputOption::VALUE_REQUIRED, 'Exit code 3 when total cluster impact exceeds N. 0 = disabled. CI gate for duplicate debt thresholds.')
            ->addOption('max-clusters', null, InputOption::VALUE_REQUIRED, 'Exit code 3 when cluster count exceeds N. 0 = disabled. CI gate for max cluster count.');

        // ── Output / reports ───────────────────────────────────────────────
        $this
            ->addOption('html', null, InputOption::VALUE_REQUIRED, 'Write HTML report to this directory')
            ->addOption('json', null, InputOption::VALUE_REQUIRED, 'Write JSON report to this file')
            ->addOption('sarif', null, InputOption::VALUE_REQUIRED, 'Write SARIF 2.1.0 report to FILE (for GitHub/GitLab PR annotations)')
            ->addOption('gitlab-sast', null, InputOption::VALUE_REQUIRED, 'Write GitLab SAST report (v15) to FILE')
            ->addOption('checkstyle', null, InputOption::VALUE_REQUIRED, 'Write Checkstyle XML report to FILE')
            ->addOption('csv', null, InputOption::VALUE_REQUIRED, 'Write flat CSV (one row per cluster member) to FILE')
            ->addOption('prometheus', null, InputOption::VALUE_REQUIRED, 'Write Prometheus text-format metrics to FILE (for pushgateway / CI dashboards)')
            ->addOption('timeseries', null, InputOption::VALUE_REQUIRED, 'Append a JSONL summary record (commit-tagged) to FILE for tracking duplicate debt over time')
            ->addOption('graphviz', null, InputOption::VALUE_REQUIRED, 'Write a Graphviz DOT graph (file→cluster bipartite) to FILE — render with `dot -Tpng FILE -o out.png`')
            ->addOption('plantuml', null, InputOption::VALUE_REQUIRED, 'Write a PlantUML class diagram to FILE — render with `plantuml FILE`')
            ->addOption('refactor-patch', null, InputOption::VALUE_REQUIRED, 'Emit one .patch file per cluster into DIR — heuristic, manual-review-required.')
            ->addOption('refactor-tests', null, InputOption::VALUE_REQUIRED, 'Emit a PHPUnit test skeleton per cluster into DIR (data-provider rows mirror hole observations).')
            ->addOption('diff', null, InputOption::VALUE_REQUIRED, 'Write per-cluster unified diffs into DIR')
            ->addOption('patch', null, InputOption::VALUE_REQUIRED, 'Write a single cumulative patch file containing every cluster diff')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Write a cumulative apply.diff containing all cluster refactor patches')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'With --apply: preview the refactor patch without modifying any files (default for --apply in F1a; F1b will make this explicit)')
            ->addOption('baseline', null, InputOption::VALUE_REQUIRED, 'CI baseline file. If file exists: compare and exit 4 if new clusters found. If file does not exist: write baseline and exit 0 (first-run auto-baseline).')
            ->addOption('baseline-out', null, InputOption::VALUE_REQUIRED, 'Write current clusters as a baseline snapshot to FILE (overwrites existing).')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Show at most N clusters in CLI output', 50)
            ->addOption('summary-only', null, InputOption::VALUE_NONE, 'Render only the top banner + final summary line (skip per-cluster details)')
            ->addOption('clusters', null, InputOption::VALUE_NONE, 'Render a one-line-per-cluster table instead of the full per-cluster breakdown')
            ->addOption('pager', null, InputOption::VALUE_REQUIRED, 'Page CLI report through $PAGER (default `less -R`). Modes: auto|never|always. auto pages when stdout is a TTY and the rendered output exceeds ~60 lines.', 'never')
            ->addOption('sort', null, InputOption::VALUE_REQUIRED, 'Cluster sort: KEY[:asc|desc]. Keys: impact|members|block-size|lines|similarity|confidence|name|file|id. Aliases: size→members, count→members. Default impact:desc.')
            ->addOption('stats', null, InputOption::VALUE_NONE, 'Show pipeline statistics')
            ->addOption('debug-log', null, InputOption::VALUE_REQUIRED, 'Append all debug (vvv) messages to FILE');

        // ── Performance / runtime ──────────────────────────────────────────
        $this
            ->addOption('workers', 'j', InputOption::VALUE_REQUIRED, 'Worker count for parallel preprocess + pair scoring (0 = auto, 1 = serial)')
            ->addOption('no-cache', null, InputOption::VALUE_NONE, 'Disable AST cache for this run')
            ->addOption('no-incremental', null, InputOption::VALUE_NONE, 'Disable per-file index reuse')
            ->addOption('no-lazy-ast', null, InputOption::VALUE_NONE, 'Keep all original ASTs in memory (higher RSS, faster anti-unification)')
            ->addOption('max-memory', null, InputOption::VALUE_REQUIRED, 'Soft memory ceiling in MB. When peak RSS exceeds this mid-pipeline, phpdup logs a warning and suggests --exact-only.')
            ->addOption('low-memory', null, InputOption::VALUE_NONE, 'Reduce memory footprint: use CompactNgramBag (32-bit fingerprint) and CanonicalNodePool interning for lower RSS at the cost of some speed.')
            ->addOption('stage', null, InputOption::VALUE_REQUIRED, 'Run pipeline only up to STAGE (scanning|preprocessing|clustering|refactoring|reporting); useful for incremental debugging')
            ->addOption('diff-base', null, InputOption::VALUE_REQUIRED, 'Git ref for diff-scoped analysis. When set, phpdup scans only files changed in `git diff --name-only <ref>..HEAD` plus their clone cohort (files sharing n-gram fingerprints with the changed files).');

        // ── Interactive / UI ───────────────────────────────────────────────
        $this
            ->addOption('tui', null, InputOption::VALUE_NONE, 'Show interactive SugarCraft dashboard after analysis completes')
            ->addOption('plain', null, InputOption::VALUE_NONE, 'Force plain CLI output (no TUI, no ANSI colours)')
            ->addOption('theme', null, InputOption::VALUE_REQUIRED, 'TUI theme: ansi|plain|charm|dracula|nord|catppuccin', 'ansi')
            ->addOption('watch', null, InputOption::VALUE_NONE, 'Stay running and re-analyze on file changes (poll-based; Ctrl+C to exit)');
    }

    /**
     * Categorized cheat-sheet shown under `Help:` when running --help.
     * Complements Symfony's auto-generated --help option list (which preserves
     * the addOption() order, so the groups also stay together there).
     */
    private function buildGroupedHelp(): string
    {
        return <<<HELP
Options grouped by category:

 <comment>Configuration</comment>
   --config, --validate-config

  <comment>Detection tuning</comment>
    --min-block-size, --mode, --similarity, --max-df,
    --optional-blocks, --optional-blocks-containment,
    --min-impact, --min-safety, --fail-on-impact, --max-clusters,
    --exact-only, --kinds, --auto-tune,
    --ted-weights, --db-aware, --trinity-collapse,
    --scorer, --ir-threshold,
    --ml-pair-url, --ml-pair-threshold,
    --profile

  <comment>Output / reports</comment>
    --html, --json, --sarif, --gitlab-sast, --checkstyle,
    --csv, --prometheus, --timeseries,
    --graphviz, --plantuml,
    --refactor-patch, --refactor-tests,
    --diff, --patch, --apply, --dry-run, --limit, --sort, --stats,
    --summary-only, --clusters

  <comment>CI / Baseline</comment>
    --baseline, --baseline-out

  <comment>Performance / runtime</comment>
    --workers (-j), --no-cache, --no-incremental, --no-lazy-ast,
    --max-memory, --low-memory, --stage, --diff-base

 <comment>Interactive / UI</comment>
   --tui, --plain, --theme, --watch

Run <info>phpdup --help</info> for full descriptions of each option.
HELP;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('validate-config')) {
            return $this->validateConfigOnly($input, $output);
        }

        $paths = $input->getArgument('paths');
        if (!$paths) {
            $output->writeln('<error>phpdup: at least one path is required</error>');
            $output->writeln('Run <info>phpdup --help</info> for usage and a grouped option reference.');
            $output->writeln('Example: <info>phpdup src/ --html=build/dup-report</info>');
            return 2;
        }

        $overrides = $this->parseCliOverrides($input, $output);
        if ($overrides === []) {
            return 2; // parseCliOverrides emitted an error and returned []
        }

        $profileExclude = $this->resolveProfile($input, $output, $paths, $overrides);

        $autoTuneExactOnly = $this->resolveAutoTune($input, $output, $paths, $overrides);

        $config = (new ConfigLoader())->load(
            paths: $paths,
            configFile: $input->getOption('config'),
            overrides: $overrides,
            profileExclude: $profileExclude,
        );

        $runtimeOptions = $this->parseRuntimeOptions($input, $output, $autoTuneExactOnly);

        // buildPipelineFactory only needs a subset of runtimeOptions
        $pipelineOpts = [
            'useCache'   => $runtimeOptions['useCache'],
            'exactOnly'  => $runtimeOptions['exactOnly'],
            'showStats'  => $runtimeOptions['showStats'],
            'maxMemoryMb' => $runtimeOptions['maxMemoryMb'],
            'stopAfter'  => $runtimeOptions['stopAfter'],
            'reportArgs' => $runtimeOptions['reportArgs'],
        ];
        $buildPipeline = $this->buildPipelineFactory($pipelineOpts);

        return $this->dispatch($input, $output, $config, $buildPipeline, $runtimeOptions);
    }

    /**
     * Parse all CLI flags into a flat override dict.
     * Returns [] on error (after emitting diagnostics).
     *
     * @return array<string, mixed>
     */
    private function parseCliOverrides(InputInterface $input, OutputInterface $output): array
    {
        $overrides = array_filter([
            'min_block_size'              => $input->getOption('min-block-size'),
            'normalization_mode'          => $input->getOption('mode'),
            'similarity_threshold'        => $input->getOption('similarity'),
            'min_cluster_impact'          => $input->getOption('min-impact'),
            'fail_on_impact'              => $input->getOption('fail-on-impact'),
            'max_clusters'                => $input->getOption('max-clusters'),
            'html'                        => $input->getOption('html'),
            'json'                        => $input->getOption('json'),
            'workers'                     => $input->getOption('workers'),
            'max_df'                      => $input->getOption('max-df'),
            'optional_blocks_containment' => $input->getOption('optional-blocks-containment'),
            'sort'                        => $input->getOption('sort'),
            'ted_weights'                 => $input->getOption('ted-weights'),
            'debug_log'                   => $input->getOption('debug-log'),
            'baseline'                    => $input->getOption('baseline'),
            'baseline_out'                => $input->getOption('baseline-out'),
            'diff_base'                   => $input->getOption('diff-base'),
        ], fn($v) => $v !== null);

        if ($input->getOption('db-aware')) {
            $overrides['db_aware'] = true;
        }
        if ($input->getOption('trinity-collapse')) {
            $overrides['trinity_collapse'] = true;
        }
        if ($input->getOption('apply')) {
            $overrides['apply'] = true;
        }

        $scorerOpt = $input->getOption('scorer');
        if ($scorerOpt !== null) {
            $scorerOpt = strtolower((string)$scorerOpt);
            if (!in_array($scorerOpt, ['default', 'ir'], true)) {
                $output->writeln('<error>phpdup: --scorer must be one of default|ir</error>');
                return [];
            }
            $overrides['scorer'] = $scorerOpt;
        }

        $irThresholdOpt = $input->getOption('ir-threshold');
        if ($irThresholdOpt !== null) {
            $overrides['ir_threshold'] = (float)$irThresholdOpt;
        }

        $mlPairUrlOpt = $input->getOption('ml-pair-url');
        if ($mlPairUrlOpt !== null) {
            $url = (string)$mlPairUrlOpt;
            if ($url !== ''
                && !\Phpdup\Ml\MlClient::isAllowedUrl(rtrim($url, '/') . '/score-pair')
            ) {
                $output->writeln('<error>phpdup: --ml-pair-url must be an http(s) URL with a non-empty host (and not 0.0.0.0)</error>');
                return [];
            }
            $overrides['ml_pair_url'] = $url;
        }

        $mlPairThresholdOpt = $input->getOption('ml-pair-threshold');
        if ($mlPairThresholdOpt !== null) {
            $overrides['ml_pair_threshold'] = (float)$mlPairThresholdOpt;
        }

        $obFlag = $input->getOption('optional-blocks');
        if ($obFlag !== null) {
            $obFlag = strtolower((string)$obFlag);
            if (!in_array($obFlag, ['on', 'off', 'true', 'false', '1', '0', 'yes', 'no'], true)) {
                $output->writeln('<error>phpdup: --optional-blocks must be on|off</error>');
                return [];
            }
            $overrides['optional_blocks_enabled'] = in_array($obFlag, ['on', 'true', '1', 'yes'], true);
        }

        if ($input->getOption('no-incremental')) $overrides['incremental'] = false;
        if ($input->getOption('no-lazy-ast'))    $overrides['lazy_ast']   = false;
        if ($input->getOption('low-memory'))    $overrides['low_memory'] = true;

        // Validate --sort eagerly so the user gets a friendly exit 2 instead
        // of an uncaught InvalidArgumentException out of Config::__construct.
        $sortOpt = $input->getOption('sort');
        if ($sortOpt !== null) {
            try {
                \Phpdup\Reporting\ClusterSort::parse((string)$sortOpt);
            } catch (\InvalidArgumentException $e) {
                $output->writeln(sprintf(
                    '<error>phpdup: --sort %s. Valid keys: %s. Direction: asc|desc.</error>',
                    $e->getMessage(),
                    implode(', ', \Phpdup\Reporting\ClusterSort::ALL_KEYS),
                ));
                return [];
            }
        }

        $kindsOpt = $input->getOption('kinds');
        if ($kindsOpt !== null) {
            $kinds = array_values(array_filter(array_map('trim', explode(',', (string)$kindsOpt))));
            $invalid = array_diff($kinds, \Phpdup\Extraction\BlockExtractor::ALL_KINDS);
            if ($invalid) {
                $output->writeln(sprintf(
                    '<error>phpdup: --kinds invalid: %s. Valid: %s</error>',
                    implode(',', $invalid),
                    implode(',', \Phpdup\Extraction\BlockExtractor::ALL_KINDS),
                ));
                return [];
            }
            $overrides['allowed_kinds'] = $kinds;
        }

        return $overrides;
    }

    /**
     * Load and apply a profile if --profile is set.
     *
     * Modifies $overrides in place: profile values fill in only keys
     * the user did not already set via CLI flags.
     *
     * @param list<string> $paths
     * @param array<string, mixed> $overrides
     * @return list<string>|null $profileExclude
     */
    private function resolveProfile(InputInterface $input, OutputInterface $output, array $paths, array &$overrides): ?array
    {
        $profileOpt = $input->getOption('profile');
        if ($profileOpt === null) {
            return null;
        }

        $registry = ProfileRegistry::bundled();
        $profileName = (string)$profileOpt;

        if ($profileName === 'auto') {
            $profileName = (new ProjectProfileDetector())->detect($paths);
            $output->writeln("<info>phpdup</info> profile auto-detect: {$profileName}");
        } elseif (!in_array($profileName, $registry->listAvailable(), true)) {
            $output->writeln(sprintf(
                '<error>phpdup: --profile must be one of %s|auto</error>',
                implode('|', $registry->listAvailable()),
            ));
            return null;
        }

        try {
            $profileData = $registry->load($profileName);
        } catch (\RuntimeException $e) {
            $output->writeln('<error>phpdup: ' . $e->getMessage() . '</error>');
            return null;
        }

        $profileOverrides = $this->profileToOverrides($profileData);
        foreach ($profileOverrides as $k => $v) {
            if (!array_key_exists($k, $overrides)) {
                $overrides[$k] = $v;
            }
        }

        $profileExclude = isset($profileData['exclude']) && is_array($profileData['exclude'])
            ? array_values(array_filter($profileData['exclude'], 'is_string'))
            : null;

        return $profileExclude;
    }

    /**
     * Run auto-tune and merge suggestions into $overrides.
     *
     * @param list<string> $paths
     * @param array<string, mixed> $overrides
     * @return bool $autoTuneExactOnly
     */
    private function resolveAutoTune(InputInterface $input, OutputInterface $output, array $paths, array &$overrides): bool
    {
        if (!$input->getOption('auto-tune')) {
            return false;
        }

        $base = Config::defaults($paths);
        $tuner = new AutoTuner();
        $suggestion = $tuner->tune($paths, $base->exclude);
        $output->writeln(sprintf(
            '<info>phpdup</info> auto-tune: %s',
            $suggestion->rationale,
        ));

        $autoTuneExactOnly = false;
        foreach ($suggestion->overrides as $k => $v) {
            if ($k === 'exact_only') {
                $autoTuneExactOnly = (bool)$v;
                continue;
            }
            if (!array_key_exists($k, $overrides)) {
                $overrides[$k] = $v;
            }
        }

        return $autoTuneExactOnly;
    }

    /**
     * @return array{useCache: bool, exactOnly: bool, showStats: bool, limit: int, cliVerbosity: string, maxMemoryMb: int, stopAfter: Stage|null, watchMode: bool, tuiMode: bool, themeName: string, reportArgs: array<string, mixed>}
     */
    private function parseRuntimeOptions(InputInterface $input, OutputInterface $output, bool $autoTuneExactOnly): array
    {
        $useCache  = !$input->getOption('no-cache');
        $exactOnly = (bool)$input->getOption('exact-only') || $autoTuneExactOnly;
        $showStats = (bool)$input->getOption('stats');
        $limit     = (int)$input->getOption('limit');
        $cliVerbosity = match (true) {
            (bool)$input->getOption('summary-only') => \Phpdup\Reporting\CliReporter::VERBOSITY_SUMMARY_ONLY,
            (bool)$input->getOption('clusters')     => \Phpdup\Reporting\CliReporter::VERBOSITY_CLUSTERS,
            default                                 => \Phpdup\Reporting\CliReporter::VERBOSITY_FULL,
        };
        $maxMemoryMb = $input->getOption('max-memory') !== null
            ? (int)$input->getOption('max-memory')
            : 0;

        $stopAfter = null;
        $stageOpt  = $input->getOption('stage');
        if ($stageOpt !== null) {
            $stopAfter = Stage::tryFrom(strtolower((string)$stageOpt));
            if ($stopAfter === null) {
                $allowed = implode('|', array_map(fn(Stage $s) => $s->value, Stage::ordered()));
                $output->writeln("<error>phpdup: --stage must be one of {$allowed}</error>");
            }
        }

        $watchMode = (bool)$input->getOption('watch');
        $tuiMode   = $this->shouldRunTui($input, $output);
        $themeName = (string)$input->getOption('theme');
        if ($tuiMode && !in_array(strtolower($themeName), TuiRunner::knownThemes(), true)) {
            $output->writeln(sprintf(
                '<error>phpdup: --theme must be one of %s</error>',
                implode('|', TuiRunner::knownThemes()),
            ));
        }

        $reportArgs = [
            'limit'          => $limit,
            'showStats'      => $showStats,
            'sarifFile'      => $input->getOption('sarif'),
            'gitlabSastFile' => $input->getOption('gitlab-sast'),
            'diffDir'        => $input->getOption('diff'),
            'patchFile'      => $input->getOption('patch'),
            'applyDir'       => $input->getOption('apply') ? 'refactored' : null,
            'applyDryRun'    => true, // F1a: --apply always behaves as dry-run (F1b will respect --dry-run flag)
            'checkstyleFile' => $input->getOption('checkstyle'),
            'csvFile'        => $input->getOption('csv'),
            'prometheusFile' => $input->getOption('prometheus'),
            'timeseriesFile' => $input->getOption('timeseries'),
            'graphvizFile'   => $input->getOption('graphviz'),
            'plantumlFile'   => $input->getOption('plantuml'),
            'refactorPatchDir' => $input->getOption('refactor-patch'),
            'refactorTestsDir' => $input->getOption('refactor-tests'),
            'cliVerbosity'   => $cliVerbosity,
            'minSafety'      => $input->getOption('min-safety') !== null ? (float)$input->getOption('min-safety') : 0.0,
            'pagerMode'      => (string)($input->getOption('pager') ?? Pager::MODE_NEVER),
        ];

        if (!in_array($reportArgs['pagerMode'], Pager::MODES, true)) {
            $output->writeln('<error>phpdup: --pager must be one of ' . implode('|', Pager::MODES) . '</error>');
        }
        if ($reportArgs['minSafety'] < 0.0 || $reportArgs['minSafety'] > 1.0) {
            $output->writeln('<error>phpdup: --min-safety must be in [0, 1]</error>');
        }

        return [
            'useCache' => $useCache,
            'exactOnly' => $exactOnly,
            'showStats' => $showStats,
            'limit' => $limit,
            'cliVerbosity' => $cliVerbosity,
            'maxMemoryMb' => $maxMemoryMb,
            'stopAfter' => $stopAfter,
            'watchMode' => $watchMode,
            'tuiMode' => $tuiMode,
            'themeName' => $themeName,
            'reportArgs' => $reportArgs,
        ];
    }

    /**
     * Build the pipeline factory closure.
     *
     * @param array{useCache: bool, exactOnly: bool, showStats: bool, maxMemoryMb: int, stopAfter: Stage|null, reportArgs: array<string, mixed>} $opts
     * @return \Closure(?ProgressListener): Pipeline
     */
    private function buildPipelineFactory(array $opts): \Closure
    {
        return static function (?ProgressListener $listener) use ($opts): Pipeline {
            return new Pipeline(
                stages: [
                    new ScanningStage($listener),
                    new PreprocessStage($opts['useCache'], $opts['showStats'], $listener, $opts['maxMemoryMb']),
                    new ClusterStage($opts['exactOnly'], $opts['maxMemoryMb'], $listener),
                    new RefactorStage($opts['useCache'], $listener),
                    new ReportStage(
                        limit:          $opts['reportArgs']['limit'],
                        showStats:      $opts['reportArgs']['showStats'],
                        sarifFile:      $opts['reportArgs']['sarifFile'],
                        gitlabSastFile: $opts['reportArgs']['gitlabSastFile'],
                        diffDir:        $opts['reportArgs']['diffDir'],
                        patchFile:      $opts['reportArgs']['patchFile'],
                        applyDir:       $opts['reportArgs']['applyDir'],
                        applyDryRun:    $opts['reportArgs']['applyDryRun'],
                        checkstyleFile: $opts['reportArgs']['checkstyleFile'],
                        csvFile:        $opts['reportArgs']['csvFile'],
                        prometheusFile: $opts['reportArgs']['prometheusFile'],
                        timeseriesFile: $opts['reportArgs']['timeseriesFile'],
                        cliVerbosity:   $opts['reportArgs']['cliVerbosity'],
                        minSafety:      $opts['reportArgs']['minSafety'],
                        graphvizFile:   $opts['reportArgs']['graphvizFile'],
                        plantumlFile:   $opts['reportArgs']['plantumlFile'],
                        pagerMode:      $opts['reportArgs']['pagerMode'],
                        refactorPatchDir: $opts['reportArgs']['refactorPatchDir'],
                        refactorTestsDir: $opts['reportArgs']['refactorTestsDir'],
                    ),
                ],
                stopAfter: $opts['stopAfter'],
                listener:  $listener,
            );
        };
    }

    /**
     * @param array{useCache: bool, exactOnly: bool, showStats: bool, limit: int, cliVerbosity: string, maxMemoryMb: int, stopAfter: Stage|null, watchMode: bool, tuiMode: bool, themeName: string, reportArgs: array<string, mixed>} $opts
     */
    private function dispatch(
        InputInterface $input,
        OutputInterface $output,
        Config $config,
        \Closure $buildPipeline,
        array $opts,
    ): int {
        $tuiRunner = new TuiRunner();

        if ($opts['tuiMode']) {
            $modelRef = null;
            $iteratorFactory = static function () use (&$modelRef, $buildPipeline, $config, $output): array {
                $state = new PipelineState($config);
                /** @var ProgressListener|null $listener */
                $listener = $modelRef;
                $gen = $buildPipeline($listener)->iter($state, $output);
                return [$gen, $state];
            };
            $modelRef = $tuiRunner->buildLiveModel($opts['themeName'], $iteratorFactory);

            if ($opts['watchMode']) {
                return $this->runWatchTui($modelRef, $tuiRunner, $config, $output);
            }
            return $tuiRunner->runWithModel($modelRef);
        }

        if ($opts['watchMode']) {
            $pipeline = $buildPipeline(null);
            $rebuild  = static fn(): PipelineState => new PipelineState($config);
            return (new WatchRunner($pipeline, $rebuild, $output))->run();
        }

        $state    = new PipelineState($config);
        SignalHandler::install($state);
        $pipeline = $buildPipeline(null);
        try {
            $pipeline->run($state, $output);
        } finally {
            SignalHandler::uninstall();
        }

        if ($state->scanError !== null) {
            $output->writeln('<error>' . $state->scanError . '</error>');
            return 2;
        }

        if ($state->cancelled) {
            $output->writeln('<comment>phpdup: cancelled by user — partial report rendered</comment>');
            return 130; // canonical SIGINT exit code
        }

        // CI gate: exit code 3 when thresholds are exceeded
        if ($config->failOnImpact > 0) {
            $totalImpact = 0;
            foreach ($state->clusters as $cluster) {
                $totalImpact += $cluster->impact;
            }
            if ($totalImpact > $config->failOnImpact) {
                return 3;
            }
        }
        if ($config->maxClusters > 0 && count($state->clusters) > $config->maxClusters) {
            return 3;
        }

        // Baseline gating: --baseline-out (write) and --baseline (compare / first-run)
        if ($config->baselineOutFile !== null || $config->baselineFile !== null) {
            $baselineStore = new BaselineStore();

            // Build current entries in baseline format
            $currentEntries = [];
            foreach ($state->clusters as $cluster) {
                $memberHashes = [];
                foreach ($cluster->members as $block) {
                    $memberHashes[] = $baselineStore->computeBlockHash($block);
                }
                sort($memberHashes);
                $currentEntries[] = [
                    'id' => $cluster->id,
                    'impact' => $cluster->impact,
                    'member_hashes' => $memberHashes,
                ];
            }

            // --baseline-out wins: always write baseline and exit 0
            if ($config->baselineOutFile !== null) {
                $baselineStore->writeBaseline($state->clusters, $config->baselineOutFile);
                $output->writeln("<info>phpdup</info> baseline written to {$config->baselineOutFile}");
                return 0;
            }

            // --baseline: compare mode
            if ($config->baselineFile !== null) {
                if (!is_file($config->baselineFile)) {
                    // First run: write baseline and exit 0
                    $baselineStore->writeBaseline($state->clusters, $config->baselineFile);
                    $output->writeln("<info>phpdup</info> baseline created at {$config->baselineFile}");
                    return 0;
                }

                // Compare against existing baseline
                $baselineEntries = $baselineStore->readBaseline($config->baselineFile);
                $newClusters = $baselineStore->compareBaselines($currentEntries, $baselineEntries);

                if ($newClusters !== []) {
                    $count = count($newClusters);
                    $output->writeln("<error>phpdup</error> {$count} new duplicate cluster(s) found since baseline — exit 4");
                    return 4;
                }
            }
        }

        return 0;
    }

    /**
     * Run the live SugarCraft dashboard with a 1.5 s poll loop that
     * snapshots file mtimes after every completed analysis pass and
     * triggers a fresh run when any file changes.
     *
     * @see WatchRunner  Plain (non-TUI) watch-mode runner used when --tui
     *                  is absent but --watch is present.
     */
    private function runWatchTui(
        PhpdupModel $model,
        TuiRunner $tuiRunner,
        Config $config,
        OutputInterface $output,
    ): int {
        $loop    = \React\EventLoop\Loop::get();
        $program = $tuiRunner->makeProgram($model, useAltScreen: true, loop: $loop);

        // Watch by polling source mtimes once the first analysis pass has emitted $state->files.
        // We rebuild the snapshot whenever the model fires a fresh analysis run.
        $snapshot = [];
        $reload   = 0;
        $loop->addPeriodicTimer(1.5, function () use ($model, &$snapshot, &$reload, $program): void {
            $files = $model->state->files;
            if ($files === [] || !$model->viewState->analysisComplete) {
                // Still working on the current run — don't double-trigger.
                return;
            }
            if ($snapshot === []) {
                $snapshot = $this->snapshotMtimes($files);
                return;
            }
            $changed = $this->pollChanges($snapshot, $files);
            if ($changed === []) return;
            $reload++;
            $program->send(new \Phpdup\Tui\Msg\RestartPipelineMsg($reload));
            $snapshot = []; // re-snapshot after the new run completes.
        });

        $program->run();
        return 0;
    }

    /**
     * @param list<string> $files
     * @return array<string,int>
     */
    private function snapshotMtimes(array $files): array
    {
        $out = [];
        foreach ($files as $f) {
            clearstatcache(true, $f);
            $m = @filemtime($f);
            if ($m !== false) {
                $out[$f] = $m;
            }
        }
        return $out;
    }

    /**
     * @param array<string,int> $previous
     * @param list<string> $current
     * @return list<string>
     */
    private function pollChanges(array &$previous, array $current): array
    {
        $changed = [];
        $known = $previous;
        foreach ($current as $f) {
            clearstatcache(true, $f);
            $m = @filemtime($f);
            if ($m === false) continue;
            if (!isset($known[$f])) {
                $changed[] = $f;       // new file
                $previous[$f] = $m;
            } elseif ($known[$f] !== $m) {
                $changed[] = $f;       // mtime changed
                $previous[$f] = $m;
            }
        }
        // detect deletions
        foreach (array_keys($previous) as $f) {
            if (!in_array($f, $current, true)) {
                $changed[] = $f;
                unset($previous[$f]);
            }
        }
        return $changed;
    }

    private function shouldRunTui(InputInterface $input, OutputInterface $_output): bool
    {
        if ($input->getOption('plain')) {
            return false;
        }
        // Phase 2: require --tui explicitly. Auto-enable on TTY is deferred until Phase 3
        // wires live progress; auto-launching today would surprise users running interactively.
        return (bool)$input->getOption('tui');
    }

    /**
     * Map a profile JSON document onto the override-dict shape that
     * ConfigLoader::load() / Config::withOverrides() understand.
     * 'exclude' is handled separately (it goes through ConfigLoader's
     * profileExclude param so excludes only kick in as a default).
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function profileToOverrides(array $data): array
    {
        $out = [];
        foreach (['min_block_size', 'max_block_size', 'normalization_mode',
                  'similarity_threshold', 'tree_threshold', 'min_cluster_impact',
                  'max_df', 'ngram_size', 'sort'] as $k) {
            if (array_key_exists($k, $data)) {
                $out[$k] = $data[$k];
            }
        }
        if (array_key_exists('kinds', $data)) {
            $out['allowed_kinds'] = $data['kinds'];
        }
        // Option 4: profiles may ship a `db_symbols` symbol-equivalence
        // pack. Map the nested shape onto the flat
        // `db_symbols_methods` / `db_symbols_functions` overrides
        // that ConfigLoader::extractDbSymbols() consumes.
        if (isset($data['db_symbols']) && is_array($data['db_symbols'])) {
            if (isset($data['db_symbols']['methods']) && is_array($data['db_symbols']['methods'])) {
                $out['db_symbols_methods'] = $data['db_symbols']['methods'];
            }
            if (isset($data['db_symbols']['functions']) && is_array($data['db_symbols']['functions'])) {
                $out['db_symbols_functions'] = $data['db_symbols']['functions'];
            }
        }
        return $out;
    }

    private function validateConfigOnly(InputInterface $input, OutputInterface $output): int
    {
        $configFile = $input->getOption('config');
        if ($configFile === null) {
            $output->writeln('<error>phpdup: --validate-config requires --config=FILE</error>');
            return 2;
        }
        if (!is_file($configFile)) {
            $output->writeln("<error>phpdup: config file not found: {$configFile}</error>");
            return 2;
        }
        $decoded = json_decode((string)file_get_contents($configFile), true);
        if (!is_array($decoded)) {
            $output->writeln("<error>phpdup: config file is not valid JSON: {$configFile}</error>");
            return 2;
        }

        try {
            (new ConfigLoader())->validate($decoded, $configFile);
        } catch (\RuntimeException $e) {
            $output->writeln('<error>phpdup: ' . $e->getMessage() . '</error>');
            return 2;
        }

        $output->writeln("<info>phpdup</info> config OK: {$configFile}");
        return 0;
    }
}
