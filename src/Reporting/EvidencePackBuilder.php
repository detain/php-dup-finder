<?php
declare(strict_types=1);

namespace Phpdup\Reporting;

use Phpdup\Clustering\Cluster;
use Phpdup\Census\HelperCopy;
use Phpdup\Extraction\Block;
use Phpdup\Refactor\HoleCoverage;
use Phpdup\Util\ControlText;
use Phpdup\Util\Json;
use Phpdup\Util\PathCanonical;
use PhpParser\Node;
use PhpParser\PrettyPrinter\Standard as PrettyPrinter;
use SebastianBergmann\Diff\Differ;
use SebastianBergmann\Diff\Output\UnifiedDiffOutputBuilder;

/**
 * Item 8.1 (improve_logic.md): evidence packs — a JSON document per finding
 * bundling everything a reviewer (human or LLM) needs to judge the clone
 * without opening the tree: members with source excerpts, the generalized
 * template, a per-member diff against that template, drift flags (4.1),
 * git history (4.2), adoption counts (3.2) and scope (5.1), size-capped and
 * ranked.
 *
 * Standalone FILE artifact by design (§8.4: "prefer file output over JSON
 * growth"): nothing is added to the report JSON, so the schema stays 1.8
 * and the golden fleet is untouched — the pack surface lives entirely
 * behind the opt-in `--evidence-packs DIR` sink.
 *
 * Substrate law (the whole point of Phase 8 riding Phases 2-6): every field
 * here is assembled from fields the pipeline ALREADY produces — the 4.1
 * drift findings, the 4.2 history sidecar, the 3.2 adoption census rows, the
 * 5.1 scope labels, the 4.4 FindingId derivation. Nothing is re-mined and
 * no second git process is ever spawned (the H-sibling GitCli caution:
 * reuse GitCli through the sidecar, never fork anew).
 *
 * Keying law (Appendix conflict-7 ruling): pack files key on the durable
 * FindingId, never on Cluster::$id (run-scoped). The id is still carried
 * inside the document for same-run cross-reference with the JSON report —
 * disclosed as such in the doc's notes.
 *
 * side_effects omission (info-doc Phase-8 matrix): the MutationTester
 * probe is unwired in this build, so the letter's side-effects field is
 * honestly ABSENT — stated in the doc's notes rather than silently skipped.
 *
 * Failure postures mirror the family: ControlText::reject BEFORE any store
 * read or directory creation (a forge-byte path is a named refusal, never
 * a partial artifact set), WritesReportFile fail-loud for every write, and
 * per-field truncation flags so a capped excerpt can never masquerade as a
 * complete one.
 */
final class EvidencePackBuilder
{
    use WritesReportFile;

    /** Artifact schema marker (standalone key space — NOT the report schema). */
    public const SCHEMA = 'evidence_pack';

    /** Document format version; bump on any shape change. */
    public const PACK_VERSION = '1';

    /**
     * Top-K cap: packs are emitted for the K highest-ranked findings only
     * ("size-capped, ranked" — the item letter's own bound, mirroring
     * ClusterHistory::MAX_ROWS as the house count cap).
     */
    public const TOP_K = 100;

    private const SOURCE_MAX_LINES = 200;
    private const SOURCE_MAX_BYTES = 8000;
    private const TEMPLATE_MAX_LINES = 200;
    private const TEMPLATE_MAX_BYTES = 8000;
    private const DIFF_MAX_LINES = 120;
    private const DIFF_MAX_BYTES = 6000;
    private const ADOPTION_MAX_ROWS = 50;
    private const HOLE_MAX_ROWS = 64;

    /** The printer-normalization disclosure carried with every template (§2 matrix letter). */
    private const TEMPLATE_FIDELITY = 'template text is printer-normalized AST output, not source bytes';

    private ?PrettyPrinter $printer = null;

    /**
     * @param list<HelperCopy>|null $adoptionRows the 3.2 census rows (the
     *        same list PlacementAdvisor joins against), or null when the
     *        adoption probe was never armed on the run — the honest absence.
     */
    public function __construct(
        private readonly ?SourceTextStore $sourceText = null,
        private readonly ?array $adoptionRows = null,
    ) {
    }

    /**
     * House entrypoint: render every pack through the pure {@see build()}
     * seam and land the files fail-loud under $dir.
     */
    public function writeTo(Report $report, string $dir): void
    {
        $this->requireStreamSafe($report);
        $this->ensureDirectory($dir);
        foreach ($this->build($report) as $name => $contents) {
            $this->writeFile($dir . DIRECTORY_SEPARATOR . $name, $contents);
        }
    }

