<?php
declare(strict_types=1);

namespace Phpdup\Reporting;

use Phpdup\Clustering\Cluster;

/**
 * Append-only JSONL: one summary row per analysis run.
 *
 * Designed to be fed into a time-series store (Elasticsearch, ClickHouse,
 * BigQuery, …) so a project can track its duplicate-debt curve over
 * commits / time. The file is append-only, so multiple CI runs can
 * write to the same path without trampling each other.
 *
 * Each row carries the run's identity (commit sha when available, ISO
 * timestamp), the corpus shape (files / blocks / parse errors), and the
 * top-line cluster aggregates (count, duplicated lines, total impact,
 * per-tag counters).
 */
final class TimeseriesReporter
{
    use WritesReportFile;

    public function __construct(
        private readonly ?string $commitSha = null,
        private readonly ?int $timestamp = null,
    ) {
    }

    public function writeTo(Report $report, string $file): void
    {
        $this->ensureDir($file);
        file_put_contents($file, $this->buildLine($report) . "\n", FILE_APPEND | LOCK_EX);
    }

    public function buildLine(Report $report): string
    {
        return (string)json_encode($this->buildRecord($report), JSON_UNESCAPED_SLASHES);
    }

    /** @return array<string,mixed> */
    public function buildRecord(Report $report): array
    {
        $totalImpact = 0;
        $tagCounts   = [];
        foreach ($report->clusters as $c) {
            $totalImpact += $c->impact;
            foreach ($c->patternTags as $tag) {
                $tagCounts[$tag] = ($tagCounts[$tag] ?? 0) + 1;
            }
        }
        ksort($tagCounts);

        return [
            'timestamp'        => date('c', $this->timestamp ?? time()),
            'commit_sha'       => $this->commitSha ?? $this->detectCommitSha(),
            'phpdup_version'   => '0.1.0',
            'files'            => $report->files,
            'blocks'           => $report->blocks,
            'parse_errors'     => $report->parseErrors,
            'clusters'         => count($report->clusters),
            'duplicated_lines' => $report->totalDuplicatedLines(),
            'total_impact'     => $totalImpact,
            'pattern_tags'     => $tagCounts,
        ];
    }

    private function detectCommitSha(): ?string
    {
        $env = getenv('GIT_COMMIT')
            ?: getenv('GITHUB_SHA')
            ?: getenv('CI_COMMIT_SHA')
            ?: getenv('BUILD_VCS_NUMBER');
        if (is_string($env) && $env !== '') {
            return $env;
        }
        // Fall back to local `.git/HEAD` resolution. Avoids shelling out so
        // the reporter stays usable inside sandboxed CI runners.
        $head = @file_get_contents(getcwd() . '/.git/HEAD');
        if ($head === false) {
            return null;
        }
        $head = trim($head);
        if (str_starts_with($head, 'ref: ')) {
            $ref = substr($head, 5);
            $sha = @file_get_contents(getcwd() . '/.git/' . $ref);
            return is_string($sha) ? trim($sha) : null;
        }
        return $head !== '' ? $head : null;
    }
}
