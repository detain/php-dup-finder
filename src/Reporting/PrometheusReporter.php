<?php
declare(strict_types=1);

namespace Phpdup\Reporting;

use Phpdup\Clustering\Cluster;

/**
 * Prometheus text-format exposition.
 *
 * Emits gauges suitable for scraping by a Prometheus pushgateway or
 * for CI-time ingestion into a metrics dashboard:
 *
 *   phpdup_clusters_total            – number of reported clusters
 *   phpdup_duplicated_lines_total    – sum of lines covered by clusters
 *   phpdup_total_impact              – ranker impact (≈ lines saved)
 *   phpdup_files_scanned             – total files in the corpus
 *   phpdup_blocks_total              – total comparable blocks
 *   phpdup_parse_errors_total        – files that failed to parse
 *   phpdup_pattern_tag_clusters{tag} – per pattern-tag cluster count
 *   phpdup_cluster_impact{id}        – per-cluster impact (a few labels)
 *
 * Format conforms to the Prometheus text-based exposition format
 * (https://prometheus.io/docs/instrumenting/exposition_formats/).
 */
final class PrometheusReporter
{
    use WritesReportFile;

    public function writeTo(Report $report, string $file): void
    {
        $this->writeFile($file, $this->build($report));
    }

    public function build(Report $report): string
    {
        $totalImpact = 0;
        $tagCounts   = [];
        foreach ($report->clusters as $c) {
            $totalImpact += $c->impact;
            foreach ($c->patternTags as $tag) {
                $tagCounts[$tag] = ($tagCounts[$tag] ?? 0) + 1;
            }
        }

        $lines = [];
        $this->gauge($lines, 'phpdup_clusters_total',          'Number of duplicate clusters reported',          count($report->clusters));
        $this->gauge($lines, 'phpdup_duplicated_lines_total',  'Total source lines covered by reported clusters', $report->totalDuplicatedLines());
        $this->gauge($lines, 'phpdup_total_impact',            'Sum of cluster impact (≈ lines that could be saved)', $totalImpact);
        $this->gauge($lines, 'phpdup_files_scanned',           'Files included in the analysis',                 $report->files);
        $this->gauge($lines, 'phpdup_blocks_total',            'Total comparable blocks extracted',              $report->blocks);
        $this->gauge($lines, 'phpdup_parse_errors_total',      'Files that failed to parse',                     $report->parseErrors);

        if ($tagCounts !== []) {
            $lines[] = '# HELP phpdup_pattern_tag_clusters Clusters tagged with a given refactor pattern';
            $lines[] = '# TYPE phpdup_pattern_tag_clusters gauge';
            ksort($tagCounts);
            foreach ($tagCounts as $tag => $n) {
                $lines[] = sprintf(
                    'phpdup_pattern_tag_clusters{tag=%s} %d',
                    $this->labelValue($tag),
                    $n,
                );
            }
        }

        if ($report->clusters !== []) {
            $lines[] = '# HELP phpdup_cluster_impact Per-cluster impact score';
            $lines[] = '# TYPE phpdup_cluster_impact gauge';
            foreach ($report->clusters as $c) {
                $lines[] = sprintf(
                    'phpdup_cluster_impact{id=%s,kind=%s} %d',
                    $this->labelValue($c->id),
                    $this->labelValue($c->members[0]->kind ?? ''),
                    $c->impact,
                );
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /** @param list<string> $lines */
    private function gauge(array &$lines, string $name, string $help, int $value): void
    {
        $lines[] = "# HELP {$name} {$help}";
        $lines[] = "# TYPE {$name} gauge";
        $lines[] = "{$name} {$value}";
    }

    private function labelValue(string $s): string
    {
        return '"' . addcslashes($s, "\"\\\n") . '"';
    }
}
