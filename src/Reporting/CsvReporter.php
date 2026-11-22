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
    use WritesReportFile;

    /** @var list<string> */
    private const HEADER = [
        'cluster_id', 'members', 'similarity', 'confidence', 'impact',
        'file', 'start', 'end', 'kind', 'namespace', 'class', 'name',
        'signature', 'pattern_tags',
    ];

    public function writeTo(Report $report, string $file): void
    {
        $this->writeFile($file, $this->build($report));
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
        $cell = self::defuseFormula($cell);
        if (preg_match('/[",\r\n]/', $cell) === 1) {
            return '"' . str_replace('"', '""', $cell) . '"';
        }
        return $cell;
    }

    /** Spreadsheet formula/DDE lead bytes (first-char triggers). */
    private const FORMULA_LEADS = "=+-@\t\r";

    /**
     * S6 formula-neutralization — the plan's shared guard shape (output-035).
     *
     * Prefixes a value whose FIRST character is a formula trigger (`=`, `+`,
     * `-`, `@`, tab, CR) — or whose FIRST character after leading whitespace
     * is one (once-064/TPL-07: ` =cmd` escorts the payload past a raw
     * first-char check, and spreadsheet apps skip leading spaces/tabs when
     * deciding whether a cell is a formula) — with the text-marker `'` so
     * consumers ingest it as literal text, never as a formula. Byte-class
     * copied from the original {@see self::escape()} guard; this method is
     * the venue every CSV-bound reporter routes user-derived text through:
     * Csv (all cells), Diff, RefactorPatch, RefactorTest metadata rows
     * (output-S22), and the Checkstyle name/message cells plus the GitLab
     * SAST location/message/description legs (once-S58) — one sanitizer
     * family under the ControlText doctrine, never a forked second guard.
     *
     * Scope law (the raw-hunk carve-out): metadata/tabular TEXT values only.
     * Never apply this to unified-diff body or header bytes, or to positions
     * inside generated PHP code, or to schema-contracted key fields — those
     * are parser-consumed structure where a spurious `'` corrupts the
     * artifact. A later lane may promote the venue to src/Util.
     */
    public static function defuseFormula(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        // Raw lead first: \t and \r are themselves classic DDE-delimiter
        // leads, and ltrim would erase exactly the evidence they carry.
        if (strpbrk($value[0], self::FORMULA_LEADS) !== false) {
            return "'" . $value;
        }

        $stripped = ltrim($value);
        if ($stripped !== '' && strpbrk($stripped[0], self::FORMULA_LEADS) !== false) {
            return "'" . $value;
        }

        return $value;
    }
}
