<?php
declare(strict_types=1);

namespace Phpdup\Pipeline\Stages;

use Phpdup\Architecture\Analyzers\AntiPatternAnalyzer;
use Phpdup\Architecture\Analyzers\DesignPatternAnalyzer;
use Phpdup\Architecture\Analyzers\DriftAnalyzer;
use Phpdup\Architecture\Analyzers\SolidAnalyzer;
use Phpdup\Cli\Pager;
use Phpdup\Parallel\WorkerPool;
use Phpdup\Pipeline\CooperativeStageInterface;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\Stage;
use Phpdup\Refactor\FunctionNameRegistry;
use Phpdup\Reporting\BaselineFilter;
use Phpdup\Reporting\BaselineStore;
use Phpdup\Reporting\CheckstyleReporter;
use Phpdup\Reporting\CliReporter;
use Phpdup\Reporting\ClusterScope;
use Phpdup\Reporting\CoherenceAnalyzer;
use Phpdup\Reporting\CsvReporter;
use Phpdup\Reporting\DeclaredTwinAnalyzer;
use Phpdup\Reporting\DiffReporter;
use Phpdup\Reporting\EvidencePackBuilder;
use Phpdup\Reporting\GitLabSastReporter;
use Phpdup\Reporting\GraphvizReporter;
use Phpdup\Reporting\HtmlReporter;
use Phpdup\Reporting\JsonReporter;
use Phpdup\Reporting\PlantumlReporter;
use Phpdup\Reporting\PrometheusReporter;
use Phpdup\Reporting\Ranker;
use Phpdup\Reporting\RefactorPatchReporter;
use Phpdup\Reporting\RefactorTestReporter;
use Phpdup\Reporting\RollupAnalyzer;
use Phpdup\Reporting\Report;
use Phpdup\Reporting\SarifReporter;
use Phpdup\Reporting\ScanWindow;
use Phpdup\Reporting\SourceTextStore;
use Phpdup\Reporting\TimeseriesReporter;
use Symfony\Component\Console\Formatter\OutputFormatter;
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
 * Checkpoints sit BETWEEN units, so a single unit that itself outlasts the
 * budget (one giant cluster through the architecture pass is the known case)
 * can still stretch one pump: unit granularity is the floor this design
 * cannot cross without owning the analyzers' internals.
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
        private readonly ?string $evidencePacksDir = null,
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
        //
        // Item 5.2 (W2b): the vendored mark rides the identical law —
        // a cached blob may restore a stale $vendored (whole-object
        // serialization, the P1 Hole safe-direction precedent), and the
        // restamp here overwrites it from THIS run's roots before the
        // scope demotion below reads one. That is why no PARSER_VERSION
        // or cluster-cache key binds the marker.
        $vendorMarker = \Phpdup\Scanning\VendorMarker::forRoots($config->paths);
        $state->currentTask = 'restamping cluster labels';
        foreach ($state->clusters as $cluster) {
            \Phpdup\Scanning\RepoLabels::stampAll($cluster->members, $config->paths);
            $vendorMarker->stampAll($cluster->members);
            if ($pump() >= $pumpDue) {
                $state->stageProgress = min(0.999, ++$unitsDone / $unitTotal);
                yield Stage::Reporting;
                $pumpDue = $pump() + self::YIELD_BUDGET_SECONDS;
            }
        }

        // Item 5.2 (W2b): lock fail-open notes ride the same skip-with-
        // note channel; the run usually saw them already at the
        // PreprocessStage stamp (same roots, same wording), so this
        // merge only adds what is genuinely new — a library-driven
        // ReportStage over hand-built state is the case that still
        // surfaces its corrupt lock instead of swallowing it.
        foreach ($vendorMarker->notes() as $note) {
            if (!in_array($note, $state->scanNotes, true)) {
                $state->scanNotes[] = $note;
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

        // Item 6.3 (demand-armed): fold subsumed clone families under
        // their maximal ancestors BEFORE ranking, per the corrected
        // placement (improve_logic_info.md §6.3 — ReportStage pre-
        // Ranker, after the 1.4 medoid-ball split which ClusterStage
        // already ran). Subsumed families leave the ranked/payload set
        // entirely — inner clones annotate the outer family (its own
        // lines already cover theirs) instead of multiplying cluster
        // counts, total_impact and rank slots. Armed ONLY on the
        // idiom_mining demand knob (the census-class posture the
        // statement-fragment nesting rides; no new key): default runs —
        // every golden, CI smoke and library route — skip this block
        // byte-identically. The fold shares ONE suppression rule with
        // 2.1/3.1/7.2c (NestedFamilySuppression::keepMaximal), never a
        // second copy.
        $rankInput = $state->clusters;
        if ($config->idiomMining) {
            $rankInput = (new \Phpdup\Reporting\SubsumptionFolder())->fold($state->clusters)['maximal'];
        }

        $clusters = (new Ranker(
            minImpact:  $config->minClusterImpact,
            sort:       \Phpdup\Reporting\ClusterSort::parse($config->sort),
            minSafety:  $this->minSafety,
            scoreMode:  $config->scoreMode,
        ))->rank($rankInput);

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

        // Item 5.2 (W2b): auto-demote vendored↔vendored under --scope
        // (info-doc §5.2 ruling). Runs AFTER the label-miss hint above
        // (a demotion-emptied slice is not a typo'd label — the hint
        // already spoke for the set that matched), and BEFORE everything
        // downstream, so coherence/architecture/history/name-registry/
        // gates all measure the first-party slice the report then shows
        // — the doc's exact complaint is vendor-polluted scope
        // denominators. Fully-vendored clusters move to the conditional
        // `summary.vendored_duplicates` section; mixed clusters are not
        // vendored↔vendored and stay in the main report. An armed scope
        // run over a vendored-free corpus is an identity partition
        // (zero-shaped section); the unarmed path never builds the split
        // at all — $vendoredDuplicates stays null, the key never opens,
        // goldens byte-hold.
        $vendoredDuplicates = null;
        if ($config->scope !== ClusterScope::FILTER_ALL) {
            $vendoredSplit      = \Phpdup\Reporting\VendoredSection::split($clusters, $vendorMarker);
            $clusters           = $vendoredSplit['main'];
            $vendoredDuplicates = $vendoredSplit['section'];
            if ($vendoredDuplicates['clusters'] > 0) {
                // One honest visibility line (scope-hint lineage): the
                // slice shrank and the reader is told where the rows
                // went. Same error-output routing as the label hint.
                $demoteLine = sprintf(
                    'phpdup: --scope=%s demoted %d vendored-duplicate cluster(s), %d lines, to the report\'s vendored_duplicates section',
                    $config->scope,
                    $vendoredDuplicates['clusters'],
                    $vendoredDuplicates['lines'],
                );
                $demoteTarget = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
                $demoteTarget->writeln($demoteLine);
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
        // item 4.1 (r1 M-1): the drift analyzer consumes the config-carried
        // drift_flags vocabulary (root phpdup.json key or the --profile
        // tier). Empty vocab — every run without either, incl. Golden and
        // the CI/smoke legs — keeps it inert and reports byte-identical;
        // --profile generic (or auto, which falls back to generic) arms
        // the four shipped predicates.
        $analyzers = [new SolidAnalyzer(), new DesignPatternAnalyzer(), new AntiPatternAnalyzer(), new DriftAnalyzer($config->driftVocabulary)];
        // Item 3.4 (S4): the duplicate-symbol evidence analyzer is mounted
        // ONLY when --symbols arms the run — same inert-mount posture as
        // DriftAnalyzer, and the same reason: a non-armed run (every frozen
        // Golden, every default corpus) must not construct it at all, so
        // the payload is byte-identical. It re-builds the SymbolIndex over
        // the run's final BlockIndex — same inputs, same deterministic pair
        // list the ClusterStage injection consumed (law pinned in
        // SymbolIndexTest) — and files one finding per forced pair involving
        // a cluster member, with the measured similarities.
        if ($config->symbolsForced && $state->index !== null) {
            $symbolIndex = new \Phpdup\Index\SymbolIndex();
            $symbolIndex->build($state->index);
            $analyzers[] = new \Phpdup\Architecture\Analyzers\SymbolDuplicationAnalyzer(
                symbols: $symbolIndex,
                similarityThreshold: $config->similarityThreshold,
            );
        }
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

        // Item 4.4 APPLY half: accepted-finding disposition. The ranked
        // full set stays on $state->clusters (gates, max-clusters, the
        // cluster-id cache and the baseline hash comparison all keep
        // their view); only the REPORT payload splits into main findings
        // plus the appendix of suppressions. With no decisions in play
        // (no registry dispositions, no accepted list, no directives)
        // this is a passthrough and the report is byte-unchanged.
        $mainClusters = $clusters;
        $baselineAppendix = [];
        $directiveRows = array_values(array_filter(
            $declaredTwins,
            static fn(array $row): bool => ($row['kind'] ?? null) === 'directive',
        ));
        if ($config->accepted !== [] || $directiveRows !== [] || $config->baselineFile !== null) {
            $registryDispositions = [];
            if ($config->baselineFile !== null) {
                try {
                    $registryDispositions = (new BaselineStore(scanRoots: $config->paths))
                        ->readDispositions($config->baselineFile);
                } catch (\RuntimeException $e) {
                    // M-1 (E4 r1): a door-level corrupt baseline must not
                    // pre-empt the curated gate door below, which names the
                    // same repair at exit 2 AFTER the report renders (the
                    // base 940efc8 shape), and must not kill a --baseline-out
                    // refresh that exists precisely to heal the file. Only
                    // the code-2 door arms are deferred; record-level
                    // garbage still dies loudly right here. The deferral is
                    // never silent: registry suppressions genuinely do not
                    // apply without the file, and the class law says an
                    // un-applied decision must never pass unnoticed.
                    if ($e->getCode() !== 2) {
                        throw $e;
                    }
                    $output->writeln('<comment>phpdup: warning: ' . OutputFormatter::escape($e->getMessage())
                        . ' — registry dispositions not applied this run</comment>');
                }
            }
            if ($registryDispositions !== [] || $config->accepted !== [] || $directiveRows !== []) {
                $baselineFilter = new BaselineFilter(scanRoots: $config->paths);
                $split = $baselineFilter->split($clusters, $registryDispositions, $config->accepted, $directiveRows);
                $mainClusters = $split['main'];
                $baselineAppendix = $split['appendix'];
            }
        }

        // Item 4.2 (git-history correlation): opt-in blame/log sidecar.
        // Runs on the SAME cluster set the report carries (post-baseline-
        // split $mainClusters — suppressed appendix rows are not report
        // claims and get no history) and AFTER the architecture pass, so
        // a 4.1 DriftAnalyzer finding on the cluster is visible to the
        // corroboration leg of the lone-fix message. Armed-only: the
        // default path takes zero new yields, git never runs, and the
        // report byte-shape is untouched (§8.4 opt-in zero churn). Every
        // git failure mode fails open to unmeasurable rows + scan notes
        // (skip-with-note law; exit code never moves, BaselineStore's
        // code-2 door below is untouched by this block).
        $clusterHistory = null;
        if ($config->history) {
            $state->currentTask = 'correlating git history';
            if ($pump() >= $pumpDue) {
                $state->stageProgress = min(0.999, ++$unitsDone / $unitTotal);
                yield Stage::Reporting;
                $pumpDue = $pump() + self::YIELD_BUDGET_SECONDS;
            }
            $historyAnalyzer = new \Phpdup\Git\ClusterHistoryAnalyzer($config);
            $clusterHistory  = $historyAnalyzer->correlate($mainClusters);
            $historyAnalyzer->annotateClusters($mainClusters);
            foreach ($clusterHistory->notes as $note) {
                $state->scanNotes[] = $note;
            }
        }
        // Item 2.2 (ROLLUP): corpus-awareness rollups — per-file token
        // facts, both-direction twin file pairs and mirrored directory
        // pairs — ARMED ONLY (config->corpus_rollups, the idiom_census
        // conditional-key family): an unarmed run never runs the analyzer
        // and the summary key is absent, so every default payload stays
        // byte-identical and the goldens stay frozen (no version event).
        // Report-stage recompute (f08 §2): nothing persists, so no cache
        // key binds the knob and no Block field is added; the pass is
        // read-only over clusters (the §5f payload-rides-trailing-param
        // seam below carries it). Synchronous like the twin analyzer
        // mounted above — the whole-corpus AST sweep is the armed run's
        // disclosed price (cooperative chunk-yield is a named follow-up).
        // The FULL ranked set feeds the analyzer (4.4's split suppresses
        // REPORTED findings; suppressed duplication still measures corpus
        // density, and $state->clusters keeps its full view likewise).
        $rollups = null;
        if ($config->corpusRollups) {
            $state->currentTask = 'rolling up corpus awareness';
            $rollups = (new RollupAnalyzer(relRoots: $config->paths))->analyze(
                $clusters,
                $state->blocks,
                $state->files,
            );
            if ($pump() >= $pumpDue) {
                $state->stageProgress = min(0.999, ++$unitsDone / $unitTotal);
                yield Stage::Reporting;
                $pumpDue = $pump() + self::YIELD_BUDGET_SECONDS;
            }
        }

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
            clusters: $mainClusters,
            config: $config,
            reusedFiles: $state->reusedFiles,
            processedFiles: $state->processedFiles,
            skippedFiles: $state->skippedFiles,
            declaredTwins: $declaredTwins,
            scanWindow: $scanWindow,
            // once-S37 fix-1 (r1-granted carrier threading, plan §37):
            // the run's real scan-signal channels cross the state→Report
            // seam here so the schema-1.8 emit commit has data to report
            // and nothing to invent. The CLI has already surfaced all
            // three honestly (r0 rounds); E2's schema-1.8 commit opened
            // the JSON emit (summary.scan_notes / parse_failures /
            // clusters_replayed / blocks_oversized / ml_status) exactly
            // as this seam was staged for. once-S72: this single
            // construction site is the whole carrier path — yields
            // interleave the phases, they never re-build or re-order it.
            scanNotes: $state->scanNotes,
            parseFailures: $state->parseFailures,
            clustersReplayed: $state->clustersReplayed,
            // E2 schema-1.8: the oversized counter and the ML status join
            // the same state→Report seam; JsonReporter now READS all five
            // carriers (the 1.7 freeze prose below is history).
            oversizedBlocks: $state->oversizedBlocks,
            mlStatus: $state->mlConnectionStatus,
            // Item 4.4 APPLY half: accepted findings suppressed from the
            // main payload, enumerated here (renderers draw the appendix
            // only when rows exist; JsonReporter's top-level 'baseline'
            // section is this carrier's schema-1.8 consumer leg).
            baselineAppendix: $baselineAppendix,
            // Item 3.1 ingestion (MINE-D): the idiom census crosses the
            // same state→Report seam as the carriers above, straight from
            // the SequenceIndex attach point. null whenever no census was
            // armed (idiomCensus() is null on an unarmed index, and the
            // index itself is null when neither demand ever fired) — the
            // reporter's conditional key is what keeps default payloads
            // byte-identical.
            idiomCensus: $state->sequenceIndex?->idiomCensus(),
            // Item 4.2: the git sidecar crossed the same seam — null on
            // the unarmed path, so every default payload stays byte-
            // identical and the conditional key never opens unasked.
            clusterHistory: $clusterHistory,
            // Item 5.2 (W2b): the demoted vendored rows cross the same
            // seam — null whenever scope was not armed (the section was
            // never asked for), a zero-shaped array when it was armed
            // over a vendored-free corpus (measured, not fabricated).
            vendoredDuplicates: $vendoredDuplicates,
            // Items 3.5/3.3 (MINE-E): same state→Report seam, same
            // null-means-unarmed posture — the two new conditional
            // summary keys ride this carrier pair.
            callSequences: $state->sequenceIndex?->callCensus(),
            literalCensus: $state->sequenceIndex?->literalCensus(),
            // E-ROLLUP (item 2.2): the corpus-awareness payload rides the
            // same state→Report seam (RollupAnalyzer's own read of
            // clusters/blocks/files — the stage holds no rollup state).
            // null on every unarmed run — the reporter's conditional key
            // is what keeps default payloads byte-identical.
            rollups: $rollups,
        );
        $state->report   = $report;
        $state->clusters = $clusters;

        // Item 5.3 (W3): arm the placement advisor's 3.2 adoption probe
        // for THIS run. The reporters construct PlacementAdvisor inside
        // their build paths, so the ledger rows cross as a documented
        // per-run arm — ReportStage is the only writer and re-arms on
        // every run (helper_adoption unarmed ⇒ null ⇒ byte-identical
        // conservative payloads), so a stale arm cannot leak across
        // runs even in the long-lived `serve` workers.
        \Phpdup\Refactor\PlacementAdvisor::armAdoptionProbe($state->sequenceIndex?->helperAdoption()?->rows());

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
        if ($this->evidencePacksDir !== null) {
            // Item 8.1: standalone per-finding evidence packs. Rides the
            // shared per-run SourceTextStore (output-038 single-read law)
            // and the SAME 3.2 adoption rows PlacementAdvisor was armed
            // with above — one join source, never a re-mine.
            $state->currentTask = 'writing evidence packs';
            if ($pump() >= $pumpDue) {
                $state->stageProgress = min(0.999, ++$unitsDone / $unitTotal);
                yield Stage::Reporting;
                $pumpDue = $pump() + self::YIELD_BUDGET_SECONDS;
            }
            (new EvidencePackBuilder($sourceText, $state->sequenceIndex?->helperAdoption()?->rows()))
                ->writeTo($report, $this->evidencePacksDir);
            $output->writeln("<info>phpdup</info> evidence packs → {$this->evidencePacksDir}/");
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
            $this->evidencePacksDir,
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
