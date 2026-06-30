<?php
declare(strict_types=1);

namespace Phpdup\Reporting;

use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;

/**
 * Flat CSV: one row per cluster member.
 *
 * Columns: cluster_id, members, similarity, confidence, impact, file,
 * start, end, kind, namespace, class, name, signature, pattern_tags
 *
 * Designed for ingestion by spreadsheets, BI tools, or jq-equivalents
 * for tabular data. No multi-line embedding — each cell is escaped with
 * RFC 4180 quoting.
 */
final class CsvReporter
{
    /** @var list<string> */
    private const HEADER = [
        'cluster_id', 'members', 'similarity', 'confidence', 'impact',
        'file', 'start', 'end', 'kind', 'namespace', 'class', 'name',
        'signature', 'pattern_tags',
    ];

    public function writeTo(Report $report, string $file): void
    {
        $dir = dirname($file);
        if ($dir !== '' && !is_dir($dir)) {
            @mkdir($dir, 0o775, true);
        }
        file_put_contents($file, $this->build($report));
    }

    public function build(Report $report): string
    {
        $rows = [self::HEADER];
        foreach ($report->clusters as $cluster) {
            foreach ($cluster->members as $member) {
                $rows[] = $this->row($cluster, $member);
            }
        }
        $out = '';
        foreach ($rows as $row) {
            $out .= implode(',', array_map([$this, 'escape'], $row)) . "\n";
        }
        return $out;
    }

    /** @return list<string> */
    private function row(Cluster $cluster, Block $member): array
    {
        return [
            $cluster->id,
            (string)count($cluster->members),
            number_format($cluster->similarity, 4, '.', ''),
            number_format($cluster->confidence, 4, '.', ''),
            (string)$cluster->impact,
            $member->file,
            (string)$member->range->start,
            (string)$member->range->end,
            $member->kind,
            $member->namespace ?? '',
            $member->class ?? '',
            $member->name ?? '',
            $this->oneLine($cluster->signature ?? ''),
            implode('|', $cluster->patternTags),
        ];
    }

    /**
     * Collapse multi-line signatures to a single line so CSV consumers
     * (spreadsheets, BI ingest pipelines) get one row per record without
     * having to handle embedded newlines.
     */
    private function oneLine(string $s): string
    {
        return trim((string)preg_replace('/\s+/', ' ', $s));
    }

    private function escape(string $cell): string
    {
        // Formula injection guard: prefix cells starting with formula-
        // trigger characters with a text-prefix (') so spreadsheets
        // treat them as literal text, not formulas.
        if ($cell !== '' && strpbrk($cell[0], "=+-@\t\r") !== false) {
            $cell = "'" . $cell;
        }
        if (preg_match('/[",\r\n]/', $cell) === 1) {
            return '"' . str_replace('"', '""', $cell) . '"';
        }
        return $cell;
    }
}
