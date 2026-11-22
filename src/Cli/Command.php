<?php
declare(strict_types=1);

namespace Phpdup\Cli;

use Phpdup\Clustering\CandidateBudgetException;
use Phpdup\Refactor\ScriptKindApplyRefusal;
use Phpdup\Pipeline\DebugLogger;
use Phpdup\Pipeline\Pipeline;
use Phpdup\Pipeline\ProgressListener;
use Phpdup\Tui\PhpdupModel;
use Phpdup\Tui\TuiRunner;
use Phpdup\Watch\FileWatcher;
use Phpdup\Watch\WatchRunner;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\Stage;
use Phpdup\Pipeline\Stages\ClusterStage;
use Phpdup\Pipeline\Stages\PreprocessStage;
use Phpdup\Pipeline\Stages\RefactorStage;
use Phpdup\Pipeline\Stages\ReportStage;
use Phpdup\Pipeline\Stages\ScanningStage;
use Phpdup\Parsing\AstCache;
use Phpdup\Reporting\BaselineStore;
use Phpdup\Scanning\FileScanner;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Exception\RuntimeException as ConsoleRuntimeException;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `phpdup analyze` entry-point.
 *
 * execute() delegates to six private methods that each own one logical phase.
 * Keeping them private enforces a strict call order and makes the flow
 * easier to follow than a long linear method:
 *
 *  1. parseCliOverrides()   — validate and collect every CLI flag into a
 *                             flat override dict; returns null on error (an
 *                             empty dict is a legal bare run, not a failure).
 *  2. resolveProfile()       — load a --profile preset and merge its values
 *                             into $overrides (only for unset keys). An
 *                             unknown name or an unloadable profile file
 *                             throws — admission failure is fatal, exit 2
 *                             (shell-S25; the returned null now means
 *                             "flag absent" only).
 *  3. resolveAutoTune()      — probe the corpus and append auto-suggested
 *                             overrides; also returns whether --exact-only
 *                             was forced by the tuner. The probe's exclude
 *                             list carries the resolved profile's AND the
 *                             root config file's excludes (exclude_reset
 *                             honored) so the band is computed on the tree
 *                             the run will actually scan (shell-S25, then
 *                             once-S26), and the merge never overwrites a
 *                             knob the user explicitly set on the CLI, via
 *                             --profile, or in the config file.
 *  4. ConfigLoader::load()   — applies the three-tier precedence chain
 *                             (overrides → config file → defaults) and
 *                             returns a frozen Config object.
 *  5. parseRuntimeOptions()  — extract options that control pipeline runtime
 *                             behaviour (cache, TUI, watch, output formats);
 *                             returns null on error (an invalid choice or a
 *                             non-numeric/out-of-domain runtime flag is a
 *                             usage rejection, exit 2, shell-S24).
 *  6. buildPipelineFactory() — build the Pipeline with resolved options.
 *  7. dispatch()             — run the pipeline directly, via TUI, or via
 *                             watch-mode, and return the process exit code.
 */
final class Command extends SymfonyCommand
{
    /**
     * once-S26: bounded-read cap for the auto-tune probe's forgiving
     * pre-read of the root --config file — deliberately the same figure as
     * ConfigLoader::MAX_CONFIG_FILE_BYTES (kept as a local mirror because
     * that constant is private and ConfigLoader is not this lane's file).
     */
    private const TUNER_CONFIG_READ_CAP = 1_048_576;

    /**
     * Symfony's binding failure for "a value-taking option was named but no
     * value was read" — the one argv-tokenization error phpdup owns (see
     * {@see self::run()}). The wording is byte-stable across the supported
     * symfony/console range (^6.4||^7.0); the capture group is the flag as
     * the user typed it, long (`--limit`) or short (`-j`) form.
     */
    private const MISSING_VALUE_PATTERN = '/^The "(?<flag>-+[^"]+)" option requires a value\.$/';

    /**
     * shell-S27 TEST SEAM (see {@see self::doorConfigLoader()}): installed
     * by the unit that must drive the admission door with a prepared
     * loader — the ctor-TypeError-as-usage-error pin. Production never
     * passes it; every real load stays a fresh stateless ConfigLoader.
     */
    public function __construct(
        private readonly ?ConfigLoader $doorLoaderForTests = null,
    ) {
        parent::__construct();
    }

    /**
     * The loader behind every read-admission door in execute() and
     * --validate-config (shell-S27). null seam ⇒ `new ConfigLoader()` per
     * load, identical to the pre-seam posture at all three call sites.
     */
    private function doorConfigLoader(): ConfigLoader
    {
        return $this->doorLoaderForTests ?? new ConfigLoader();
    }

