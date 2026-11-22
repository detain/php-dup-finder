<?php
declare(strict_types=1);

namespace Phpdup\Reporting;

use Phpdup\Util\ControlText;

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
 *   phpdup_duplicates_total          – blocks covered by reported clusters
 *   phpdup_pattern_tag_clusters{tag} – per pattern-tag cluster count
 *   phpdup_severity_clusters{severity} – per severity-tier cluster count
 *   phpdup_kind_blocks{kind}         – per block-kind duplicate count
 *
 * Cardinality posture (output-026): every label set here is bounded by a
 * fixed vocabulary (pattern tags, the four Severity tiers, the block-kind
 * roster), so a cron-fed TSDB keeps a stable series count. The former
 * `phpdup_cluster_impact{id="<content-hash>"}` series is retired: cluster
 * ids are content-derived and change on every edit, so scraping per-id
 * series churns an unbounded set of dead label sets into the DB — the
 * classic high-cardinality anti-pattern. Per-cluster detail (impact,
 * signature, members) stays in the JSON and HTML reports, which are
 * written fresh per run and keep no long-lived label index. Deliberately
 * NO flag re-enables the id series: a retention-warning opt-in still
 * ships a footgun whose cost lands on the operator's TSDB, and JSON/HTML
 * already serve the per-cluster use case (plan ruling, output-S20).
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
        $totalImpact    = 0;
        $duplicates     = 0;
        $tagCounts      = [];
        $severityCounts = [];
        $kindCounts     = [];
        foreach ($report->clusters as $c) {
            $totalImpact += $c->impact;
            $severity     = Severity::forImpact($c->impact);
            $severityCounts[$severity] = ($severityCounts[$severity] ?? 0) + 1;
            foreach ($c->members as $member) {
                $duplicates++;
                $kindCounts[$member->kind] = ($kindCounts[$member->kind] ?? 0) + 1;
            }
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
        $this->gauge($lines, 'phpdup_duplicates_total',        'Blocks covered by reported clusters',            $duplicates);

        $this->labeled($lines, 'phpdup_pattern_tag_clusters', 'tag',      'Clusters tagged with a given refactor pattern', $tagCounts);
        $this->labeled($lines, 'phpdup_severity_clusters',    'severity', 'Clusters per impact severity tier',   $severityCounts);
        $this->labeled($lines, 'phpdup_kind_blocks',          'kind',     'Duplicate blocks per extracted kind', $kindCounts);

        return implode("\n", $lines) . "\n";
    }

    /** @param list<string> $lines */
    private function gauge(array &$lines, string $name, string $help, int $value): void
    {
        $lines[] = "# HELP {$name} {$help}";
        $lines[] = "# TYPE {$name} gauge";
        $lines[] = "{$name} {$value}";
    }

    /**
     * Emit one labeled gauge family with bounded vocabulary: sorted by label
     * so exposition bytes are stable across runs, and skipped entirely when
     * the corpus contributes no value (the posture phpdup_pattern_tag_clusters
     * has always used).
     *
     * @param list<string>     $lines
     * @param array<string,int> $counts
     */
    private function labeled(array &$lines, string $name, string $label, string $help, array $counts): void
    {
        if ($counts === []) {
            return;
        }
        $lines[] = "# HELP {$name} {$help}";
        $lines[] = "# TYPE {$name} gauge";
        ksort($counts);
        foreach ($counts as $value => $n) {
            $lines[] = sprintf(
                '%s{%s=%s} %d',
                $name,
                $label,
                $this->labelValue((string) $value),
                $n,
            );
        }
    }

    /**
     * Quote a label value for the exposition format (output-047): the Go
     * client's escape set — backslash, double quote, LF, CR, TAB are mapped
     * to their two-char escapes, so a control byte in a tag/kind/severity
     * can never split or forge an exposition line (the pre-fix set missed
     * \r, leaving a CR free to break line framing on tolerant readers). The
     * two bytes the format has no escape for at all (NUL, ESC) are
     * substituted with the shared visible marker — the same scrub-arm
     * doctrine as Graphviz labels, via Util\ControlText rather than a
     * forked sanitizer.
     */
    private function labelValue(string $s): string
    {
        $escaped = addcslashes($s, "\"\\\n\r\t");
        return '"' . strtr($escaped, ["\0" => ControlText::SUBSTITUTE, "\x1b" => ControlText::SUBSTITUTE]) . '"';
    }
}
