<?php
declare(strict_types=1);

namespace Phpdup\Reporting;

use Phpdup\Clustering\Cluster;
use Phpdup\Refactor\Hole;
use Phpdup\Util\Num;

/**
 * Machine-readable JSON dump.
 */
final class JsonReporter
{
    use WritesReportFile;

    public function writeTo(Report $report, string $file): void
    {
        $this->writeFile($file, json_encode($this->build($report), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /** @return array<string, mixed> */
    public function build(Report $report): array
    {
        return [
            'phpdup_version' => '0.1.0',
            'schema_version' => JsonSchemaSpec::SCHEMA_VERSION,
            'summary' => [
                'files'        => $report->files,
                'blocks'       => $report->blocks,
                'parse_errors' => $report->parseErrors,
                'clusters'     => count($report->clusters),
                'duplicated_lines' => $report->totalDuplicatedLines(),
                'total_impact' => array_sum(array_map(fn(Cluster $c) => $c->impact, $report->clusters)),
                // File accounting (0.6) — trailing-additive keys, schema
                // '1.1' → '1.2'. 'files' above keeps its historical meaning
                // (files scanned) and 'files_scanned' restates it under an
                // unambiguous name. null = the producer does not track the
                // bucket (legacy `new Report(...)` callers such as the
                // library API and server worker); a tracked zero is 0.
                // Balance law when all counters are tracked:
                //   files_scanned = files_reused + files_processed
                //                 + files_without_blocks + parse_errors.
                'files_scanned'        => $report->files,
                'files_reused'         => $report->reusedFiles,
                'files_processed'      => $report->processedFiles,
                'files_without_blocks' => $report->skippedFiles,
            ],
            'config' => [
                'paths'                => $report->config->paths,
                'min_block_size'       => $report->config->minBlockSize,
                'normalization_mode'   => $report->config->normalizationMode,
                'similarity_threshold' => Num::json($report->config->similarityThreshold),
                'tree_threshold'       => Num::json($report->config->treeThreshold),
                'min_cluster_impact'   => $report->config->minClusterImpact,
            ],
            'clusters' => array_map([$this, 'clusterPayload'], $report->clusters),
            // Item 4.3: the declared-twin claim-vs-reality table —
            // trailing-additive key, schema '1.2' → '1.3'. Producers
            // without the analyzer pass emit [] (the key is always
            // present so consumers can bind on it).
            'declared_twins' => $report->declaredTwins,
        ];
    }

    /** @return array<string, mixed> */
    private function clusterPayload(Cluster $c): array
    {
        return [
            'id'           => $c->id,
            'kind'         => $c->members[0]->kind ?? null,
            'exact'        => $c->exact,
            'similarity'   => Num::json($c->similarity),
            'confidence'   => Num::json($c->confidence),
            'safety'       => Num::json($c->safety),
            'impact'       => $c->impact,
            'pattern_tags' => $c->patternTags,
            'outlier_members' => $c->outlierMemberIds,
            'architectural_findings' => array_map(static fn(\Phpdup\Architecture\Finding $f) => [
                'analyzer'   => $f->analyzer,
                'code'       => $f->code,
                'severity'   => $f->severity,
                'message'    => $f->message,
                'suggestion' => $f->suggestion,
            ], $c->architecturalFindings),
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
            'pairs' => array_map(static fn(array $p) => [
                'blockA'     => $p['blockA'],
                'blockB'     => $p['blockB'],
                'matchTier'  => $p['matchTier'],
                'matchScore' => Num::json($p['matchScore']),
            ], $c->pairs),
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
