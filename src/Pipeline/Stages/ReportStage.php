<?php
declare(strict_types=1);

namespace Phpdup\Pipeline\Stages;

use Phpdup\Architecture\Analyzers\AntiPatternAnalyzer;
use Phpdup\Architecture\Analyzers\DesignPatternAnalyzer;
use Phpdup\Architecture\Analyzers\SolidAnalyzer;
use Phpdup\Cli\Pager;
use Phpdup\Parallel\WorkerPool;
use Phpdup\Pipeline\CooperativeStageInterface;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\Stage;
use Phpdup\Refactor\FunctionNameRegistry;
use Phpdup\Reporting\CheckstyleReporter;
use Phpdup\Reporting\CliReporter;
use Phpdup\Reporting\ClusterScope;
use Phpdup\Reporting\CoherenceAnalyzer;
use Phpdup\Reporting\CsvReporter;
use Phpdup\Reporting\DeclaredTwinAnalyzer;
use Phpdup\Reporting\DiffReporter;
use Phpdup\Reporting\GitLabSastReporter;
use Phpdup\Reporting\GraphvizReporter;
use Phpdup\Reporting\HtmlReporter;
use Phpdup\Reporting\JsonReporter;
use Phpdup\Reporting\PlantumlReporter;
use Phpdup\Reporting\PrometheusReporter;
use Phpdup\Reporting\Ranker;
use Phpdup\Reporting\RefactorPatchReporter;
use Phpdup\Reporting\RefactorTestReporter;
use Phpdup\Reporting\Report;
use Phpdup\Reporting\SarifReporter;
use Phpdup\Reporting\ScanWindow;
use Phpdup\Reporting\SourceTextStore;
use Phpdup\Reporting\TimeseriesReporter;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * once-S72 (php-dup-finder-once-087): the reporting stage is COOPERATIVE.
 *
 * It used to run the whole reporter stack — restamps, ranking, coherence,
 * architecture, twin verification, the CLI body, and every artifact sink —
 * inside ONE pump after the final Cluster/Refactor yield: the TUI's finish
 * freeze, seconds of dead keys and a motionless spinner while the tail
 * rendered. It now implements {@see CooperativeStageInterface}: the same
 * statement sequence, threaded through a generator that pauses at
 * cost-bounded checkpoints so the driver can repaint between units.
 *
 * Yield accounting is on COST, not count (the finding's core ask): each
 * checkpoint compares a monotone clock against a deadline {@see
 * self::YIELD_BUDGET_SECONDS} after the previous yield, so cheap phases
 * never pump and slow phases pump at their own pace. The clock is
 * injectable ({@see self::$pumpClock}) so pins exercise both counterfactual
 * postures deterministically; production reads wall time. External pump
 * time is never charged to the stage: the window is re-anchored AFTER each
 * yield resumes, so a repaint between pumps cannot cascade extra yields.
 *
 * Deliberate deviation from the soft-cancel law: cooperative stages break
 * out cleanly when {@see PipelineState::$cancelled} is set, but ReportStage
 * NEVER aborts — it is precisely the partial report that a cancel is meant
 * to deliver (Pipeline::iter() codifies the same exemption by skipping
 * every OTHER stage once cancelled). The once-S75 SIGINT contract keeps the
 * run interruptible anyway: the second press kills the program between
 * pumps, and every checkpoint here is a pump boundary.
 *
 * Carrier-integrity law (the #1 regression class for this conversion): the
 * once-S37 scan-signal carriers (scanNotes / parseFailures /
 * clustersReplayed) and every payload mutation are built by EXACTLY ONE
 * statement sequence — yields are pause points, never re-orderings. The
 * synchronous entry {@see run()} drains this generator, so the CLI, the
 * library callers and Watch/Server re-entry see byte-identical semantics to
 * the pre-conversion body; the live {@see Pipeline::iter()} driver consumes
 * the yields between repaints. Proven pin-wise in PipelineTest (frozen vs
 * hot pump clock, run-vs-iter sink byte equality) and by the golden fleet
 * staying byte-frozen at 1.7.
 */
final class ReportStage implements CooperativeStageInterface
{
    /**
     * Wall-clock window a reporting unit may run before the stage offers a
     * repaint to its driver. 25 ms bounds each pump well inside the 500 ms
     * acceptance cadence while keeping yield (and pipelineFrame bookkeeping)
     * overhead negligible on fast corpora.
     */
    private const YIELD_BUDGET_SECONDS = 0.025;

    /**
     * Monotone clock behind the yield budget. Injectable so pins freeze (no
     * yields, ever) or run hot (a yield at every checkpoint) without
     * sleeping; production defaults to wall time like every other timer on
     * the pipeline.
     *
     * @var \Closure(): float
     */
    private readonly \Closure $pumpClock;

    public function __construct(
        private readonly int $limit,
        private readonly bool $showStats,
        private readonly ?string $sarifFile = null,
        private readonly ?string $gitlabSastFile = null,
        private readonly ?string $diffDir = null,
        private readonly ?string $patchFile = null,
        private readonly ?string $applyDir = null,
        private readonly bool $applyDryRun = true,
        private readonly ?string $checkstyleFile = null,
        private readonly ?string $csvFile = null,
        private readonly ?string $prometheusFile = null,
        private readonly ?string $timeseriesFile = null,
        private readonly string $cliVerbosity = CliReporter::VERBOSITY_FULL,
        private readonly float $minSafety = 0.0,
        private readonly ?string $graphvizFile = null,
        private readonly ?string $plantumlFile = null,
        private readonly string $pagerMode = Pager::MODE_NEVER,
        private readonly ?string $refactorPatchDir = null,
        private readonly ?string $refactorTestsDir = null,
        ?\Closure $pumpClock = null,
    ) {
        $this->pumpClock = $pumpClock ?? static fn(): float => microtime(true);
    }

    #[\Override]
    public function name(): Stage
    {
        return Stage::Reporting;
    }

    #[\Override]
    public function run(PipelineState $state, OutputInterface $output): void
    {
        // once-S72: one body. The synchronous entry drains the cooperative
        // generator exactly as Pipeline::run() drains the whole pipeline —
        // the yields are pure pause points no sync caller observes.
        foreach ($this->iter($state, $output) as $_) {
        }
    }

    /**
     * Cooperative drive of the report tail: every unit below is one of the
     * original synchronous run() steps, in the original order, with a
     * cost-bounded yield checkpoint interleaved where real work happens.
     *
     * @return \Generator<int, Stage>
     */
    #[\Override]
    public function iter(PipelineState $state, OutputInterface $output): \Generator
    {
        // 0.6: the old guard was `if (!$state->blocks) return;` which masked
        // the entire skip surface — a corpus whose files all parse into zero
        // comparable blocks (or all error) produced no Report, no CLI
        // verdict, and never wrote --json at all. A run with files but no
        // blocks is exactly the case the new counters exist to report, so
        // only "the scanner found no files" short-circuits now.
        if ($state->files === []) {
            return;
        }

        $config = $state->config;

        // once-S72 pump state (locals only — a Watch/Server re-entry builds a
        // fresh generator, never inherits a stale window).
        $pump       = $this->pumpClock;
        $pumpDue    = $pump() + self::YIELD_BUDGET_SECONDS;
        $unitsDone  = 0;
        $unitTotal  = max(1, 6 + 2 * count($state->clusters) + $this->activeSinkCount());

        // output-S42 (php-dup-finder-output-059): open the run-scoped
        // spread memo window before the restamp below mutates any member
        // and before any consumer (Ranker, SafetyScorer via it, the
        // --scope filter, the JSON 'scope') reads. Watch/Server and
        // cluster-cache replay re-enter run() in the same process, so
        // invalidation must sit on THIS side of the walk — the memo then
        // serves every reader of this run from exactly one compute per
        // cluster, and no spread of a previous run's roots can leak.
        // See the ClusterScope determinism law.
        ClusterScope::beginRun();

        // Item 5.1: labels are run context. Blocks reached here via the
        // normal path were stamped by PreprocessStage from this run's
        // roots; cluster-cache replay, however, deserializes whole
        // Cluster objects whose members carry the ROOTS OF THE SAVING
        // RUN. Restamp every cluster member so repo/scope as reported
        // (and as scored per-repo/typed) always speaks for this run.
        $state->currentTask = 'restamping cluster labels';
        foreach ($state->clusters as $cluster) {
            \Phpdup\Scanning\RepoLabels::stampAll($cluster->members, $config->paths);
            if ($pump() >= $pumpDue) {
                $state->stageProgress = min(0.999, ++$unitsDone / $unitTotal);
                yield Stage::Reporting;
                $pumpDue = $pump() + self::YIELD_BUDGET_SECONDS;
            }
        }

        if ($this->showStats) {
            $output->writeln('  timings (s):');
            foreach ($state->timings as $k => $v) {
                $output->writeln(sprintf('    %-12s %6.2f', $k, $v));
            }
            $output->writeln(sprintf(
                '  workers: %d (pcntl %s)',
                $config->workers > 0 ? $config->workers : WorkerPool::detectCpuCount(),
                WorkerPool::isAvailable() ? 'available' : 'unavailable',
            ));
        }

        $state->currentTask = 'ranking clusters';
        if ($pump() >= $pumpDue) {
            $state->stageProgress = min(0.999, ++$unitsDone / $unitTotal);
            yield Stage::Reporting;
            $pumpDue = $pump() + self::YIELD_BUDGET_SECONDS;
        }

        $clusters = (new Ranker(
            minImpact:  $config->minClusterImpact,
            sort:       \Phpdup\Reporting\ClusterSort::parse($config->sort),
            minSafety:  $this->minSafety,
            scoreMode:  $config->scoreMode,
        ))->rank($state->clusters);

        // Item 5.1: --scope filters between rank and report (order
        // pinned): gates and reporters both see the filtered set, so a
        // CI --fail-on-impact/--max-clusters run with --scope measures
        // exactly the slice it reports. 'all' (default) is a no-op.
        $preFilter = $clusters;
        $clusters = ClusterScope::filter($clusters, $config->scope);

        if ($clusters === [] && $preFilter !== []
            && !in_array($config->scope, [ClusterScope::FILTER_ALL, ClusterScope::FILTER_SINGLE, ClusterScope::FILTER_MULTI], true)) {
            // Review m-2: an open-set scope is a free-form repo label, so a
            // typo empties the report silently (exit 0, zero clusters). One
            // hint line on stderr names the labels this run actually
            // produced — friendly, no usage dump. The closed-set words
            // single/multi can legitimately match zero clusters in a
            // one-cohort run, so they never earn a hint.
            $labels = [];
            foreach ($preFilter as $c) {
                foreach ($c->members as $m) {
                    if ($m->repo !== '') {
                        $labels[$m->repo] = true;
                    }
                }
            }
            ksort($labels);
            if ($labels !== []) {
                $hint = sprintf(
                    'phpdup: --scope=%s matched no clusters; repo labels present in this run: %s',
                    $config->scope,
                    implode(', ', array_keys($labels)),
                );
                // True stderr on the CLI (ConsoleOutput); every other host
                // (TUI, NullOutput tests) keeps the passed stream unchanged.
                $target = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
                $target->writeln($hint);
            }
        }

        // Coherence analysis: flag outlier members (low mean pairwise
        // similarity to the rest of their cluster). Reporters surface
        // these via Cluster::$outlierMemberIds.
        $state->currentTask = 'analysing cluster coherence';
        if ($pump() >= $pumpDue) {
            $state->stageProgress = min(0.999, ++$unitsDone / $unitTotal);
            yield Stage::Reporting;
            $pumpDue = $pump() + self::YIELD_BUDGET_SECONDS;
        }
        $clusters = (new CoherenceAnalyzer())->analyze($clusters);

        // Architectural analysis: emit Finding[] per cluster covering
        // SOLID violations, design-pattern matches, and anti-patterns.
        $state->currentTask = 'analysing architecture';
        $analyzers = [new SolidAnalyzer(), new DesignPatternAnalyzer(), new AntiPatternAnalyzer()];
        foreach ($clusters as $c) {
            $findings = [];
            foreach ($analyzers as $a) {
                foreach ($a->analyze($c) as $f) {
                    $findings[] = $f;
                }
            }
            $c->architecturalFindings = $findings;
            if ($pump() >= $pumpDue) {
                $state->stageProgress = min(0.999, ++$unitsDone / $unitTotal);
                yield Stage::Reporting;
                $pumpDue = $pump() + self::YIELD_BUDGET_SECONDS;
            }
        }

        // Item 4.3: declared-twin verification — measure every doc-comment
        // twin claim in the corpus against the retained content. Drifted
        // claimant clusters gain a warning Finding here (rendered through
        // the existing architectural-findings surface); the full table
        // rides the Report as `declared_twins`. Needs the block index;
        // without one (library-invoked ReportStage on hand-built state)
        // the pass is honestly empty, never faked.
        //
        // output-S34 (php-dup-finder-output-017): the pass is comment-
        // token level — it loads no parsed trees and needs no disk cache,
        // so no cacheDir seam is wired and a --no-cache run pays the same
        // one lex pass per corpus file as a warm one.
        $state->currentTask = 'verifying declared twins';
        if ($pump() >= $pumpDue) {
            $state->stageProgress = min(0.999, ++$unitsDone / $unitTotal);
            yield Stage::Reporting;
            $pumpDue = $pump() + self::YIELD_BUDGET_SECONDS;
        }
        $declaredTwins = [];
        if ($state->index !== null) {
            $twinAnalyzer = new DeclaredTwinAnalyzer(
                index: $state->index,
                similarityThreshold: $config->similarityThreshold,
            );
            $declaredTwins = $twinAnalyzer->analyze($clusters);
            $twinAnalyzer->annotateClusters($clusters, $declaredTwins);
        }

        // W4-B fix (M3): cross-cluster name uniqueness. Runs here — the
        // merge point after ranking and the single post-worker name-decision
        // point — because SignatureBuilder produces names inside forked
        // workers with no shared state. The fresh per-run() instance keeps
        // claims within this report payload only; NEVER make it static
        // (Watch/Server re-run the pipeline in-process; see the P4
        // PipelineState lifecycle law and the class docblock).
        $state->currentTask = 'resolving function names';
        if ($pump() >= $pumpDue) {
            $state->stageProgress = min(0.999, ++$unitsDone / $unitTotal);
            yield Stage::Reporting;
            $pumpDue = $pump() + self::YIELD_BUDGET_SECONDS;
        }
        (new FunctionNameRegistry())->resolve($clusters);

        // once-S59: carry the run's real wall-clock window on the Report.
        // A state driven by Pipeline::iter() knows when the scan began; a
        // hand-driven stage run (library-style callers) does not, and then
        // the Report honestly carries no window instead of a fabricated one.
        $scanWindow = $state->runStartedAt !== null
            ? new ScanWindow(
                startedAt: $state->runStartedAt,
                endedAt:   microtime(true),
            )
            : null;

        $report = new Report(
            files: count($state->files),
            blocks: count($state->blocks),
            parseErrors: $state->parseErrors,
            clusters: $clusters,
            config: $config,
            reusedFiles: $state->reusedFiles,
            processedFiles: $state->processedFiles,
            skippedFiles: $state->skippedFiles,
            declaredTwins: $declaredTwins,
            scanWindow: $scanWindow,
            // once-S37 fix-1 (r1-granted carrier threading, plan §37):
            // the run's real scan-signal channels cross the state→Report
            // seam here so the owner-gated schema-1.8 emit commit has
            // data to report and nothing to invent. The CLI has already
            // surfaced all three honestly (r0 rounds); this is the
            // machine-readable carrier only — no reporter reads it yet,
            // so the JSON payload stays byte-exactly 1.7. once-S72: this
            // single construction site is the whole carrier path — yields
            // interleave the phases, they never re-build or re-order it.
            scanNotes: $state->scanNotes,
            parseFailures: $state->parseFailures,
            clustersReplayed: $state->clustersReplayed,
        );
        $state->report   = $report;
        $state->clusters = $clusters;

        // Cache cluster ids so the `phpdup completion` drill-down can
        // suggest them. One id per line; refreshed when the id list
        // changes, so stale ids age out automatically without a
        // per-run rewrite (shell-S40).
        $state->currentTask = 'writing cluster-id cache';
        if ($pump() >= $pumpDue) {
            $state->stageProgress = min(0.999, ++$unitsDone / $unitTotal);
            yield Stage::Reporting;
            $pumpDue = $pump() + self::YIELD_BUDGET_SECONDS;
        }
        $this->writeClusterIdCache($config->cacheDir, $clusters);

        // output-038: one SourceTextStore per report run. Every source-
        // reading surface (HTML member panes, .diff/.patch streams, the
        // JSON evidence keys, the refactor-patch verdict reads) shares it,
        // so each scanned file is read at most once per run, reads are
        // mtime/size-revalidated, and a symlink planted toward an
        // out-of-root target is refused AT THE READ with a FileScanner-
        // style skip note instead of having its content embedded in a
        // shareable artifact (SEC-06 read-side half). Constructed here —
        // after the report payload exists and before any surface writes —
        // and never reused across runs (Watch/Server re-enter run()).
        $sourceText = new \Phpdup\Reporting\SourceTextStore($config->paths);

        // once-S06 (php-dup-finder-once-006): the pager is a CONSOLE-only
        // affordance. Pager::send proc_opens $PAGER with its stdout on the
        // process's own STDOUT (fd 1) — the payload bypasses whatever
        // OutputInterface was handed to this stage. Any non-console sink
        // (the TUI's discard sink from Command::dispatch, a buffer in a
        // library caller) that still spawned a pager would have the report
        // body written AROUND the abstraction — inside sugar-core's alt
        // screen that is precisely the terminal-corruption class this step
        // closes. Gate the branch on the sink that actually shares the
        // console stream; every other sink renders inline below, and the
        // file reporters still write their artifacts either way. The TUI
        // dispatch additionally pins pagerMode to MODE_NEVER at the
        // factory — the Command-side half of the same seam once-S76's
        // Pager no-TTY posture builds on.
        $state->currentTask = 'rendering report body';
        if ($pump() >= $pumpDue) {
            $state->stageProgress = min(0.999, ++$unitsDone / $unitTotal);
            yield Stage::Reporting;
            $pumpDue = $pump() + self::YIELD_BUDGET_SECONDS;
        }
        if ($this->pagerMode !== Pager::MODE_NEVER && $output instanceof ConsoleOutputInterface) {
            $buf = new \Symfony\Component\Console\Output\BufferedOutput(
                $output->getVerbosity(),
                $output->isDecorated(),
                $output->getFormatter(),
            );
            (new CliReporter($this->cliVerbosity))->render($report, $buf, $this->limit);
            $payload = $buf->fetch();
            $lines = substr_count($payload, "\n");
            if (Pager::shouldPage($this->pagerMode, $lines)) {
                Pager::send($payload, $output);
            } else {
                $output->write($payload);
            }
        } else {
            (new CliReporter($this->cliVerbosity))->render($report, $output, $this->limit);
        }

        // once-S72 sink cadence: every enabled artifact writer announces the
        // sink it is about to perform, then pays the cost-check, so a large
        // tree streams its report tail across repaints instead of arriving
        // as one multi-second freeze after the last cluster row. The writes
        // themselves stay in the original order, one per sink.
        if ($config->jsonReportFile !== null) {
            $state->currentTask = 'writing json report';
            if ($pump() >= $pumpDue) {
                $state->stageProgress = min(0.999, ++$unitsDone / $unitTotal);
                yield Stage::Reporting;
                $pumpDue = $pump() + self::YIELD_BUDGET_SECONDS;
            }
            (new JsonReporter($sourceText))->writeTo($report, $config->jsonReportFile);
            $output->writeln("<info>phpdup</info> json report → {$config->jsonReportFile}");
        }
        if ($config->htmlReportDir !== null) {
            $state->currentTask = 'writing html report';
            if ($pump() >= $pumpDue) {
                $state->stageProgress = min(0.999, ++$unitsDone / $unitTotal);
                yield Stage::Reporting;
                $pumpDue = $pump() + self::YIELD_BUDGET_SECONDS;
            }
            (new HtmlReporter($sourceText))->writeTo($report, $config->htmlReportDir);
            $output->writeln("<info>phpdup</info> html report → {$config->htmlReportDir}/index.html");
        }
        if ($this->sarifFile !== null) {
            $state->currentTask = 'writing sarif report';
            if ($pump() >= $pumpDue) {
                $state->stageProgress = min(0.999, ++$unitsDone / $unitTotal);
                yield Stage::Reporting;
                $pumpDue = $pump() + self::YIELD_BUDGET_SECONDS;
            }
            (new SarifReporter())->writeTo($report, $this->sarifFile);
            $output->writeln("<info>phpdup</info> sarif report → {$this->sarifFile}");
        }
        if ($this->gitlabSastFile !== null) {
            $state->currentTask = 'writing gitlab-sast report';
            if ($pump() >= $pumpDue) {
                $state->stageProgress = min(0.999, ++$unitsDone / $unitTotal);
                yield Stage::Reporting;
                $pumpDue = $pump() + self::YIELD_BUDGET_SECONDS;
            }
            (new GitLabSastReporter())->writeTo($report, $this->gitlabSastFile);
            $output->writeln("<info>phpdup</info> gitlab-sast report → {$this->gitlabSastFile}");
        }
        if ($this->diffDir !== null) {
            $state->currentTask = 'writing diff reports';
            if ($pump() >= $pumpDue) {
                $state->stageProgress = min(0.999, ++$unitsDone / $unitTotal);
                yield Stage::Reporting;
                $pumpDue = $pump() + self::YIELD_BUDGET_SECONDS;
            }
            (new DiffReporter($sourceText))->writeDir($report, $this->diffDir);
            $output->writeln("<info>phpdup</info> diff reports → {$this->diffDir}/");
        }
        if ($this->patchFile !== null) {
            $state->currentTask = 'writing cumulative patch';
            if ($pump() >= $pumpDue) {
                $state->stageProgress = min(0.999, ++$unitsDone / $unitTotal);
                yield Stage::Reporting;
                $pumpDue = $pump() + self::YIELD_BUDGET_SECONDS;
            }
            (new DiffReporter($sourceText))->writePatch($report, $this->patchFile);
            $output->writeln("<info>phpdup</info> cumulative patch → {$this->patchFile}");
        }
        if ($this->checkstyleFile !== null) {
            $state->currentTask = 'writing checkstyle report';
            if ($pump() >= $pumpDue) {
                $state->stageProgress = min(0.999, ++$unitsDone / $unitTotal);
                yield Stage::Reporting;
                $pumpDue = $pump() + self::YIELD_BUDGET_SECONDS;
            }
            (new CheckstyleReporter())->writeTo($report, $this->checkstyleFile);
            $output->writeln("<info>phpdup</info> checkstyle report → {$this->checkstyleFile}");
        }
        if ($this->csvFile !== null) {
            $state->currentTask = 'writing csv report';
            if ($pump() >= $pumpDue) {
                $state->stageProgress = min(0.999, ++$unitsDone / $unitTotal);
                yield Stage::Reporting;
                $pumpDue = $pump() + self::YIELD_BUDGET_SECONDS;
            }
            (new CsvReporter())->writeTo($report, $this->csvFile);
            $output->writeln("<info>phpdup</info> csv report → {$this->csvFile}");
        }
        if ($this->prometheusFile !== null) {
            $state->currentTask = 'writing prometheus report';
            if ($pump() >= $pumpDue) {
                $state->stageProgress = min(0.999, ++$unitsDone / $unitTotal);
                yield Stage::Reporting;
                $pumpDue = $pump() + self::YIELD_BUDGET_SECONDS;
            }
            (new PrometheusReporter())->writeTo($report, $this->prometheusFile);
            $output->writeln("<info>phpdup</info> prometheus report → {$this->prometheusFile}");
        }
        if ($this->timeseriesFile !== null) {
            $state->currentTask = 'writing timeseries record';
            if ($pump() >= $pumpDue) {
                $state->stageProgress = min(0.999, ++$unitsDone / $unitTotal);
                yield Stage::Reporting;
                $pumpDue = $pump() + self::YIELD_BUDGET_SECONDS;
            }
            (new TimeseriesReporter())->writeTo($report, $this->timeseriesFile);
            $output->writeln("<info>phpdup</info> timeseries record appended → {$this->timeseriesFile}");
        }
        if ($this->graphvizFile !== null) {
            $state->currentTask = 'writing graphviz dot';
            if ($pump() >= $pumpDue) {
                $state->stageProgress = min(0.999, ++$unitsDone / $unitTotal);
                yield Stage::Reporting;
                $pumpDue = $pump() + self::YIELD_BUDGET_SECONDS;
            }
            (new GraphvizReporter())->writeTo($report, $this->graphvizFile);
            $output->writeln("<info>phpdup</info> graphviz dot → {$this->graphvizFile}");
        }
        if ($this->plantumlFile !== null) {
            $state->currentTask = 'writing plantuml file';
            if ($pump() >= $pumpDue) {
                $state->stageProgress = min(0.999, ++$unitsDone / $unitTotal);
                yield Stage::Reporting;
                $pumpDue = $pump() + self::YIELD_BUDGET_SECONDS;
            }
            (new PlantumlReporter())->writeTo($report, $this->plantumlFile);
            $output->writeln("<info>phpdup</info> plantuml file → {$this->plantumlFile}");
        }
        if ($this->refactorPatchDir !== null) {
            $state->currentTask = 'writing refactor patches';
            if ($pump() >= $pumpDue) {
                $state->stageProgress = min(0.999, ++$unitsDone / $unitTotal);
                yield Stage::Reporting;
                $pumpDue = $pump() + self::YIELD_BUDGET_SECONDS;
            }
            (new RefactorPatchReporter($sourceText))->writeTo($report, $this->refactorPatchDir);
            $output->writeln("<info>phpdup</info> refactor patches → {$this->refactorPatchDir}/");
        }
        if ($this->applyDir !== null) {
            $state->currentTask = 'writing apply output';
            if ($pump() >= $pumpDue) {
                $state->stageProgress = min(0.999, ++$unitsDone / $unitTotal);
                yield Stage::Reporting;
                $pumpDue = $pump() + self::YIELD_BUDGET_SECONDS;
            }
            (new RefactorPatchReporter($sourceText))->writeTo($report, $this->applyDir, apply: true, dryRun: $this->applyDryRun);
            // output-022 (Phase-4 outside merge): announce the artifact the
            // chosen branch actually wrote — F1a dry-run emits the scaffold
            // diff, F1b emits the human summary (markdown, never a `.diff`).
            $applyArtifact = $this->applyDryRun
                ? RefactorPatchReporter::APPLY_DIFF_FILE
                : RefactorPatchReporter::APPLY_SUMMARY_FILE;
            $applyLabel = $this->applyDryRun ? 'diff' : 'summary';
            $output->writeln("<info>phpdup</info> apply {$applyLabel} → {$this->applyDir}/{$applyArtifact}");
        }
        if ($this->refactorTestsDir !== null) {
            $state->currentTask = 'writing refactor test skeletons';
            if ($pump() >= $pumpDue) {
                $state->stageProgress = min(0.999, ++$unitsDone / $unitTotal);
                yield Stage::Reporting;
                $pumpDue = $pump() + self::YIELD_BUDGET_SECONDS;
            }
            (new RefactorTestReporter())->writeTo($report, $this->refactorTestsDir);
            $output->writeln("<info>phpdup</info> refactor test skeletons → {$this->refactorTestsDir}/");
        }
    }

    /**
     * How many artifact sinks this instance will actually run — the rough
     * size of one report-tail pump sequence, used only to scale the
     * display-side stageProgress fraction (never the payload).
     */
    private function activeSinkCount(): int
    {
        $sinks = [
            $this->sarifFile,
            $this->gitlabSastFile,
            $this->diffDir,
            $this->patchFile,
            $this->applyDir,
            $this->checkstyleFile,
            $this->csvFile,
            $this->prometheusFile,
            $this->timeseriesFile,
            $this->graphvizFile,
            $this->plantumlFile,
            $this->refactorPatchDir,
            $this->refactorTestsDir,
        ];
        $active = 0;
        foreach ($sinks as $sink) {
            if ($sink !== null) {
                $active++;
            }
        }
        return $active;
    }

    /**
     * Refresh the cluster-id cache. shell-S40 (php-dup-finder-shell-051)
     * removes the unconditional per-run I/O: the payload is built first
     * and compared against the current file, an identical list is not
     * rewritten, and a zero-cluster run over a cache dir that does not
     * exist yet creates neither the dir nor an empty list — nothing was
     * recorded before, nothing is recorded now, so the state is
     * unchanged. A stale non-empty list IS still aged out: the empty
     * payload differs from it and lands as a truncate.
     *
     * @param list<\Phpdup\Clustering\Cluster> $clusters
     */
    private function writeClusterIdCache(string $cacheDir, array $clusters): void
    {
        if ($cacheDir === '') {
            return;
        }
        $payload = '';
        foreach ($clusters as $c) {
            $payload .= $c->id . "\n";
        }
        $path = $cacheDir . '/clusters.list';
        $current = @is_file($path) ? @file_get_contents($path) : null;
        if ($current === $payload) {
            return;
        }
        if ($current === null && $payload === '') {
            return;
        }
        if (!@is_dir($cacheDir) && !@mkdir($cacheDir, 0o775, true)) {
            return;
        }
        @file_put_contents($path, $payload);
    }
}
