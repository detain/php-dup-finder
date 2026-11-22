<?php
declare(strict_types=1);

namespace Phpdup\Pipeline\Stages;

use Phpdup\Architecture\Analyzers\AntiPatternAnalyzer;
use Phpdup\Architecture\Analyzers\DesignPatternAnalyzer;
use Phpdup\Architecture\Analyzers\SolidAnalyzer;
use Phpdup\Cli\Pager;
use Phpdup\Parallel\WorkerPool;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\Stage;
use Phpdup\Pipeline\StageInterface;
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

final class ReportStage implements StageInterface
{
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
    ) {}

    #[\Override]
    public function name(): Stage
    {
        return Stage::Reporting;
    }

    #[\Override]
    public function run(PipelineState $state, OutputInterface $output): void
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

        // Item 5.1: labels are run context. Blocks reached here via the
        // normal path were stamped by PreprocessStage from this run's
        // roots; cluster-cache replay, however, deserializes whole
        // Cluster objects whose members carry the ROOTS OF THE SAVING
        // RUN. Restamp every cluster member so repo/scope as reported
        // (and as scored per-repo/typed) always speaks for this run.
        foreach ($state->clusters as $cluster) {
            \Phpdup\Scanning\RepoLabels::stampAll($cluster->members, $config->paths);
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
        $clusters = (new CoherenceAnalyzer())->analyze($clusters);

        // Architectural analysis: emit Finding[] per cluster covering
        // SOLID violations, design-pattern matches, and anti-patterns.
        $analyzers = [new SolidAnalyzer(), new DesignPatternAnalyzer(), new AntiPatternAnalyzer()];
        foreach ($clusters as $c) {
            $findings = [];
            foreach ($analyzers as $a) {
                foreach ($a->analyze($c) as $f) {
                    $findings[] = $f;
                }
            }
            $c->architecturalFindings = $findings;
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
        );
        $state->report   = $report;
        $state->clusters = $clusters;

        // Cache cluster ids so the `phpdup completion` drill-down can
        // suggest them. One id per line; refreshed when the id list
        // changes, so stale ids age out automatically without a
        // per-run rewrite (shell-S40).
        $this->writeClusterIdCache($config->cacheDir, $clusters);

        // output-038: one SourceTextStore per report run. Every source-
        // reading surface (HTML member panes, .diff/.patch streams, the
        // JSON evidence keys, the refactor-patch verdict reads) shares it,
        // so each scanned file is read at most once per run, reads are
        // mtime/size-revalidated, and a symlink planted toward an
        // out-of-root target is refused AT THE READ with a FileScanner-
        // style skip note instead of having its content embedded in a
        // shareable artifact (SEC-06 read-side half). Constructed here —
        // after the report payload exists and before any surface writes
        // — and never reused across runs (Watch/Server re-enter run()).
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

        if ($config->jsonReportFile !== null) {
            (new JsonReporter($sourceText))->writeTo($report, $config->jsonReportFile);
            $output->writeln("<info>phpdup</info> json report → {$config->jsonReportFile}");
        }
        if ($config->htmlReportDir !== null) {
            (new HtmlReporter($sourceText))->writeTo($report, $config->htmlReportDir);
            $output->writeln("<info>phpdup</info> html report → {$config->htmlReportDir}/index.html");
        }
        if ($this->sarifFile !== null) {
            (new SarifReporter())->writeTo($report, $this->sarifFile);
            $output->writeln("<info>phpdup</info> sarif report → {$this->sarifFile}");
        }
        if ($this->gitlabSastFile !== null) {
            (new GitLabSastReporter())->writeTo($report, $this->gitlabSastFile);
            $output->writeln("<info>phpdup</info> gitlab-sast report → {$this->gitlabSastFile}");
        }
        if ($this->diffDir !== null) {
            (new DiffReporter($sourceText))->writeDir($report, $this->diffDir);
            $output->writeln("<info>phpdup</info> diff reports → {$this->diffDir}/");
        }
        if ($this->patchFile !== null) {
            (new DiffReporter($sourceText))->writePatch($report, $this->patchFile);
            $output->writeln("<info>phpdup</info> cumulative patch → {$this->patchFile}");
        }
        if ($this->checkstyleFile !== null) {
            (new CheckstyleReporter())->writeTo($report, $this->checkstyleFile);
            $output->writeln("<info>phpdup</info> checkstyle report → {$this->checkstyleFile}");
        }
        if ($this->csvFile !== null) {
            (new CsvReporter())->writeTo($report, $this->csvFile);
            $output->writeln("<info>phpdup</info> csv report → {$this->csvFile}");
        }
        if ($this->prometheusFile !== null) {
            (new PrometheusReporter())->writeTo($report, $this->prometheusFile);
            $output->writeln("<info>phpdup</info> prometheus report → {$this->prometheusFile}");
        }
        if ($this->timeseriesFile !== null) {
            (new TimeseriesReporter())->writeTo($report, $this->timeseriesFile);
            $output->writeln("<info>phpdup</info> timeseries record appended → {$this->timeseriesFile}");
        }
        if ($this->graphvizFile !== null) {
            (new GraphvizReporter())->writeTo($report, $this->graphvizFile);
            $output->writeln("<info>phpdup</info> graphviz dot → {$this->graphvizFile}");
        }
        if ($this->plantumlFile !== null) {
            (new PlantumlReporter())->writeTo($report, $this->plantumlFile);
            $output->writeln("<info>phpdup</info> plantuml file → {$this->plantumlFile}");
        }
        if ($this->refactorPatchDir !== null) {
            (new RefactorPatchReporter($sourceText))->writeTo($report, $this->refactorPatchDir);
            $output->writeln("<info>phpdup</info> refactor patches → {$this->refactorPatchDir}/");
        }
        if ($this->applyDir !== null) {
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
            (new RefactorTestReporter())->writeTo($report, $this->refactorTestsDir);
            $output->writeln("<info>phpdup</info> refactor test skeletons → {$this->refactorTestsDir}/");
        }
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
