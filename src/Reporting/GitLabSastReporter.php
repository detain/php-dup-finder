<?php
declare(strict_types=1);

namespace Phpdup\Reporting;

use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Util\Json;
use Phpdup\Util\Version;

/**
 * GitLab SAST report (schema v15.x).
 *
 * Each duplicate block region becomes a `vulnerabilities[]` entry; cluster
 * impact maps onto the SAST severity buckets so high-impact duplicates are
 * surfaced at the top of GitLab's MR security widget.
 *
 * Schema: https://gitlab.com/gitlab-org/security-products/security-report-schemas
 */
final class GitLabSastReporter
{
    use WritesReportFile;

    public const SCHEMA_VERSION = '15.0.7';

    public function writeTo(Report $report, string $file): void
    {
        $this->writeFile($file, Json::encode($this->build($report), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /** @return array<string, mixed> */
    public function build(Report $report): array
    {
        $scan = [
            'scanner' => [
                'id'      => 'phpdup',
                'name'    => 'phpdup',
                'version' => Version::STRING,
                'vendor'  => ['name' => 'php-dup-finder'],
                'url'     => 'https://github.com/detain/php-dup-finder',
            ],
            'analyzer' => [
                'id'      => 'phpdup',
                'name'    => 'phpdup',
                'version' => Version::STRING,
                'vendor'  => ['name' => 'php-dup-finder'],
            ],
            'type'      => 'sast',
            'status'    => 'success',
        ];

        // once-S59: the timestamps are the scan's own measured metadata
        // (recorded by the pipeline, never read here). Two builds over the
        // same Report are byte-identical, and a real run always shows
        // end_time > start_time — microseconds survive formatting, so the
        // two are equal only for a truly zero-duration window. A Report
        // without pipeline metadata (library API, hand-built) carries no
        // window: the fields are then omitted honestly, not fabricated
        // from a build-time clock.
        //
        // RFC3339 UTC with the explicit 'Z' designator (output-S04): naive
        // 'gmdate(...)' output reads like UTC but strict date-time
        // validators reject a timestamp without an offset.
        $window = $report->scanWindow;
        if ($window !== null) {
            $scan['start_time'] = self::formatTimestamp($window->startedAt);
            $scan['end_time']   = self::formatTimestamp($window->endedAt);
        }

        return [
            'version'           => self::SCHEMA_VERSION,
            'scan'              => $scan,
            'vulnerabilities'   => $this->buildVulnerabilities($report),
        ];
    }

    /**
     * Epoch seconds (UTC, float) → RFC3339 'Z' timestamp using the shortest
     * fractional form that keeps the instant exact (whole seconds print with
     * no fraction at all). Microsecond resolution is what preserves the
     * once-S59 guarantee that start == end only for a genuinely zero-length
     * window.
     */
    private static function formatTimestamp(float $epoch): string
    {
        $secs  = (int) floor($epoch);
        $micro = (int) round(($epoch - $secs) * 1_000_000);
        if ($micro >= 1_000_000) { // 0.9999996-type float edges round up
            $secs++;
            $micro = 0;
        }

        $clock = gmdate('Y-m-d\TH:i:s\Z', $secs);
        if ($micro === 0) {
            return $clock;
        }

        // 'Z' sits after the fraction per RFC3339: strip it, splice.
        return substr($clock, 0, -1) . rtrim(sprintf('.%06d', $micro), '0') . 'Z';
    }

    /** @return list<array<string, mixed>> */
    private function buildVulnerabilities(Report $report): array
    {
        $out = [];
        foreach ($report->clusters as $cluster) {
            foreach ($cluster->members as $member) {
                $out[] = $this->vulnFor($cluster, $member);
            }
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private function vulnFor(Cluster $cluster, Block $member): array
    {
        return [
            'id'       => sprintf('%s::%s', $cluster->id, $member->id),
            'category' => 'sast',
            'name'     => 'Duplicated logic',
            'message'  => sprintf(
                'Block is part of cluster %s (%d members, similarity %.2f).',
                $cluster->id, count($cluster->members), $cluster->similarity,
            ),
            'description' => trim(sprintf(
                "phpdup found %d structurally-similar blocks. Consider extracting a shared abstraction:\n\n%s",
                count($cluster->members),
                (string)$cluster->signature,
            )),
            'severity'   => Severity::forImpact($cluster->impact),
            'confidence' => $cluster->exact ? 'High' : Severity::forScore($cluster->confidence),
            'scanner' => [
                'id'   => 'phpdup',
                'name' => 'phpdup',
            ],
            'location' => [
                'file'       => $member->file,
                'start_line' => $member->range->start,
                'end_line'   => $member->range->end,
            ],
            'identifiers' => [
                [
                    'type'  => 'phpdup_cluster',
                    'name'  => 'phpdup cluster ' . $cluster->id,
                    'value' => $cluster->id,
                    'url'   => 'https://github.com/detain/php-dup-finder',
                ],
                [
                    'type'  => 'phpdup_structural_hash',
                    'name'  => 'phpdup structural hash',
                    'value' => $member->structuralHash,
                ],
            ],
        ];
    }

}
