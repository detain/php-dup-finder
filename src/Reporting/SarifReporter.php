<?php
declare(strict_types=1);

namespace Phpdup\Reporting;

use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;

/**
 * SARIF 2.1.0 output for GitHub / GitLab PR annotations.
 *
 * Each duplicate block in a cluster is emitted as a separate `result`
 * sharing a `partialFingerprints.clusterId`, so consumers can group them.
 * The cluster's suggested signature lives in `properties.suggestedSignature`.
 *
 * Schema: https://docs.oasis-open.org/sarif/sarif/v2.1.0/os/sarif-v2.1.0-os.html
 */
final class SarifReporter
{
    use WritesReportFile;

    public const SCHEMA  = 'https://raw.githubusercontent.com/oasis-tcs/sarif-spec/main/Schemata/sarif-schema-2.1.0.json';
    public const VERSION = '2.1.0';

    public function writeTo(Report $report, string $file): void
    {
        $this->writeFile($file, json_encode($this->build($report), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /** @return array<string, mixed> */
    public function build(Report $report): array
    {
        return [
            '$schema' => self::SCHEMA,
            'version' => self::VERSION,
            'runs'    => [
                [
                    'tool' => [
                        'driver' => [
                            'name'           => 'phpdup',
                            'informationUri' => 'https://github.com/detain/php-dup-finder',
                            'version'        => '0.2.0',
                            'rules'          => [
                                [
                                    'id'   => 'phpdup/duplicate-logic',
                                    'name' => 'DuplicateLogic',
                                    'shortDescription' => [
                                        'text' => 'Block of code is structurally similar to one or more other blocks.',
                                    ],
                                    'fullDescription' => [
                                        'text' => 'phpdup detected near- or exact-duplicate logic. Consider extracting a shared abstraction.',
                                    ],
                                    'helpUri'              => 'https://github.com/detain/php-dup-finder',
                                    'defaultConfiguration' => ['level' => 'warning'],
                                ],
                            ],
                        ],
                    ],
                    'results' => $this->buildResults($report),
                ],
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function buildResults(Report $report): array
    {
        $results = [];
        foreach ($report->clusters as $cluster) {
            foreach ($cluster->members as $member) {
                $results[] = $this->resultFor($cluster, $member);
            }
        }
        return $results;
    }

    private function countOptionalSegments(Cluster $cluster): int
    {
        $n = 0;
        foreach ($cluster->holes as $h) {
            if ($h->kind === 'optional_block') $n++;
        }
        return $n;
    }

    /** @return array<string, mixed> */
    private function resultFor(Cluster $cluster, Block $member): array
    {
        return [
            'ruleId'  => 'phpdup/duplicate-logic',
            'level'   => $cluster->exact ? 'warning' : 'note',
            'message' => [
                'text' => sprintf(
                    'Cluster %s — %d members, similarity %.2f, impact %d. Suggested abstraction: %s',
                    $cluster->id,
                    count($cluster->members),
                    $cluster->similarity,
                    $cluster->impact,
                    str_replace("\n", ' ', (string)$cluster->signature),
                ),
            ],
            'locations' => [
                [
                    'physicalLocation' => [
                        'artifactLocation' => ['uri' => $member->file],
                        'region' => [
                            'startLine' => $member->range->start,
                            'endLine'   => $member->range->end,
                        ],
                    ],
                ],
            ],
            'partialFingerprints' => [
                'clusterId'      => $cluster->id,
                'structuralHash' => $member->structuralHash,
            ],
            'properties' => array_filter([
                'kind'               => $member->kind,
                'clusterKind'        => $cluster->members[0]->kind ?? null,
                'similarity'         => $cluster->similarity,
                'exact'              => $cluster->exact,
                'impact'             => $cluster->impact,
                'memberCount'        => count($cluster->members),
                'patternTags'        => $cluster->patternTags,
                'suggestedSignature' => (string)$cluster->signature,
                // Type-3 / optional-segment metadata so PR-annotation tooling
                // can flag these clusters distinctly from "exact + variables".
                'optionalSegmentCount' => $this->countOptionalSegments($cluster),
                'hasOptionalSegments'  => in_array('optional-segments', $cluster->patternTags, true),
            ], static fn($v) => $v !== null),
        ];
    }
}
