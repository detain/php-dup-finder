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
