<?php
declare(strict_types=1);

namespace Phpdup\Reporting;

use Phpdup\Clustering\Cluster;
use Phpdup\Refactor\Hole;

/**
 * Machine-readable JSON dump.
 */
final class JsonReporter
{
    public function writeTo(Report $report, string $file): void
    {
        $dir = dirname($file);
        if ($dir !== '' && !is_dir($dir)) {
            @mkdir($dir, 0o775, true);
        }
        $payload = $this->build($report);
        file_put_contents($file, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /** @return array<string, mixed> */
    public function build(Report $report): array
    {
        return [
            'phpdup_version' => '0.1.0',
            'summary' => [
                'files'        => $report->files,
                'blocks'       => $report->blocks,
                'parse_errors' => $report->parseErrors,
                'clusters'     => count($report->clusters),
                'duplicated_lines' => $report->totalDuplicatedLines(),
                'total_impact' => array_sum(array_map(fn(Cluster $c) => $c->impact, $report->clusters)),
            ],
            'config' => [
                'paths'                => $report->config->paths,
                'min_block_size'       => $report->config->minBlockSize,
                'normalization_mode'   => $report->config->normalizationMode,
                'similarity_threshold' => $report->config->similarityThreshold,
                'tree_threshold'       => $report->config->treeThreshold,
                'min_cluster_impact'   => $report->config->minClusterImpact,
            ],
            'clusters' => array_map([$this, 'clusterPayload'], $report->clusters),
        ];
    }

    /** @return array<string, mixed> */
    private function clusterPayload(Cluster $c): array
    {
        return [
            'id'           => $c->id,
            'kind'         => $c->members[0]->kind ?? null,
            'exact'        => $c->exact,
            'similarity'   => round($c->similarity, 4),
            'confidence'   => round($c->confidence, 4),
            'impact'       => $c->impact,
            'pattern_tags' => $c->patternTags,
            'signature'    => $c->signature,
            'members' => array_map(static fn($m) => [
                'file'      => $m->file,
                'start'     => $m->range->start,
                'end'       => $m->range->end,
                'kind'      => $m->kind,
                'namespace' => $m->namespace,
                'class'     => $m->class,
                'name'      => $m->name,
                'size'      => $m->size,
            ], $c->members),
            'holes' => array_map(static function (Hole $h) {
                $base = [
                    'placeholder'    => $h->placeholder,
                    'kind'           => $h->kind,
                    'inferred_type'  => $h->inferredType,
                    'suggested_name' => $h->suggestedName,
                    'observed'       => array_values(array_unique($h->observedValues)),
                    'value_count'    => $h->uniqueValueCount(),
                ];
                if ($h->kind === 'optional_block') {
                    $present = [];
                    foreach ($h->observedValues as $i => $v) {
                        if ($v !== '<absent>') $present[] = $i;
                    }
                    $base['present_in_members'] = $present;
                }
                return $base;
            }, $c->holes),
        ];
    }
}
