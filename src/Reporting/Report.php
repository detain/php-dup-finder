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
     * @param list<string> $scanNotes
     * @param list<array{file:string,message:string}> $parseFailures
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