    /**
     * Pure multi-file seam (reporting-pattern shape): filename => JSON text,
     * in ranked emission order. Nothing is written and no source is read
     * here beyond the shared store; rank reflects the cluster's 1-based
     * position in $report->clusters (the Ranker's final order, baseline and
     * vendored splits already applied upstream).
     *
     * @return array<string,string> '<rank>-F<hex>.pack.json' => JSON document
     */
    public function build(Report $report): array
    {
        $store = $this->sourceText ?? new SourceTextStore($report->config->paths);
        $files = [];
        $rank  = 0;
        $made  = 0;
        foreach ($report->clusters as $cluster) {
            $rank++;
            if ($made >= self::TOP_K) {
                break;
            }
            if (count($cluster->members) < 2) {
                continue;
            }
            $findingId = FindingId::for($cluster, $report->config->paths);
            $files[sprintf('%03d-%s.pack.json', $rank, $findingId)] =
                Json::encode($this->packDoc($cluster, $rank, $findingId, $store, $report), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
            $made++;
        }
        return $files;
    }

    /**
     * @return array<string,mixed>
     */
    private function packDoc(Cluster $cluster, int $rank, string $findingId, SourceTextStore $store, Report $report): array
    {
        $template = $this->templateSection($cluster);

        $members = [];
        foreach ($cluster->members as $m) {
            $members[] = $this->memberSection($m, $store);
        }

        $doc = [
            'schema'          => self::SCHEMA,
            'pack_version'    => self::PACK_VERSION,
            'finding_id'      => $findingId,
            'cluster_id'      => $cluster->id,
            'rank'            => $rank,
            'similarity'      => $cluster->similarity,
            'exact'           => $cluster->exact,
            'impact'          => $cluster->impact,
            'confidence'      => $cluster->confidence,
            'safety'          => $cluster->safety,
            'score'           => $cluster->score,
            'signature'       => $cluster->signature,
            'pattern_tags'    => $cluster->patternTags,
            'outlier_members' => $cluster->outlierMemberIds,
            'members'         => $members,
            'template'        => $template,
            'member_diffs'    => $this->diffSection($cluster, $template, $store),
            'drift_flags'     => $this->driftSection($cluster),
            'history'         => $this->historySection($cluster, $report),
            'adoption'        => $this->adoptionSection($cluster),
            'scope'           => [
                'level' => ClusterScope::of($cluster),
                'repos' => ClusterScope::repos($cluster),
            ],
            'notes'           => [
                'cluster_id is run-scoped; finding_id (item 4.4) is the durable key across runs.',
                'history rows join on the same-run cluster id; a stale sidecar cannot mis-join because rows ride the Report built this run.',
                'side_effects omitted: the MutationTester probe is unwired in this build (improve_logic_info.md Phase-8 matrix).',
            ],
        ];

        if ($cluster->subsumes !== []) {
            $doc['subsumes'] = $cluster->subsumes;
        }
        if ($cluster->subsumedBy !== null) {
            $doc['subsumed_by'] = $cluster->subsumedBy;
        }
        return $doc;
    }

    /**
     * The generalized template: PrettyPrinter over the AntiUnifier seed AST
     * (the doc-matrix shape), with the hole roster as the abstraction's
     * parameter surface. A run without loaded ASTs (lazy-AST stripped) says
     * so honestly instead of printing nothing.
     *
     * @return array<string,mixed>
     */
    private function templateSection(Cluster $cluster): array
    {
        $template = $cluster->generalizedAst;
        if (!$template instanceof Node) {
            return [
                'available' => false,
                'reason'    => 'generalized AST not present (lazy-AST run or cache-stripped cluster)',
            ];
        }
        $printer = $this->printer ??= new PrettyPrinter();
        $text    = $template instanceof Node\Expr ? $printer->prettyPrintExpr($template) : $printer->prettyPrint([$template]);
        [$text, $truncated] = self::cap($text, self::TEMPLATE_MAX_LINES, self::TEMPLATE_MAX_BYTES);

        $holes = [];
        foreach (array_slice($cluster->holes, 0, self::HOLE_MAX_ROWS) as $hole) {
            $holes[] = [
                'placeholder'  => $hole->placeholder,
                'kind'         => $hole->kind,
                'inferred'     => $hole->inferredType,
                'degraded'     => $hole->degraded,
                'unique_values' => $hole->uniqueValueCount(),
            ];
        }

        $canonical = HoleCoverage::canonicalTemplate($cluster);
        return [
            'available'         => true,
            'text'              => $text,
            'truncated'         => $truncated,
            'fidelity'          => self::TEMPLATE_FIDELITY,
            'canonical_member'  => $canonical?->id,
            'holes'             => $holes,
            'holes_truncated'   => count($cluster->holes) > self::HOLE_MAX_ROWS,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function memberSection(Block $m, SourceTextStore $store): array
    {
        $row = [
            'id'        => $m->id,
            'file'      => $m->file,
            'start'     => $m->range->start,
            'end'       => $m->range->end,
            'kind'      => $m->kind,
            'namespace' => $m->namespace,
            'class'     => $m->class,
            'name'      => $m->name,
            'size'      => $m->size,
            'repo'      => $m->repo,
            'scope'     => $m->scope,
            'vendored'  => $m->vendored,
        ];
        $source = DiffReporter::sourceOf($m, $store);
        if ($source === null) {
            $row['source']        = null;
            $row['source_note']   = SourceTextStore::UNFETCHED . ' (' . ($store->reason($m->file) ?? 'unavailable') . ')';
            $row['truncated']     = false;
            return $row;
        }
        [$capped, $truncated] = self::cap($source, self::SOURCE_MAX_LINES, self::SOURCE_MAX_BYTES);
        $row['source']        = $capped;
        $row['truncated']     = $truncated;
        return $row;
    }

    /**
     * Per-member unified diff against the template text — the reviewer's
     * "what does the abstraction actually capture" view. When the template
     * is unavailable the section states the posture instead of diffing
     * against placeholder bytes (output-016 law).
     *
     * @param array<string,mixed> $template
     * @return list<array<string,mixed>>
     */
    private function diffSection(Cluster $cluster, array $template, SourceTextStore $store): array
    {
        if (($template['available'] ?? false) !== true) {
            return [];
        }
        $templateText = (string) $template['text'];
        $aOperand     = Slug::safe($cluster->id) . '.template';

        $diffs = [];
        foreach ($cluster->members as $m) {
            ControlText::reject($m->file, 'EvidencePackBuilder', 'member file path');
            $source = DiffReporter::sourceOf($m, $store);
            if ($source === null) {
                $diffs[] = ['member' => $m->id, 'diff' => null, 'note' => SourceTextStore::UNFETCHED . ' (' . ($store->reason($m->file) ?? 'unavailable') . ')'];
                continue;
            }
            if ($source === $templateText) {
                // Byte-identical: an empty diff is noise masquerading as
                // proof — same posture as the JSON evidence keys.
                $diffs[] = ['member' => $m->id, 'diff' => null, 'identical' => true];
                continue;
            }
            $header = "--- a/{$aOperand}\n+++ b/" . PathCanonical::diffOperand($m->file) . "\n";
            $differ = new Differ(new UnifiedDiffOutputBuilder($header, false));
            [$diff, $truncated] = self::cap($differ->diff($templateText, $source), self::DIFF_MAX_LINES, self::DIFF_MAX_BYTES);
            $diffs[] = ['member' => $m->id, 'diff' => $diff, 'truncated' => $truncated];
        }
        return $diffs;
    }

    /**
     * The 4.1 drift findings carried on the cluster — filtered to the
     * drift- code family so the pack says exactly what item 8.1 names.
     *
     * @return list<array<string,mixed>>
     */
    private function driftSection(Cluster $cluster): array
    {
        $flags = [];
        foreach ($cluster->architecturalFindings as $f) {
            if (!str_starts_with($f->code, 'drift-')) {
                continue;
            }
            $flags[] = [
                'analyzer'   => $f->analyzer,
                'code'       => $f->code,
                'severity'   => $f->severity,
                'message'    => $f->message,
                'suggestion' => $f->suggestion,
            ];
        }
        return $flags;
    }

    /**
     * The 4.2 history sidecar row for this cluster (same-run id join — the
     * sidecar rows are verdict-keyed by Cluster::$id and ride the same
     * Report, so no stale join is possible). Null when the window was never
     * armed; an explicit "not covered" posture when the sidecar exists but
     * the cluster was outside the window or past the MAX_ROWS cap.
     *
     * @return array<string,mixed>|null
     */
    private function historySection(Cluster $cluster, Report $report): ?array
    {
        $sidecar = $report->clusterHistory;
        if ($sidecar === null) {
            return null;
        }
        foreach ($sidecar->rows as $row) {
            if (($row['cluster'] ?? null) !== $cluster->id) {
                continue;
            }
            return [
                'covered'       => true,
                'verdict'       => $row['verdict'] ?? null,
                'reason'        => $row['reason'] ?? null,
                'members'       => $row['members'] ?? [],
                'co_change'     => $row['co_change'] ?? [],
                'window_months' => $sidecar->windowMonths,
            ];
        }
        return [
            'covered'       => false,
            'window_months' => $sidecar->windowMonths,
            'note'          => 'no history row for this cluster inside the window (untouched, or omitted beyond the sidecar cap)',
        ];
    }

    /**
     * The 3.2 adoption rows touching this finding, joined with the
     * PlacementAdvisor law kept honest: line-exact attribution only when
     * the row's copy samples are complete, the file-level ceiling
     * otherwise; the helper definition itself counts as a member when the
     * range contains it. Null when the probe was unarmed.
     *
     * @return array<string,mixed>|null
     */
    private function adoptionSection(Cluster $cluster): ?array
    {
        if ($this->adoptionRows === null) {
            return null;
        }
        $helpers = [];
        foreach ($this->adoptionRows as $row) {
            $samplesComplete = count($row->copyLocations) >= $row->inlineSites;
            $attach = [];
            foreach ($cluster->members as $m) {
                if ($m->file === $row->defFile && $m->range->start <= $row->defStart && $m->range->end >= $row->defEnd) {
                    $attach[] = ['member' => $m->id, 'mode' => 'def'];
                    continue;
                }
                if (($row->inlineFiles[$m->file] ?? 0) < 1) {
                    continue;
                }
                if ($samplesComplete) {
                    if (self::hasCopyInside($m, $row)) {
                        $attach[] = ['member' => $m->id, 'mode' => 'line_exact'];
                    }
                    continue;
                }
                $attach[] = ['member' => $m->id, 'mode' => 'file_level'];
            }
            if ($attach === []) {
                continue;
            }
            $helpers[] = [
                'helper_label'   => $row->helperLabel,
                'helper_kind'    => $row->helperKind,
                'def'            => ['file' => $row->defFile, 'start' => $row->defStart, 'end' => $row->defEnd],
                'def_statements' => $row->defStatements,
                'sites_total'    => $row->sitesTotal,
                'inline_sites'   => $row->inlineSites,
                'callers'        => $row->callers,
                'join'           => $samplesComplete ? 'line_exact' : 'file_level',
                'attach'         => $attach,
            ];
            if (count($helpers) >= self::ADOPTION_MAX_ROWS) {
                break;
            }
        }
        return ['helpers' => $helpers];
    }

    private static function hasCopyInside(Block $member, HelperCopy $row): bool
    {
        foreach ($row->copyLocations as $location) {
            $sep = strrpos($location, ':');
            if ($sep === false) {
                continue;
            }
            if (substr($location, 0, $sep) !== $member->file) {
                continue;
            }
            $line = (int) substr($location, $sep + 1);
            if ($line >= $member->range->start && $line <= $member->range->end) {
                return true;
            }
        }
        return false;
    }

    /**
     * Boundary guard (output-011 law, same as DiffReporter/JsonReporter
     * evidence): member paths ride both the diff header inside this JSON
     * and a decoded consumer's `git apply` path — a control byte there
     * forges stream lines. Refuse the WHOLE report before any store read
     * or directory creation; a partial pack set is the misfeed class.
     */
    private function requireStreamSafe(Report $report): void
    {
        foreach ($report->clusters as $cluster) {
            foreach ($cluster->members as $member) {
                ControlText::reject($member->file, 'EvidencePackBuilder', 'member file path');
            }
        }
    }

    /**
     * Cap text to $maxLines lines AND $maxBytes bytes with an honest
     * marker (the JsonReporter cap idiom, kept class-local so the
     * private-constant coupling never crosses reporters).
     *
     * @return array{string,bool}
     */
    private static function cap(string $text, int $maxLines, int $maxBytes): array
    {
        $lines = explode("\n", rtrim($text, "\n"));
        $total = count($lines);
        $bytes = strlen($text);
        if ($total <= $maxLines && $bytes <= $maxBytes) {
            return [$text, false];
        }
        $kept   = array_slice($lines, 0, $maxLines);
        $joined = implode("\n", $kept);
        if (strlen($joined) > $maxBytes) {
            $joined = substr($joined, 0, $maxBytes);
        }
        $shownBytes = strlen($joined);
        $shownLines = count(explode("\n", $joined));
        $joined .= "\n# … truncated: showing {$shownLines} of {$total} lines, {$shownBytes} of {$bytes} bytes …";
        return [$joined . "\n", true];
    }
}
