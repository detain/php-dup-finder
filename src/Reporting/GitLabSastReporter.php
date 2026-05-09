<?php
declare(strict_types=1);

namespace Phpdup\Reporting;

use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;

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
    public const SCHEMA_VERSION = '15.0.7';

    public function writeTo(Report $report, string $file): void
    {
        $dir = dirname($file);
        if ($dir !== '' && !is_dir($dir)) {
            @mkdir($dir, 0o775, true);
        }
        file_put_contents(
            $file,
            json_encode($this->build($report), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );
    }

    /** @return array<string, mixed> */
    public function build(Report $report): array
    {
        return [
            'version' => self::SCHEMA_VERSION,
            'scan'    => [
                'scanner' => [
                    'id'      => 'phpdup',
                    'name'    => 'phpdup',
                    'version' => '0.2.0',
                    'vendor'  => ['name' => 'php-dup-finder'],
                    'url'     => 'https://github.com/detain/php-dup-finder',
                ],
                'analyzer' => [
                    'id'      => 'phpdup',
                    'name'    => 'phpdup',
                    'version' => '0.2.0',
                    'vendor'  => ['name' => 'php-dup-finder'],
                ],
                'type'      => 'sast',
                'status'    => 'success',
                'start_time' => gmdate('Y-m-d\TH:i:s'),
                'end_time'   => gmdate('Y-m-d\TH:i:s'),
            ],
            'vulnerabilities' => $this->buildVulnerabilities($report),
        ];
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
            'severity'   => $this->severityFor($cluster->impact),
            'confidence' => $cluster->exact ? 'High' : ($cluster->confidence >= 0.85 ? 'Medium' : 'Low'),
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

    private function severityFor(int $impact): string
    {
        return match (true) {
            $impact > 100         => 'High',
            $impact >= 50         => 'Medium',
            $impact >= 20         => 'Low',
            default               => 'Info',
        };
    }
}
