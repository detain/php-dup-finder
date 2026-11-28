<?php
declare(strict_types=1);

namespace Phpdup\Reporting;

use Phpdup\Cli\Config;
use Phpdup\Clustering\Cluster;

/**
 * In-memory analysis result. Reporters consume this; nothing else
 * should hold a reference to clusters past this point.
 *
 * The trailing file-accounting counters (0.6) are nullable on purpose:
 * producers that predate per-bucket tracking (library API, server
 * workers) pass nothing and report `null` = "not tracked", which is
 * distinct from a tracked zero. ReportStage threads the real numbers.
 */
final class Report
{
    /**
     * @param list<Cluster> $clusters
     * @param list<array<string, mixed>> $declaredTwins
     * @param list<array<string, mixed>> $baselineAppendix Item 4.4: accepted/unresolved/resurfaced rows (empty = no decisions).
     * @param list<string> $scanNotes
     * @param list<array{file:string,message:string}> $parseFailures
     * @param null|array<string, mixed> $vendoredDuplicates Item 5.2 (W2b): the demoted 'vendored duplicates' section (null = scope unarmed, key never opens).
     */
    public function __construct(
        public readonly int $files,
        public readonly int $blocks,
        public readonly int $parseErrors,
        public readonly array $clusters,
        public readonly Config $config,
        public readonly ?int $reusedFiles = null,
        public readonly ?int $processedFiles = null,
        public readonly ?int $skippedFiles = null,
        // Item 4.3: declared-twin claim-vs-reality rows
        // (DeclaredTwinAnalyzer::analyze()); trailing default = "feature
        // not run" for library/worker producers, so the §5f rule that
        // payload lists ride trailing params holds zero-cost.
        public readonly array $declaredTwins = [],
        // once-S59: wall-clock scan window from pipeline metadata
        // (PipelineState::$runStartedAt → report-build instant, stamped by
        // ReportStage). Null = "this producer never measured a run" (library
        // API, server workers, hand-built reports); timestamp-emitting
        // reporters then omit their fields honestly rather than fabricating
        // a build-time clock read. Not part of the JSON payload shape.
        public readonly ?ScanWindow $scanWindow = null,
        // once-S37 fix-1 (r1-granted carrier threading): the run's scan-signal
        // channels ride the Report as trailing params — §5f, payload lists
        // ride trailing params. Defaults keep every legacy producer honest
        // (library API, server workers at AnalysisWorker): empty lists mean
        // "this producer did not track them", clustersReplayed null means
        // "unknown", never a fabricated zero or false. ReportStage threads
        // the real PipelineState values. The JSON emit of
        // summary.scan_notes[] / summary.parse_failures[] /
        // summary.clusters_replayed (plan §37 acceptance) is a schema-1.8
        // event: it lands only in the same commit that moves SCHEMA_VERSION
        // 1.7→1.8, so until that owner-gated window the carrier is read by
        // no reporter and the payload stays exactly 1.7. The skipNotes
        // channel contract is reused by once-S41/S66/S71.
        public readonly array $scanNotes = [],
        public readonly array $parseFailures = [],
        public readonly ?bool $clustersReplayed = null,
        // Item 0.6 (oversized leg): the mass the max_block_size ceiling
        // gave up on during this run's FRESH extractions — fully dropped
        // parents counted once, plus one per statement a salvaged parent's
        // window packing silently dropped (E2 r1 MINOR-1). null =
        // "this producer does not track the bucket" (legacy library/
        // worker/hand-built reports), a tracked zero is 0 — the same
        // honesty law as reusedFiles/skippedFiles above.
        public readonly ?int $oversizedBlocks = null,
        // once-S70 design, EMITTED at schema 1.8: the master-side ML pair
        // client's end-of-run connection status (see
        // PipelineState::$mlConnectionStatus for the four values and the
        // fork-zone semantics boundary). null = producer never wired one.
        public readonly ?string $mlStatus = null,
        // Item 4.4 APPLY half (§5f trailing-param law): the baseline
        // appendix — findings ACCEPTED as kept duplication, suppressed
        // from $clusters with acknowledgement. Each row is the
        // BaselineFilter shape (finding_id, status, source, resurfaced,
        // provenance). Empty list = no decisions touched this run; the
        // CLI trailer and HTML appendix render only when rows exist, so
        // the default report is byte-unchanged. The JSON emit rides the
        // next schema-1.8 train (JsonReporter is another lane's live
        // file; it reads this property).
        public readonly array $baselineAppendix = [],
        // Item 3.1 ingestion (MINE-D): the run's idiom-subtree census —
        // the SubtreeCensus the armed PreprocessStage ingests through the
        // SequenceIndex attach point, handed over at the state→Report
        // seam like the once-S37/S70/E2 carriers before it (§5f trailing-
        // param law). Null = "no census was taken" (unarmed demand, or a
        // producer that never ran the armed stage — library API, server
        // workers, hand-built reports); JsonReporter emits the conditional
        // `summary.idiom_census` block ONLY when this is non-null, so the
        // default payload stays byte-identical and the goldens hold. The
        // census object is the substrate's own accumulator — the reporter
        // reads its deterministic views (rows()/maximalRows()/hashCount()/
        // sitesTotal()), never its internals.
        public readonly ?\Phpdup\Census\SubtreeCensus $idiomCensus = null,
        // Item 4.2 (git-history correlation): the per-cluster blame/log
        // sidecar ReportStage computes when `history` is armed — handed
        // over at the state→Report seam like the §5f carriers before it
        // (trailing-param law). Null = "no correlation was asked for"
        // (default posture, or any producer that never ran the armed leg);
        // JsonReporter emits the conditional `summary.cluster_history`
        // block ONLY when this is non-null, so the default payload stays
        // byte-identical and the goldens hold (§8.4: opt-in zero churn).
        public readonly ?\Phpdup\Git\ClusterHistory $clusterHistory = null,
        // Item 5.2 (W2b, vendored third-party awareness): the demoted
        // 'vendored duplicates' section ReportStage computes when the
        // scope filter is armed (see {@see VendoredSection::split()} for
        // the row shape and the doc law behind every field). Same
        // trailing-param law as the carriers above: null = the section
        // was never asked for (scope unarmed = every default run), a
        // zero-shaped array = armed over a vendored-free corpus.
        // JsonReporter emits the conditional `summary.vendored_duplicates`
        // block ONLY when non-null — no default payload moves, no
        // SCHEMA_VERSION event (demand-armed conditional key, MINE-D §4).
        public readonly ?array $vendoredDuplicates = null,
    ) {
    }

    public function totalDuplicatedLines(): int
    {
        $sum = 0;
        foreach ($this->clusters as $c) {
            $sum += $c->totalLines();
        }
        return $sum;
    }
}