    #[\Override]
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
            ->addOption('candidate-budget', null, InputOption::VALUE_REQUIRED, 'Safety cap on candidate pairs emitted during clustering. A pathological clone family
                halts loudly instead of exhausting memory. Default 60,000,000; 0 = unlimited (opts out of the guard).')
            ->addOption('optional-blocks', null, InputOption::VALUE_REQUIRED, 'Type-3 / "optional segment" detection: on|off (default on). When on, blocks whose statements differ in length but share a common skeleton cluster together with bool $include* params for the absent-from-some-members segments.')
            ->addOption('optional-blocks-containment', null, InputOption::VALUE_REQUIRED, 'Containment threshold (0..1) for the type-3 fallback path. Default 0.85.')
            ->addOption('min-impact', null, InputOption::VALUE_REQUIRED, 'Minimum cluster impact to report')
            ->addOption('exact-only', null, InputOption::VALUE_NONE, 'Skip near-duplicate detection (faster)')
            ->addOption('kinds', null, InputOption::VALUE_REQUIRED, 'Comma-separated block kinds to include (e.g. method,closure). Default: all of
                method|function|closure|arrow|if|for|foreach|while|do|try|switch|match. The fold kind script
                (top-level statements) is NOT in that default set — it must be named explicitly, and it currently
                cannot combine with --apply/--refactor-patch (script clusters are refused by the AST-addressing
                machinery, typed exit 1; text rendering via --patch/--diff stays allowed).')
            ->addOption('auto-tune', null, InputOption::VALUE_NONE, 'Probe the corpus before analysis and pick min-block-size / max-df / min-impact based on size; --exact-only is forced on for very large trees. The probe counts the tree the run will actually scan (profile and --config excludes applied, exclude_reset honored). Suggestions fill gaps only: a knob explicitly set on the command line, by --profile, or in the --config file is never overwritten, and the printed rationale claims only what was applied.')
            ->addOption('profile', null, InputOption::VALUE_REQUIRED, 'Apply a project profile (' . implode('|', ProfileRegistry::bundled()->listAvailable()) . '|auto) to seed framework-aware excludes + tuning. db-aware-* profiles ship symbol-equivalence packs for various ORMs. "auto" sniffs the scan path for known markers. Explicit CLI flags + --config win over profile values.')
            ->addOption('min-safety', null, InputOption::VALUE_REQUIRED, 'Drop clusters whose refactor-safety score (0..1) is below this threshold. 0 = report all (default).')
            ->addOption('score-mode', null, InputOption::VALUE_REQUIRED, 'Scoring stance for cross-repo clones: global|per-repo|typed (default global).
                global = legacy math, byte-identical to every stored baseline. per-repo = the >3-namespaces
                penalty in confidence/safety is evaluated on the namespace spread within the densest member
                repo, so a subsystem mirrored across packages is no longer double-penalized for its mirroring.
                typed = audit posture: cross-repo clusters skip that penalty and gain the inverse bonus (a
                cross-repo clone is the strongest dedup signal: one shared helper erases N repo-local copies).
                Only clusters with >=2 distinct member repo labels behave differently; everything else scores
                as before. Equivalently: config key score_mode.')
            ->addOption('max-hole-coverage', null, InputOption::VALUE_REQUIRED, 'Extraction-quality stance: fraction 0..1 (default 1.0 = rule never fires,
                today\'s behavior byte-identical). When a cluster\'s generalized template is MORE than this
                fraction hole, the report marks it re_expressed and surfaces its longest hole-free statement
                run (hole_fragment) instead of dressing the wreck up as a clean function. Evidence is
                annotated, never dropped. Equivalently: config key max_hole_coverage (root-only).')
            ->addOption('ted-weights', null, InputOption::VALUE_REQUIRED, 'Tree-edit cost model: default|semantic. semantic weights method calls / control flow heavier than literals.')
            ->addOption('db-aware', null, InputOption::VALUE_NONE, 'ORM-/DB-aware semantic dedup: rewrite recognised database calls (Eloquent,
                Doctrine, PDO, mysqli, pg_*, raw SQL) to canonical __DB_<OP>__ tokens during
                normalisation so equivalent ORM and raw-SQL variants cluster together. Off by
                default — opt-in for ORM-heavy codebases.')
            ->addOption('trinity-collapse', null, InputOption::VALUE_NONE, 'Detect the canonical CRUD trinity (read → mutate → save) and rewrite the
                three-statement sequence as a single __DB_UPSERT__("entity") synthetic call so ORM
                upserts cluster with raw UPDATE queries. Composes with --db-aware. Off by default.')
            ->addOption('scorer', null, InputOption::VALUE_REQUIRED, 'Scoring tier set: default | ir. "ir" enables the IR-tier fallback — when AST
                Jaccard / TED / containment all reject a pair, lift both blocks to the canonical IR
                (Phpdup\\Ir\\IrLifter) and Jaccard their token bags. Pairs at or above --ir-threshold
                form edges weighted by the IR similarity. When the flag is absent the config-file /
                built-in scorer applies.')
            ->addOption('ir-threshold', null, InputOption::VALUE_REQUIRED, 'IR-tier multiset-Jaccard threshold (0..1). Pairs at or above this score form edges. Default 0.85.')
            ->addOption('ml-pair-url', null, InputOption::VALUE_REQUIRED, 'External pair-similarity ML sidecar URL. Empty = disabled. When set, the
                very last clustering tier — runs after structural-hash, AST Jaccard + TED,
                containment, and IR all reject a pair. Posts a PairFeatures vector to
                <URL>/score-pair and accepts pairs at or above --ml-pair-threshold. Returns null
                on transport failure so unavailability never breaks the run. http(s) only.')
            ->addOption('ml-pair-threshold', null, InputOption::VALUE_REQUIRED, 'Similarity threshold (0..1) for the ML pair tier. Pairs whose model-returned
                similarity meets this value emit edges. Default 0.80.')
            ->addOption('fail-on-impact', null, InputOption::VALUE_REQUIRED, 'Exit code 3 when any single cluster impact is >= N (canonical per-cluster maximum formula; the shipped GitHub action fail-on-impact input enforces the same rule). 0 = disabled.')
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
            ->addOption('dry-run', null, InputOption::VALUE_OPTIONAL, 'With --apply: preview the refactor patch instead of rewriting member files. Bare --dry-run or the default (true) keeps the preview posture (refactored/apply.diff, sources untouched); --dry-run=false runs the real apply path (member files rewritten, refactored/apply-summary.md lands instead). A value outside the boolean shapes true|false|1|0|yes|no|on|off is rejected with exit 2. Inert without an apply posture (--apply or config "apply": true).', true)
            ->addOption('baseline', null, InputOption::VALUE_REQUIRED, 'CI baseline file. If file exists: compare and exit 4 if new clusters found. If file does not exist: write baseline and exit 0 (first-run auto-baseline).')
            ->addOption('baseline-out', null, InputOption::VALUE_REQUIRED, 'Write current clusters as a baseline snapshot to FILE (overwrites existing).')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Show at most N clusters in CLI output: 0 prints NO detail cards (unlike the --candidate-budget / --max-clusters family it is NOT the 0=unlimited sentinel — pass a large N to see every cluster), file sinks such as --json/--html always carry every cluster regardless of N, and a negative or non-numeric N is rejected with exit 2', 50)
            ->addOption('summary-only', null, InputOption::VALUE_NONE, 'Render only the top banner + final summary line (skip per-cluster details)')
            ->addOption('clusters', null, InputOption::VALUE_NONE, 'Render a one-line-per-cluster table instead of the full per-cluster breakdown')
            ->addOption('scope', null, InputOption::VALUE_REQUIRED, 'Report only clusters matching a repo scope: all|single|multi|<repo label>
                (default all). Clusters carry per-member repo labels derived from the scan roots;
                single = one repo per cluster, multi = members span >=2 distinct repos, any other
                value = keep clusters with at least one member from that repo label. Applied after
                ranking, so --limit/--sort and the CI gates all measure the filtered slice.
                Equivalently: config key scope.')
            ->addOption('pager', null, InputOption::VALUE_REQUIRED, 'Page CLI report through $PAGER (default `less -R`). Modes: auto|never|always. auto pages when stdout is a TTY and the rendered output exceeds ~60 lines.', 'never')
            ->addOption('sort', null, InputOption::VALUE_REQUIRED, 'Cluster sort: KEY[:asc|desc]. Keys: impact|members|block-size|lines|similarity|confidence|name|file|id. Aliases: size→members, count→members. Default impact:desc.')
            ->addOption('stats', null, InputOption::VALUE_NONE, 'Show pipeline statistics')
            ->addOption('debug-log', null, InputOption::VALUE_REQUIRED, 'Append all debug (vvv) messages to FILE');

        // ── Performance / runtime ──────────────────────────────────────────
        $this
            ->addOption('workers', 'j', InputOption::VALUE_REQUIRED, 'Worker count for parallel preprocess + pair scoring. 0 = auto-detect CPU count (portable probe chain, PHPDUP_WORKERS seeds it); 1 = serial. Above the pool ceiling (4 x detected CPUs) the run is admitted with a warning and the pool clamps.')
            ->addOption('no-cache', null, InputOption::VALUE_NONE, 'Disable the per-file disk caches for this run: the AST parser blob cache is neither read NOR written (fresh parse), and the per-file index (IndexStore) is not reused. The cluster cache (clusters.* under cache_dir) is governed by --no-incremental, not by this flag.')
            ->addOption('no-incremental', null, InputOption::VALUE_NONE, 'Disable per-file index reuse')
            ->addOption('no-lazy-ast', null, InputOption::VALUE_NONE, 'Keep all original ASTs in memory (higher RSS, faster anti-unification)')
            ->addOption('max-memory', null, InputOption::VALUE_REQUIRED, 'Soft memory ceiling in MB. When peak RSS exceeds this mid-pipeline, phpdup logs a warning and suggests --exact-only.')
            ->addOption('low-memory', null, InputOption::VALUE_NONE, 'Reduce memory footprint: use CompactNgramBag (32-bit fingerprint) and CanonicalNodePool interning for lower RSS at the cost of some speed.')
            ->addOption('stage', null, InputOption::VALUE_REQUIRED, 'Run pipeline only up to STAGE (scanning|preprocessing|clustering|refactoring|reporting); useful for incremental debugging')
            ->addOption('diff-base', null, InputOption::VALUE_REQUIRED, 'Git ref for diff-scoped analysis. When set, phpdup scans only files changed in `git diff --name-only <ref>..HEAD` plus their clone cohort (files sharing n-gram fingerprints with the changed files).')
            ->addOption('trust-in-root-cache', null, InputOption::VALUE_NONE, 'Read the on-disk AST cache even when cache_dir resolves inside a scan root. Off by default: a committed cache inside a cloned tree can be a planted decoy, so an untrusted run refuses those reads (reports real code, one cold pass) and prints a notice. Opt in only for repositories you control.');

        // ── Interactive / UI ───────────────────────────────────────────────
        $this
            ->addOption('tui', null, InputOption::VALUE_NONE, 'Show interactive SugarCraft dashboard after analysis completes')
            ->addOption('plain', null, InputOption::VALUE_NONE, 'Force plain CLI output (no TUI, no ANSI colours)')
            ->addOption('theme', null, InputOption::VALUE_REQUIRED, 'TUI theme: ansi|plain|charm|dracula|nord|catppuccin', 'ansi')
            ->addOption('watch', null, InputOption::VALUE_NONE, 'Stay running and re-analyze on file changes (Ctrl+C to exit)');
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
    --min-block-size, --mode, --similarity, --max-df, --candidate-budget,
    --optional-blocks, --optional-blocks-containment,
    --min-impact, --min-safety, --fail-on-impact, --max-clusters,
    --score-mode, --max-hole-coverage,
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
    --summary-only, --clusters, --scope, --pager, --debug-log

  <comment>CI / Baseline</comment>
    --baseline, --baseline-out

  <comment>Performance / runtime</comment>
    --workers (-j), --no-cache, --no-incremental, --no-lazy-ast,
    --max-memory, --low-memory, --stage, --diff-base,
    --trust-in-root-cache

 <comment>Interactive / UI</comment>
   --tui, --plain, --theme, --watch

Run <info>phpdup --help</info> for full descriptions of each option.
HELP;
    }

    /**
     * Bind argv BEFORE the parent's run(), so the one tokenizer error phpdup
     * owns becomes a phpdup-shaped admission refusal instead of a Symfony
     * usage dump.
     *
     * once-S20 (php-dup-finder-once-023, closing once-S49's banked follow-on):
     * the exit-code contract says a rejected option prints one clean
     * `phpdup:` line and exits 2 — the fence shell-S24 built lives in
     * execute(), which the tokenizer can beat. `--limit -1` never reaches
     * that fence: a token starting with "-" is read as the NEXT option, so
     * `--limit` looks value-less, binding throws inside Command::run(), and
     * the run dies in Symfony's boxed usage text at exit 1 — while the very
     * same request written `--limit=-1` passes through the boundary and gets
     * the friendly `limit must be >= 0` exit 2. One intent, two doors.
     *
     * This leg translates exactly the missing-value class and nothing else.
     * Unknown options (`--nope` → "does not exist") stay on Symfony's exit 1:
     * docs/CLI.md's exit-2 row deliberately does not cover them and
     * tests/Integration/CliSmokeTest pins that posture until the docs flip.
     * Re-raising every other binding error untouched keeps this door from
     * silently rerouting a class the contract does not promise at 2.
     *
     * The pre-bind is idempotent (Input::bind resets and re-parses from the
     * retained argv tokens), so parent::run() re-binds the same definition
     * and proceeds unchanged; analyze never sets ignoreValidationErrors(),
     * which would otherwise skip Symfony's own validation pass.
     */
    #[\Override]
    public function run(InputInterface $input, OutputInterface $output): int
    {
        $this->mergeApplicationDefinition();

        // shell-S28 (php-dup-finder-shell-039): --dry-run is VALUE_OPTIONAL so
        // both the bare flag and --dry-run=false reach execute(); a
        // VALUE_OPTIONAL token written bare would otherwise SWALLOW the next
        // argv token (ArgvInput probes it as the value whenever none was
        // attached), so `phpdup analyze --dry-run src` would lose src and die
        // at the path door. Attaching the value BEFORE the first bind turns
        // the bare token into its explicit form; '--dry-run=' (empty value,
        // same consumption hazard) lands in the same shape. Everything past
        // a '--' end-of-options marker is an argument token and stays typed.
        if ($input instanceof \Symfony\Component\Console\Input\ArgvInput) {
            $this->attachBareDryRunValue($input);
        }

        try {
            $input->bind($this->getDefinition());
        } catch (ConsoleRuntimeException $e) {
            $flag = $this->missingValueFlag($e->getMessage());
            if ($flag === null) {
                throw $e; // not the missing-value class — Symfony's door stands
            }

            $output->writeln(sprintf(
                '<error>phpdup: The "%s" option requires a value. A value that begins with "-" is only admitted attached to the flag, e.g. %s=<value></error>',
                $flag,
                $flag,
            ));

            return 2;
        }

        return parent::run($input, $output);
    }

    /**
     * The flag behind a "requires a value" binding failure, or null when the
     * message is any other console error. Narrow on purpose — see run().
     */
    private function missingValueFlag(string $message): ?string
    {
        return preg_match(self::MISSING_VALUE_PATTERN, $message, $matches) === 1
            ? $matches['flag']
            : null;
    }

    /**
     * Rewrite bare `--dry-run` / empty-value `--dry-run=` argv tokens into
     * the explicit `--dry-run=true` form. See run() for the WHY: a
     * VALUE_OPTIONAL token without an attached value peeks at the next
     * token, which would turn `analyze --dry-run src/` into dry-run='src/'.
     * Tokens after the '--' end-of-options marker are argument values and
     * are left exactly as typed.
     */
    private function attachBareDryRunValue(\Symfony\Component\Console\Input\ArgvInput $input): void
    {
        $tokens = $input->getRawTokens();
        $changed = false;
        foreach ($tokens as $i => $token) {
            if ($token === '--') {
                break;
            }
            if ($token === '--dry-run' || $token === '--dry-run=') {
                $tokens[$i] = '--dry-run=true';
                $changed = true;
            }
        }
        if (!$changed) {
            return;
        }

        // setTokens() is protected in the vendored Symfony line; Input::bind()
        // re-runs parse() over the `tokens` property on EVERY bind, and this
        // command binds (run() pre-bind + parent rebind) strictly after this
        // point, so writing the property pre-bind is the whole contract. If a
        // future Symfony renames the property the write fails loudly instead
        // of silently skipping normalization — and the bare-flag subprocess
        // pin in CliDefaultShadowingTest turns red on any drift (an
        // attached-value-less --dry-run would eat the next token there).
        $property = new \ReflectionProperty($input, 'tokens');
        $property->setAccessible(true);
        $property->setValue($input, $tokens);
    }

    #[\Override]
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

        // shell-S28 (once-S31-r1 F-2, routed through shell-S27-r1 §(b)):
        // absolutize the argv scan roots at this boundary — every consumer
        // below (profile auto-detect, auto-tune probe, Config load, diff-base
        // cohort, cache-trust gate, FileScanner walk) then walks the real
        // filesystem, and the shipped-artifact silent-zero dies at its root
        // instead of at each stat site. See the method for the phar story.
        $paths = $this->absoluteArgvScanRoots($paths);

        $overrides = $this->parseCliOverrides($input, $output);
        if ($overrides === null) {
            return 2; // parseCliOverrides emitted the specific error and rejected the input
        }

        // shell-S25 (php-dup-finder-shell-012): profile admission now shares
        // the parseCliOverrides failure door — a rejected --profile throws
        // out of resolveProfile and lands in the exit-2 catch below, never
        // degrading into a silent default-settings run with exit 0. The
        // auto-tune probe sits in the same door: its band must see the
        // profile's exclude list (php-dup-finder-shell-043), and every
        // admission failure short-circuits before the pipeline.
        try {
            $profileExclude = $this->resolveProfile($input, $output, $paths, $overrides);

            $autoTuneExactOnly = $this->resolveAutoTune($input, $output, $paths, $overrides, $profileExclude);

            $config = $this->doorConfigLoader()->load(
                paths: $paths,
                configFile: $input->getOption('config'),
                overrides: $overrides,
                profileExclude: $profileExclude,
            );
        } catch (\RuntimeException | \InvalidArgumentException | \TypeError $e) {
            // Invalid config, un-coercible numeric flag values, and rejected
            // --profile values surface as the conventional friendly exit 2,
            // never as a raw stack trace.
            //
            // shell-S27 (php-dup-finder-shell-015) widens this arm to
            // \TypeError as a CLASS rule: any TypeError surfacing from the
            // Config construction behind this door is an admission-shape
            // failure — pre-leg, a root config carrying "apply": "yes"
            // reached `new Config(...)` unvalidated and died at exit 255
            // with the raw arg-#44 message. A tests/ census shows zero
            // legitimate runtime TypeErrors expected to crash raw here
            // (every "TypeError" hit in tests/ asserts its ABSENCE), so
            // translating the class loses no debuggability: the friendly
            // line still carries PHP's full ctor message verbatim.
            $output->writeln('<error>phpdup: ' . $e->getMessage() . '</error>');
            return 2;
        }

        // once-S69 (php-dup-finder-once-079): admit an unwritable --debug-log
        // path here — before the pipeline constructs a DebugLogger and before
        // any analysis work starts. Old posture: the run continued, append()
        // retried fopen per debug message (warning spam), and shutdown could
        // still die. Now: one clear error line, usage exit 2, nothing opened.
        if ($config->debugLog !== null) {
            $debugLogError = DebugLogger::admissionProbe($config->debugLog);
            if ($debugLogError !== null) {
                $output->writeln('<error>phpdup: --debug-log path is not writable: '
                    . $config->debugLog . ' (' . $debugLogError . ')</error>');
                return 2;
            }
        }

        // once-S34 (php-dup-finder-once-038): resolve the --diff-base cohort
        // HERE, before the pipeline exists — named roots, repo-relative git,
        // unicode paths, honest empty-cohort refusal. Old posture: the
        // stage-level probe joined repo-relative git paths under the scan
        // root (only correct for toplevel roots), so a subdir/file root
        // silently collected nothing and ClusterStage's "no changes" rule
        // turned the gate premise into a FULL unscoped scan. Now:
        //  • FileScanner::resolveDiffCohort maps git output against the
        //    real toplevel (git -C … rev-parse --show-toplevel), filters to
        //    the named roots, and runs diff with -c core.quotePath=false so
        //    non-ASCII changed paths match disk (XP-03);
        //  • a git refusal exits 2 here, before any discovery (same shell-
        //    S23 early-abort posture, one probe earlier, message wording
        //    preserved);
        //  • an empty cohort — the misconfiguration case the finding was
        //    about — exits 2 with a message instead of the silent fallback;
        //  • a resolved cohort is threaded to dispatch and pre-set on every
        //    PipelineState, while the pipeline's OWN config re-loads with
        //    diff_base explicitly null: ScanningStage probes only when that
        //    key is set, so the legacy join never double-probes and never
        //    prints a count its broken mapping discarded. The single
        //    truthful count line below is emitted by this admission, in the
        //    shell-S23 message shape (same position in the output order).
        $diffCohort = null;
        $pipelineConfig = $config;
        if ($config->diffBase !== null) {
            $cohort = FileScanner::resolveDiffCohort($config->paths, $config->diffBase);
            if ($cohort['error'] !== null) {
                // shell-S34 (php-dup-finder-shell-025): the error string
                // embeds the probed directory name and git's raw stderr —
                // untrusted bytes, markup-escaped before they touch the
                // <error> line.
                $output->writeln('<error>' . self::escapeMarkup($cohort['error']) . '</error>');
                return 2;
            }
            if ($cohort['paths'] === []) {
                $output->writeln(sprintf(
                    '<error>phpdup: --diff-base %s: no changed files match the named scan root(s); refusing the unscoped full scan%s</error>',
                    // shell-S34: the refusal echoes the raw argv ref.
                    self::escapeMarkup($config->diffBase),
                    $cohort['unmatched'] === []
                        ? ''
                        : ' — ' . count($cohort['unmatched']) . ' changed path(s) in range are not on disk under the roots',
                ));
                return 2;
            }
            $diffCohort = $cohort['paths'];
            // shell-S34: the count echo carries the raw ref twice.
            $output->writeln(sprintf(
                "<info>phpdup</info> diff-base %s: %d changed file(s) from git diff --name-only %s..HEAD",
                self::escapeMarkup($config->diffBase),
                count($diffCohort),
                self::escapeMarkup($config->diffBase),
            ));
            if ($cohort['unmatched'] !== []) {
                // XP-03's "report unmatched" leg: changed-but-gone paths
                // under a named root are disclosed, never silently dropped.
                // shell-S34: every disclosed path is a raw git output byte.
                $output->writeln(sprintf(
                    '<comment>phpdup: --diff-base: %d changed path(s) under the named root(s) are no longer on disk (deleted or renamed away); excluded from the cohort: %s</comment>',
                    count($cohort['unmatched']),
                    implode(', ', array_map(static fn (string $p): string => self::escapeMarkup($p), $cohort['unmatched'])),
                ));
            }
            // Re-load with an explicit null so BOTH sources of diff_base
            // (CLI flag and config file — OverrideResolver checks the
            // overrides key first) vacate the pipeline config. Admission
            // already consumed the value; the pipeline must not re-derive
            // the cohort through the legacy mapping.
            $tryConfig    = $overrides;
            $tryConfig['diff_base'] = null;
            try {
                $pipelineConfig = $this->doorConfigLoader()->load(
                    paths: $paths,
                    configFile: $input->getOption('config'),
                    overrides: $tryConfig,
                    profileExclude: $profileExclude,
                );
            } catch (\RuntimeException | \InvalidArgumentException | \TypeError $e) {
                // Same friendly exit-2 posture as the first load, the same
                // shell-S27 \TypeError class arm; in practice nothing can
                // newly fail here — the inputs differ only in the key this
                // block just consumed.
                $output->writeln('<error>phpdup: ' . $e->getMessage() . '</error>');
                return 2;
            }
        }

        // shell-S27 (php-dup-finder-shell-053): the loaded Config already
        // folds flag OR file — parseCliOverrides writes overrides['apply']
        // only when --apply is present, and OverrideResolver precedence
        // makes an override beat the file. Passing the folded value keeps
        // the render door and the Config looking at one truth; the raw
        // flag alone used to leave a config-driven apply inert.
        $runtimeOptions = $this->parseRuntimeOptions(
            $input,
            $output,
            $autoTuneExactOnly,
            applyMode: $pipelineConfig->apply,
        );
        if ($runtimeOptions === null) {
            // shell-S24 (php-dup-finder-shell-013/014): parseRuntimeOptions
            // emitted the specific error and rejected the input — the run
            // ends here, before the pipeline is ever built. The old posture
            // printed the error line and carried on to a FULL analysis with
            // exit 0, so option mistakes were un-runnable-to and un-CI-able.
            return 2;
        }

        // buildPipelineFactory only needs a subset of runtimeOptions
        $pipelineOpts = [
            'useCache'   => $runtimeOptions['useCache'],
            'exactOnly'  => $runtimeOptions['exactOnly'],
            'showStats'  => $runtimeOptions['showStats'],
            'maxMemoryMb' => $runtimeOptions['maxMemoryMb'],
            'stopAfter'  => $runtimeOptions['stopAfter'],
            'reportArgs' => $runtimeOptions['reportArgs'],
        ];
        // once-S06 (php-dup-finder-once-006): a TUI run NEVER pages. The
        // pager branch in ReportStage proc_opens $PAGER with its stdout on
        // the PROCESS's own STDOUT — the very terminal sugar-core takes
        // over with the alt screen — so `--tui --pager=auto|always` used to
        // drop `less` inside the running dashboard (once-006's pager half,
        // raised with the report-body half by TUI-01/U-02). Admission still
        // validates the raw --pager value (shell-S24's refusal door and the
        // render-only pin in CliDefaultShadowingTest stay true to the
        // flag); the suppression lands here, on the factory's copy, so the
        // CLI/watch paths keep the user's mode. This Command seam is the
        // pager-suppression entry once-S76's Pager-side no-TTY posture
        // builds on.
        if ($runtimeOptions['tuiMode']) {
            $pipelineOpts['reportArgs']['pagerMode'] = Pager::MODE_NEVER;
        }
        $buildPipeline = $this->buildPipelineFactory($pipelineOpts);

        // engine-006 read-path trust gate. The default cache_dir is the
        // relative `.phpdup-cache`, so a normal run stores blobs INSIDE the
        // scanned tree. Cloning an untrusted repo and running `analyze .`
        // would then serve the repo author's committed decoy blob as if it
        // were freshly parsed code. Unless the operator opts in with
        // --trust-in-root-cache, refuse every disk-cache read for such a
        // run: the analysis is simply cold (real source is re-parsed), and
        // a planted cache can no longer fabricate a duplicate. Armed BEFORE
        // dispatch so the pcntl_fork workers inherit the flag (true fork);
        // the serial fallback reads the same process-wide state. Writes stay
        // untouched — a cold read repopulates the correct blob for next time.
        $trustInRootCache = (bool)$input->getOption('trust-in-root-cache');
        $cacheInUse = $runtimeOptions['useCache'] && $pipelineConfig->cacheDir !== '';
        $refuseInRootCacheReads = $cacheInUse
            && !$trustInRootCache
            && $this->cacheResidesInScanRoot($pipelineConfig->cacheDir, $pipelineConfig->paths);
        AstCache::refuseInRootCacheReads($refuseInRootCacheReads);
        if ($refuseInRootCacheReads) {
            $output->writeln(sprintf(
                '<comment>phpdup: ignoring the AST cache in %s because it lives inside the scanned tree (a committed cache there could be a planted decoy). This run is cold; pass --trust-in-root-cache only for repositories you trust.</comment>',
                $pipelineConfig->cacheDir,
            ));
        }

        return $this->dispatch($input, $output, $pipelineConfig, $buildPipeline, $runtimeOptions, $diffCohort);
    }

    /**
     * True when $cacheDir resolves to a location inside (or equal to) any of
     * the scan roots in $paths. Both sides are reduced to a lexical absolute
     * path — realpath() when the target exists so symlinks collapse, and a
     * segment walk when it does not (a cold cache dir is created later, by
     * the AstCache constructor inside the workers) — before the prefix test.
     *
     * @param list<string> $paths
     */
    private function cacheResidesInScanRoot(string $cacheDir, array $paths): bool
    {
        $cwd = getcwd();
        if ($cwd === false) {
            return false; // cannot resolve the relative default; fail open (no refusal)
        }
        $cacheAbs = self::toAbsolutePath($cacheDir, $cwd);
        foreach ($paths as $root) {
            if ($root === '') {
                continue;
            }
            $rootAbs = self::toAbsolutePath($root, $cwd);
            $prefix = $rootAbs === '/' ? '/' : $rootAbs . '/';
            if ($cacheAbs === $rootAbs || str_starts_with($cacheAbs, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Resolve $path against $cwd to a trailing-slash-free absolute path,
     * preferring realpath (existing paths, symlinks collapsed) and falling
     * back to a pure lexical walk that only strips `.` / `..` / empty
     * segments — never touching the filesystem for a not-yet-created dir.
     */
    private static function toAbsolutePath(string $path, string $cwd): string
    {
        $real = @realpath($path);
        if ($real !== false) {
            return $real;
        }
        return self::lexicalJoin($path, $cwd);
    }

    /**
     * shell-S28 (once-S31-r1 F-2 / shell-S27-r1 §(b)): absolutize every argv
     * scan root at the Command boundary so a relative root resolves against
     * the invocation CWD — never the phar archive root. Inside an executed
     * phar, PHP's stream layer resolves RELATIVE file calls against the
     * archive regardless of Phar::interceptFileFuncs() (once-S31's micro-phar
     * evidence: is_dir('dup') false, is_dir('.') false, is_dir('/abs/dup')
     * true, getcwd() normal), so the README-documented shipped form
     * `phpdup analyze <rel>/` silently printed "1 path(s) → 0 files" at exit
     * 0 while the checkout analyzed the same pair. Anchoring the spellings
     * on getcwd() at admission fixes it at the root for EVERY consumer
     * (profile auto-detect, auto-tune probe, Config load, diff-base cohort,
     * cache-trust gate, FileScanner walk) without touching the walk itself:
     * once-S28's prune/re-root + skipNotes contract and once-S87's
     * disk-identity dedupe receive already-absolute paths and stay exactly
     * as written. Per-directory config discovery is unaffected by design —
     * its keys are realpath() answers (ConfigLoader) and Config::effectiveFor
     * realpath-matches — relative vs absolute argv cannot shift which
     * .phpdup.json wins (once-S27-r1 §(b) probe + this lane's pin).
     *
     * A phar:// answer from realpath() (if the wrapper ever resolves a
     * relative spelling INSIDE the archive) is re-anchored on the invocation
     * cwd by lexical join — an archive path is useless to FileScanner.
     * An empty-string element passes through untouched: it is falsy at the
     * admission door above only in list position, and every existing
     * consumer treats '' as a no-op root (cacheResidesInScanRoot skips it).
     *
     * @param list<string> $paths argv path spellings as typed
     * @return list<string> absolute real-filesystem roots
     */
    private function absoluteArgvScanRoots(array $paths): array
    {
        $cwd = getcwd();
        if ($cwd === false) {
            return $paths; // cannot anchor — keep the typed spellings (same fail-open posture as the cache-trust gate)
        }

        $roots = [];
        foreach ($paths as $root) {
            if ($root === '') {
                $roots[] = $root;
                continue;
            }
            $abs = self::toAbsolutePath($root, $cwd);
            if (str_starts_with($abs, 'phar://')) {
                $abs = self::lexicalJoin($root, $cwd);
            }
            $roots[] = $abs;
        }
        return $roots;
    }

    /**
     * shell-S34 (php-dup-finder-shell-025): $bytes as literal console text.
     * Symfony's formatter consumes `<...>` inside any writeln() line, so
     * raw path/ref/message bytes embedded mid-line are markup — and
     * injection — material (SEC proved `<fg=red>` swallowing). This is the
     * Command-side embodiment of the OutputInterface::escape convention the
     * step makes law for path bytes: OutputFormatter::escape() carries
     * Symfony's round-trip guarantee (the formatter prints the result
     * byte-for-byte as the original value, trailing-backslash cases
     * included), and innocuous values pass through unchanged, so the
     * shell-S28/once-S15 render pins stay byte-exact.
     */
    private static function escapeMarkup(string $bytes): string
    {
        return OutputFormatter::escape($bytes);
    }

    /**
     * Lexical half of {@see self::toAbsolutePath()}: join $path on $cwd and
     * strip `.` / `..` / empty segments without touching the filesystem.
     */
    private static function lexicalJoin(string $path, string $cwd): string
    {
        $absolute = $path !== '' && $path[0] === '/' ? $path : $cwd . '/' . $path;
        $segments = [];
        foreach (explode('/', $absolute) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }
        return '/' . implode('/', $segments);
    }

    /**
     * Parse all CLI flags into a flat override dict.
     * Returns null on error (after emitting diagnostics); a legitimately
     * bare invocation returns [] — empty NO LONGER means error (NN1 made
     * that possible by removing --scorer's shadowing Symfony default).
     *
     * @return array<string, mixed>|null
     */
    private function parseCliOverrides(InputInterface $input, OutputInterface $output): ?array
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
            'candidate_budget'            => $input->getOption('candidate-budget'),
            'max_hole_coverage'           => $input->getOption('max-hole-coverage'),
            'optional_blocks_containment' => $input->getOption('optional-blocks-containment'),
            'sort'                        => $input->getOption('sort'),
            'ted_weights'                 => $input->getOption('ted-weights'),
            'debug_log'                   => $input->getOption('debug-log'),
            'baseline'                    => $input->getOption('baseline'),
            'baseline_out'                => $input->getOption('baseline-out'),
            'diff_base'                   => $input->getOption('diff-base'),
            'scope'                       => $input->getOption('scope'),
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
                return null;
            }
            $overrides['scorer'] = $scorerOpt;
        }

        $scoreModeOpt = $input->getOption('score-mode');
        if ($scoreModeOpt !== null) {
            $scoreModeOpt = strtolower((string)$scoreModeOpt);
            if (!in_array($scoreModeOpt, \Phpdup\Cli\Config::SCORE_MODES, true)) {
                $output->writeln(sprintf(
                    '<error>phpdup: --score-mode must be one of %s</error>',
                    implode('|', \Phpdup\Cli\Config::SCORE_MODES),
                ));
                return null;
            }
            $overrides['score_mode'] = $scoreModeOpt;
        }

        $scopeOpt = $input->getOption('scope');
        if ($scopeOpt !== null && trim((string)$scopeOpt) === '') {
            // Open-set value (all|single|multi|<repo label>) — only the
            // empty string is a shape error the CLI can see.
            $output->writeln('<error>phpdup: --scope must be a non-empty value (all|single|multi|&lt;repo-label&gt;)</error>');
            return null;
        }

        $irThresholdOpt = $input->getOption('ir-threshold');
        if ($irThresholdOpt !== null) {
            // Pass the raw CLI string through: ConfigLoader::coerceNumerics()
            // owns the cast AND the [0,1] domain for ir_threshold. A blind
            // (float) cast here would launder garbage ('hot') into 0.0 and
            // silently exit 0, bypassing the boundary entirely.
            $overrides['ir_threshold'] = $irThresholdOpt;
        }

        $mlPairUrlOpt = $input->getOption('ml-pair-url');
        if ($mlPairUrlOpt !== null) {
            $url = (string)$mlPairUrlOpt;
            if ($url !== ''
                && !\Phpdup\Ml\MlClient::isAllowedUrl(rtrim($url, '/') . '/score-pair')
            ) {
                $output->writeln('<error>phpdup: --ml-pair-url must be an http(s) URL with a non-empty host (and not 0.0.0.0)</error>');
                return null;
            }
            $overrides['ml_pair_url'] = $url;
        }

        $mlPairThresholdOpt = $input->getOption('ml-pair-threshold');
        if ($mlPairThresholdOpt !== null) {
            // Same boundary rationale as ir_threshold: raw string to
            // coerceNumerics(), no silent (float) laundering.
            $overrides['ml_pair_threshold'] = $mlPairThresholdOpt;
        }

        $obFlag = $input->getOption('optional-blocks');
        if ($obFlag !== null) {
            $obFlag = strtolower((string)$obFlag);
            if (!in_array($obFlag, ['on', 'off', 'true', 'false', '1', '0', 'yes', 'no'], true)) {
                $output->writeln('<error>phpdup: --optional-blocks must be on|off</error>');
                return null;
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
                return null;
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
                return null;
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
     * shell-S25 (php-dup-finder-shell-012): failures are FATAL. The old
     * posture printed an error line and returned null — indistinguishable
     * from an absent flag — so `--profile=bogus` ran with default settings
     * and exited 0. An unknown name now throws with the same message the
     * old writeln emitted (byte-identical user-facing line via the caller's
     * `phpdup: ` prefix), and ProfileRegistry::load()'s RuntimeException
     * for a missing/unreadable/invalid profile file propagates untouched.
     * execute() maps both onto the conventional exit 2.
     *
     * @param list<string> $paths
     * @param array<string, mixed> $overrides
     * @return list<string>|null $profileExclude null only when the flag is absent
     * @throws \RuntimeException when the profile name is unknown or its file cannot be loaded
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
            throw new \RuntimeException(sprintf(
                '--profile must be one of %s|auto',
                implode('|', $registry->listAvailable()),
            ));
        }

        // Unknown / unreadable / invalid profile JSON throws RuntimeException
        // straight through — the exit-2 door in execute() renders it.
        $profileData = $registry->load($profileName);

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
     * shell-S25 (php-dup-finder-shell-043): the probe samples the tree the
     * run will actually scan. The old posture probed with stock defaults
     * only — on a laravel-shaped corpus the profile's vendor/storage/node_
     * modules trees counted into the band, a "medium" corpus read as
     * "large", and exact_only silently disabled tier scoring. Per-directory
     * overrides still merge later inside ConfigLoader; the profile is the
     * dominant pre-pipeline exclusion term and is now folded in.
     *
     * once-S26 (php-dup-finder-once-030): the same honesty extends to the
     * root --config file. The probe now mirrors ConfigLoader::load()'s path
     * and exclude resolution (config "paths"/"exclude" applied,
     * "exclude_reset" honored), and the tuner receives the merged EFFECTIVE
     * knob values plus the explicitly-set (locked) keys: tuned suggestions
     * only fill gaps — a knob set on the CLI, via --profile, or in the
     * config file is never overwritten (COPY-05: tuned values used to enter
     * the TOP override layer and outrank --config), and the printed
     * rationale claims only what the merge actually applies (PD-CFG-11's
     * blind census, PD-CFG-12's "8→8" no-op narration).
     *
     * The config file is re-read here under a bounded, FORGIVING pre-read:
     * any failure answers an empty payload, so admission behavior is
     * untouched — the authoritative ConfigLoader::load() below still
     * surfaces the real error through the exit-2 door with its own message.
     *
     * @param list<string> $paths
     * @param array<string, mixed> $overrides
     * @param list<string>|null $profileExclude excludes resolved from --profile (null = absent)
     * @return bool $autoTuneExactOnly
     */
    private function resolveAutoTune(InputInterface $input, OutputInterface $output, array $paths, array &$overrides, ?array $profileExclude): bool
    {
        if (!$input->getOption('auto-tune')) {
            return false;
        }

        $configData = $this->readConfigForTuner($input->getOption('config'));

        // Mirror of ConfigLoader::load()'s path resolution (keep in step
        // with that formula): a config "paths" key replaces the CLI
        // argument for the real scan, so the probe must count it too.
        $tunePaths = $paths;
        if (isset($configData['paths']) && is_array($configData['paths'])) {
            $fromConfig = array_values(array_filter(
                $configData['paths'],
                static fn($p): bool => is_string($p) && $p !== '',
            ));
            if ($fromConfig !== []) {
                $tunePaths = $fromConfig;
            }
        }

        // Mirror of ConfigLoader::load()'s exclude stack (item-5.2 append
        // semantics, exclude_reset opt-out): the scan will apply exactly
        // this list, so the band census must see the config's own excludes,
        // not just defaults + profile (once-S26; PD-CFG-11).
        $base = Config::defaults($tunePaths);
        $configLayer = isset($configData['exclude']) && is_array($configData['exclude'])
            ? array_values(array_filter($configData['exclude'], static fn($e): bool => is_string($e) && $e !== ''))
            : [];
        $dictLayer = isset($overrides['exclude']) && is_array($overrides['exclude'])
            ? array_values(array_filter($overrides['exclude'], static fn($e): bool => is_string($e) && $e !== ''))
            : [];
        $excludeReset = (bool)($configData['exclude_reset'] ?? false) || (bool)($overrides['exclude_reset'] ?? false);
        $probeExclude = array_values(array_unique($excludeReset
            ? array_merge($configLayer, $dictLayer)
            : array_merge($base->exclude, $profileExclude ?? [], $configLayer, $dictLayer)));

        // once-S26: a knob is the user's when ANY explicit source names it —
        // CLI flag or profile (both already sit in $overrides) or the root
        // config file. Otherwise the run's effective value is the stock
        // default and the tuner may move it (and must say so only when it
        // really moves something).
        $locked    = [];
        $effective = [];
        foreach (['min_block_size', 'min_cluster_impact', 'max_df'] as $key) {
            if (array_key_exists($key, $overrides)) {
                $locked[]        = $key;
                $effective[$key] = $overrides[$key];
            } elseif (array_key_exists($key, $configData)) {
                $locked[]        = $key;
                $effective[$key] = $configData[$key];
            } else {
                $effective[$key] = match ($key) {
                    'min_block_size'     => $base->minBlockSize,
                    'min_cluster_impact' => $base->minClusterImpact,
                    default              => $base->maxDocumentFrequency,
                };
            }
        }

        $tuner = new AutoTuner();
        $suggestion = $tuner->tune($tunePaths, $probeExclude, $effective, $locked);
        $output->writeln(sprintf(
            '<info>phpdup</info> auto-tune: %s',
            $suggestion->rationale,
        ));

        $autoTuneExactOnly = false;
        foreach ($suggestion->overrides as $k => $v) {
            if ($k === 'exact_only') {
                // Disclosure stays "forced on for very large trees": --exact-only
                // is a VALUE_NONE stance, not an override-dict knob, so the OR in
                // parseRuntimeOptions can only make near-dup work stricter.
                $autoTuneExactOnly = (bool)$v;
                continue;
            }
            // Belt over braces: the tuner already filtered $locked out of
            // $suggestion; this keeps the never-overwrite contract true for
            // any caller that hands tune() no context.
            if (!array_key_exists($k, $overrides)) {
                $overrides[$k] = $v;
            }
        }

        return $autoTuneExactOnly;
    }

    /**
     * Bounded, forgiving pre-read of the root --config payload for the
     * auto-tune probe (once-S26). Mirrors ConfigLoader::decodeConfigFile()'s
     * read discipline (size cap, decode depth) but answers [] on EVERY
     * failure mode — this observer must not change admission behavior: the
     * authoritative load() still reports the real reason with its own
     * message through the exit-2 door.
     *
     * @return array<array-key,mixed>
     */
    private function readConfigForTuner(mixed $configFile): array
    {
        if (!is_string($configFile) || $configFile === '' || !is_file($configFile)) {
            return [];
        }
        $handle = @fopen($configFile, 'rb');
        if ($handle === false) {
            return [];
        }
        $bytes = @stream_get_contents($handle, self::TUNER_CONFIG_READ_CAP + 1);
        fclose($handle);
        if ($bytes === false || strlen($bytes) > self::TUNER_CONFIG_READ_CAP) {
            return [];
        }
        try {
            $decoded = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param bool|null $applyMode  shell-S27 (php-dup-finder-shell-053): the
     *         effective apply mode folded by the loaded Config — flag OR
     *         root-config-file, the CLI flag winning the disagreement.
     *         execute() always passes it; the null default keeps the legacy
     *         pre-S27 reflection callers (CliDefaultShadowingTest's door
     *         pins) reading the raw flag alone. No production path sees it.
     * @return array{useCache: bool, exactOnly: bool, showStats: bool, limit: int, cliVerbosity: string, maxMemoryMb: int, stopAfter: Stage|null, watchMode: bool, tuiMode: bool, themeName: string, reportArgs: array<string, mixed>}|null
     *         null = invalid option value (the specific `phpdup:` error line
     *         has been emitted; the caller answers with exit 2, shell-S24)
     */
    private function parseRuntimeOptions(InputInterface $input, OutputInterface $output, bool $autoTuneExactOnly, ?bool $applyMode = null): ?array
    {
        // --no-cache silences the per-file disk caches for this run, READS AND
        // WRITES alike: the per-file IndexStore is not built (PreprocessStage
        // skips reuse), the worker's on-disk AST parser cache is disabled
        // (engine-S26 / engine-019 — astDiskCache=false gives the worker an
        // empty cache dir, so no blobs are parsed from or written to disk),
        // and RefactorStage does not reload cached ASTs. The cluster cache
        // (clusters.* under cache_dir) is governed by --no-incremental, not
        // by this flag. See docs/CLI.md.
        $useCache  = !$input->getOption('no-cache');
        $exactOnly = (bool)$input->getOption('exact-only') || $autoTuneExactOnly;
        $showStats = (bool)$input->getOption('stats');
        $cliVerbosity = match (true) {
            (bool)$input->getOption('summary-only') => \Phpdup\Reporting\CliReporter::VERBOSITY_SUMMARY_ONLY,
            (bool)$input->getOption('clusters')     => \Phpdup\Reporting\CliReporter::VERBOSITY_CLUSTERS,
            default                                 => \Phpdup\Reporting\CliReporter::VERBOSITY_FULL,
        };

        // shell-S24 (php-dup-finder-shell-014): the three render-side numeric
        // flags used to be BLIND-CAST — (int)'abc' is 0 and (float)'hot' is
        // 0.0, so garbage silently became the sentinel value (limit 0, no
        // ceiling, min-safety off) and the run exited 0. Out-of-domain
        // --min-safety merely printed and kept going. All three now pass
        // through ConfigLoader's coercion boundary (the same trim policy,
        // int64-overflow guard and key-named rejection the NUMERIC_KEYS
        // family uses); the --limit default is a native int 50, while the
        // string shapes a CLI value arrives in stay accepted for robustness.
        try {
            $limit = ConfigLoader::coerceRuntimeInt('limit', $input->getOption('limit'), 0);
            $maxMemoryMb = $input->getOption('max-memory') !== null
                ? ConfigLoader::coerceRuntimeInt('max_memory', $input->getOption('max-memory'), 0)
                : 0;
            $minSafety = $input->getOption('min-safety') !== null
                ? ConfigLoader::coerceRuntimeFloat('min_safety', $input->getOption('min-safety'), 0.0, 1.0)
                : 0.0;
        } catch (\InvalidArgumentException $e) {
            $output->writeln('<error>phpdup: ' . $e->getMessage() . '</error>');
            return null;
        }

        $stopAfter = null;
        $stageOpt  = $input->getOption('stage');
        if ($stageOpt !== null) {
            $stopAfter = Stage::tryFrom(strtolower((string)$stageOpt));
            if ($stopAfter === null) {
                $allowed = implode('|', array_map(fn(Stage $s) => $s->value, Stage::ordered()));
                $output->writeln("<error>phpdup: --stage must be one of {$allowed}</error>");
                // shell-S24: was print-and-run-the-world; the pipeline never
                // starts on a bad stage.
                return null;
            }
        }

        $watchMode = (bool)$input->getOption('watch');
        $tuiMode   = $this->shouldRunTui($input, $output);
        $themeName = (string)$input->getOption('theme');
        // shell-S24: theme admission is UNCONDITIONAL now — a typo'd theme
        // must exit 2 whether or not this invocation also passes --tui, so
        // the flag can never lie silently and only bite when --tui is added
        // later (once-S22 relies on this door closing before render).
        if (!in_array(strtolower($themeName), TuiRunner::knownThemes(), true)) {
            $output->writeln(sprintf(
                '<error>phpdup: --theme must be one of %s</error>',
                implode('|', TuiRunner::knownThemes()),
            ));
            return null;
        }

        $pagerMode = (string)($input->getOption('pager') ?? Pager::MODE_NEVER);
        if (!in_array($pagerMode, Pager::MODES, true)) {
            $output->writeln('<error>phpdup: --pager must be one of ' . implode('|', Pager::MODES) . '</error>');
            return null; // shell-S24: was print-and-continue
        }

        // shell-S28 (php-dup-finder-shell-039): --dry-run used to be registered,
        // documented in the grouped help, and NEVER READ — applyDryRun below
        // was hardcoded true (F1a's safe-direction stand-in for the F1b
        // feature). The apply plumbing one layer below always honored a
        // dryRun arg (RefactorPatchReporter::writeTo(apply: true, dryRun:
        // ...)); this leg wires the option to that arg. The value arrives
        // native bool from a bare flag or the Option default (true), or as
        // the literal string an explicit --dry-run=<value> attached. Only the
        // boolean shapes the flag's own help lists are admitted; a bogus
        // value is a usage refusal (null => exit 2), the shell-S24 door.
        $dryRunRaw = $input->getOption('dry-run');
        if ($dryRunRaw === null) {
            $dryRun = true; // option absent — F1a preview posture is the default
        } elseif ($dryRunRaw === true) {
            $dryRun = true; // bare --dry-run (VALUE_OPTIONAL with no attached value)
        } elseif (is_bool($dryRunRaw)) {
            $dryRun = $dryRunRaw;
        } elseif (is_string($dryRunRaw)) {
            switch (strtolower(trim($dryRunRaw))) {
                case '1':
                case 'true':
                case 'yes':
                case 'on':
                    $dryRun = true;
                    break;
                case '0':
                case 'false':
                case 'no':
                case 'off':
                    $dryRun = false;
                    break;
                default:
                    $output->writeln(
                        '<error>phpdup: --dry-run must be a boolean'
                        . ' (true|false|1|0|yes|no|on|off)</error>'
                    );
                    return null; // shell-S24 posture: refuse the run at admission
            }
        } else {
            $output->writeln('<error>phpdup: --dry-run must be a boolean</error>');
            return null;
        }

        $reportArgs = [
            'limit'          => $limit,
            'showStats'      => $showStats,
            'sarifFile'      => $input->getOption('sarif'),
            'gitlabSastFile' => $input->getOption('gitlab-sast'),
            'diffDir'        => $input->getOption('diff'),
            'patchFile'      => $input->getOption('patch'),
            // shell-S27 (php-dup-finder-shell-053): apply mode rides the
            // Config-folded flag OR file value (execute() always passes
            // $applyMode; the ?? keeps pre-S27 reflection callers honest).
            'applyDir'       => ($applyMode ?? (bool)$input->getOption('apply')) ? 'refactored' : null,
            // shell-S28 (php-dup-finder-shell-039): the F1a hardcode was the
            // defect — --dry-run was registered but never read. The value now
            // flows from the option through the boolean door above; the
            // default (bare/absent) stays the F1a preview posture, and
            // --dry-run=false opens the F1b real-apply branch that already
            // ships one layer below (RefactorPatchReporter::writeTo apply).
            'applyDryRun'    => $dryRun,
            'checkstyleFile' => $input->getOption('checkstyle'),
            'csvFile'        => $input->getOption('csv'),
            'prometheusFile' => $input->getOption('prometheus'),
            'timeseriesFile' => $input->getOption('timeseries'),
            'graphvizFile'   => $input->getOption('graphviz'),
            'plantumlFile'   => $input->getOption('plantuml'),
            'refactorPatchDir' => $input->getOption('refactor-patch'),
            'refactorTestsDir' => $input->getOption('refactor-tests'),
            'cliVerbosity'   => $cliVerbosity,
            'minSafety'      => $minSafety,
            'pagerMode'      => $pagerMode,
        ];

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
     * @param list<string>|null $diffCohort once-S34: the authoritative --diff-base
     *                                      cohort resolved at admission (realpaths),
     *                                      pre-set on every PipelineState this
     *                                      driver creates; null without --diff-base
     */
    private function dispatch(
        InputInterface $input,
        OutputInterface $output,
        Config $config,
        \Closure $buildPipeline,
        array $opts,
        ?array $diffCohort = null,
    ): int {
        $tuiRunner = new TuiRunner();

        if ($opts['tuiMode']) {
            // once-S06 (php-dup-finder-once-006): the pipeline's console
            // sink must not be $output here. sugar-core enters the alt
            // screen and drives raw mode through THIS terminal, while the
            // stages write progress, --stats lines and the full CLI report
            // body straight into the OutputInterface handed to iter() —
            // those bytes used to land inside the running dashboard
            // (garbage printout + staircasing never repaired; TUI-01/U-02
            // reproduced live in an 80x24 tmux). The dashboard observes the
            // run through the ProgressListener/model state, never through
            // the console, so the stages get a discard sink; the file
            // reporters still write their artifacts (they never touch
            // $output) and the pager is pinned to MODE_NEVER for this mode
            // at the factory (see the once-S06 block in execute()).
            $modelRef = null;
            $pipelineOutput = new NullOutput();
            $iteratorFactory = static function () use (&$modelRef, $buildPipeline, $config, $pipelineOutput, $diffCohort): array {
                $state = new PipelineState($config);
                if ($diffCohort !== null) {
                    $state->diffBaseFiles = $diffCohort;
                }
                /** @var ProgressListener|null $listener */
                $listener = $modelRef;
                $gen = $buildPipeline($listener)->iter($state, $pipelineOutput);
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
            $rebuild  = static function () use ($config, $diffCohort): PipelineState {
                $state = new PipelineState($config);
                if ($diffCohort !== null) {
                    $state->diffBaseFiles = $diffCohort;
                }
                return $state;
            };
            $scanRoot = $config->paths[0] ?? getcwd();
            $logger = new \Psr\Log\NullLogger();
            return (new WatchRunner($pipeline, $rebuild, $output, $logger, $scanRoot))->run();
        }

        $state    = new PipelineState($config);
        if ($diffCohort !== null) {
            // once-S34: the admission-resolved cohort rides the state from
            // the start; ScanningStage cannot overwrite it (the pipeline
            // config's diff_base was explicitly nulled at admission).
            $state->diffBaseFiles = $diffCohort;
        }
        SignalHandler::install($state);
        $pipeline = $buildPipeline(null);
        // shell-S23 (php-dup-finder-shell-040): drive the cooperative
        // generator here instead of Pipeline::run() so a ScanningStage that
        // fails its --diff-base probe aborts the run at the stage boundary
        // — downstream stages never start, so nothing is extracted before
        // the exit-2 render below (was: full run, then the message). The
        // DebugLogger wiring mirrors Pipeline::run() 1:1.
        $state->setDebugLogger(new DebugLogger($config->debugLog));
        $gen = $pipeline->iter($state, $output);
        try {
            foreach ($gen as $_) {
                if ($state->scanError !== null) {
                    // Abandon the pump: the suspended generator is dropped
                    // with the local, its stages-after-scanning never run.
                    break;
                }
            }
        } catch (CandidateBudgetException | ScriptKindApplyRefusal $e) {
            // ft-n2: the budget halt is a runtime stop, not an invalid
            // invocation — its message already names every remedy. Render
            // it as the clean `phpdup:` error line (the same posture the
            // config boundary uses at load time) instead of letting it
            // reach Symfony's throwable renderer with its usage-dump
            // footer. Exit 1 = runtime-halt per the docs contract
            // (0 = ok incl findings, 2 = invalid invocation, 3 = gate trip).
            // Item 1.3: the script-kind apply refusal rides the SAME
            // posture — a typed runtime refusal, not a crash, not exit 2.
            // shell-S34: its forBlock message embeds the offending file's
            // raw path bytes — escape at the render.
            $output->writeln('<error>phpdup: ' . self::escapeMarkup($e->getMessage()) . '</error>');
            return 1;
        } finally {
            SignalHandler::uninstall();
        }

        if ($state->scanError !== null) {
            // shell-S34 (php-dup-finder-shell-025 leg (b)): the scanError
            // string embeds the probed path and git's raw stderr — the
            // finding's exact Command sink (old :667-669). Escape at the
            // render; the composed string itself (and its substring pins)
            // stays untouched.
            $output->writeln('<error>' . self::escapeMarkup($state->scanError) . '</error>');
            return 2;
        }

        if ($state->cancelled) {
            $output->writeln('<comment>phpdup: cancelled by user — partial report rendered</comment>');
            return 130; // canonical SIGINT exit code
        }

        // CI gate: exit code 3 when thresholds are exceeded.
        // once-S15 (php-dup-finder-once-018): ONE canonical --fail-on-impact
        // semantic — the per-cluster MAXIMUM impact compared with `>=`,
        // identical to the shipped GitHub action's fail-on-impact input and
        // to the formula documented in docs/CLI.md and docs/CI.md. (The CLI
        // used to sum TOTAL impact and compare `>`, so CI and local runs
        // disagreed on the same flag name.) The exit-code contract is
        // untouched: 3 = gate trip. A trip line names WHICH gate fired.
        if ($config->failOnImpact > 0) {
            $maxImpact = 0;
            foreach ($state->clusters as $cluster) {
                if ($cluster->impact > $maxImpact) {
                    $maxImpact = $cluster->impact;
                }
            }
            if ($maxImpact >= $config->failOnImpact) {
                $output->writeln('<error>phpdup: --fail-on-impact gate tripped — max cluster impact ' . $maxImpact . ' >= ' . $config->failOnImpact . '.</error>');
                return 3;
            }
        }
        if ($config->maxClusters > 0 && count($state->clusters) > $config->maxClusters) {
            $output->writeln('<error>phpdup: --max-clusters gate tripped — ' . count($state->clusters) . ' clusters > ' . $config->maxClusters . '.</error>');
            return 3;
        }

        // Baseline gating: --baseline-out (write) and --baseline (compare / first-run)
        if ($config->baselineOutFile !== null || $config->baselineFile !== null) {
            $baselineStore = new BaselineStore(scanRoots: $config->paths);

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

        // Build a FileWatcher for the scan root. We need only the first path
        // for now (multi-root support would need one watcher per path).
        $scanRoot = $config->paths[0] ?? getcwd();
        $logger = new ConsoleLogger($output);
        $fileWatcher = new FileWatcher($logger, $scanRoot, 1.5);

        // Rebase the watcher snapshot whenever the model completes a run.
        $reload = 0;
        $watcher = $fileWatcher;
        $fileWatcher->watch(function (string $path, \Phpdup\Watch\FileChangeType $type) use ($model, $program, &$reload, $watcher): void {
            if (!$model->viewState->analysisComplete) {
                // Still working on the current run — don't double-trigger.
                return;
            }
            $reload++;
            $program->send(new \Phpdup\Tui\Msg\RestartPipelineMsg($reload));

            // Rebase the snapshot on the new file list after the run completes.
            $files = $model->state->files;
            $fileInfos = [];
            foreach ($files as $f) {
                $fileInfos[] = new \SplFileInfo($f);
            }
            $watcher->syncFromFileList($fileInfos);
        });

        $program->run();
        return 0;
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
     * profileExclude param and APPENDS to the stock defaults, item 5.2).
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
            $this->doorConfigLoader()->validate($decoded, $configFile);
        } catch (\RuntimeException $e) {
            $output->writeln('<error>phpdup: ' . $e->getMessage() . '</error>');
            return 2;
        }

        $output->writeln("<info>phpdup</info> config OK: {$configFile}");
        return 0;
    }
}
