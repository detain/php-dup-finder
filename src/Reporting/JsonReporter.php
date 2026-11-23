<?php
declare(strict_types=1);

namespace Phpdup\Reporting;

use Phpdup\Cli\Config;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Refactor\Hole;
use Phpdup\Refactor\HoleCoverage;
use Phpdup\Refactor\PlacementAdvisor;
use Phpdup\Util\ControlText;
use Phpdup\Util\Json;
use Phpdup\Util\Num;
use Phpdup\Util\Version;
use PhpParser\Node;
use PhpParser\PrettyPrinter\Standard as PrettyPrinter;

/**
 * Machine-readable JSON dump.
 *
 * Item 5.4 grows the cluster payload with honest extraction evidence, all
 * of it TRAILING-ADDITIVE (schema '1.4' → '1.5'): which member is the
 * canonical `template`, its capped `source` text, a capped `evidence_diff`
 * against the median-scored peer, the measured `hole_coverage`, and — only
 * when the operator's `max_hole_coverage` stance is exceeded — the
 * `re_expressed` mark plus the longest hole-free `hole_fragment`. Hole
 * objects additionally carry `degraded`, and structural gaps in `observed`
 * are JSON null, never the old `<missing>` string.
 *
 * output-S27 (schema '1.6' → '1.7'): `observed` null now means any
 * absence row — the canonical Hole::MISSING covers structural gaps,
 * never-gathered rows and verified optional-segment absences alike, and
 * the retired printable sentinel is normalized to null here (with
 * pre-5.4 '<missing>') for stale payloads. `present_in_members` is
 * corrected in the same pass: it lists members with a genuine value
 * row, so canonical '' rows no longer count as present. No key is
 * added or removed — a semantic correction inside existing keys, hence
 * a minor bump.
 *
 * output-038: source bytes arrive through the same per-run
 * {@see SourceTextStore} every other read surface uses (injected by
 * ReportStage; a standalone `build()` on a hand-built Report creates its
 * own confined store from the report's scan roots). The unfetchable case
 * is the payload's own `text: null` / `evidence_diff: null` posture — the
 * `<source unavailable: …>` placeholder string that used to ride inside
 * these values is gone. Schema shape untouched: both keys already declare
 * null, and no golden member sits in a previously-placeholdered case.
 */
final class JsonReporter
{
    use WritesReportFile;

    /** Size caps for the item-5.4 evidence keys — reports stay bounded, truncation is marked. */
    private const SOURCE_MAX_LINES   = 200;
    private const SOURCE_MAX_BYTES   = 8000;
    private const DIFF_MAX_LINES     = 120;
    private const DIFF_MAX_BYTES     = 6000;
    private const FRAGMENT_MAX_LINES = 80;
    private const FRAGMENT_MAX_BYTES = 4000;

    private ?PrettyPrinter $printer = null;

    public function __construct(private readonly ?SourceTextStore $sourceText = null) {}

    /**
     * Render-projection classifier (output-S27): is this observed row an
     * absence of ANY kind — the canonical Hole::MISSING, the pre-5.4
     * '<missing>' impostor, or the retired pre-1.7 printable absent
     * sentinel? Those two string literals exist ONLY in this projection
     * layer; the data layer has emitted canonical rows since 1.7.
     */
    private static function isAbsenceRow(string $v): bool
    {
        return $v === Hole::MISSING || $v === '<missing>' || $v === '<absent>';
    }

    public function writeTo(Report $report, string $file): void
    {
        $this->writeFile($file, Json::encode($this->build($report), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /** @return array<string, mixed> */
    public function build(Report $report): array
    {
        $advisor = new PlacementAdvisor($report->config->paths);
        $store   = $this->sourceText ?? new SourceTextStore($report->config->paths);
        return [
            'phpdup_version' => Version::STRING,
            'schema_version' => JsonSchemaSpec::SCHEMA_VERSION,
            'summary' => [
                'files'        => $report->files,
                'blocks'       => $report->blocks,
                'parse_errors' => $report->parseErrors,
                'clusters'     => count($report->clusters),
                'duplicated_lines' => $report->totalDuplicatedLines(),
                'total_impact' => array_sum(array_map(fn(Cluster $c) => $c->impact, $report->clusters)),
                // File accounting — trailing-additive keys, schema
                // '1.1' → '1.2' (the earlier plan-era '(0.6)' label was a
                // section reference, not a schema version). 'files' above keeps its historical meaning
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
            // Echo of the run config — key set governed by
            // JsonSchemaSpec::configShape() (once-S55); the three
            // deliberately un-echoed knobs are adjudicated there.
            'config' => [
                'paths'                => $report->config->paths,
                'min_block_size'       => $report->config->minBlockSize,
                'normalization_mode'   => $report->config->normalizationMode,
                'similarity_threshold' => Num::json($report->config->similarityThreshold),
                'tree_threshold'       => Num::json($report->config->treeThreshold),
                'min_cluster_impact'   => $report->config->minClusterImpact,
            ],
            // Item 5.3: one advisor per build() so composer manifests are
            // read once per run; it evaluates post-RefactorStage state
            // (holes + labels) only — never reloads unloaded member ASTs.
            'clusters' => array_map(fn(Cluster $c) => $this->clusterPayload($c, $report->config, $advisor, $store), $report->clusters),
            // Item 4.3: the declared-twin claim-vs-reality table —
            // trailing-additive key, schema '1.2' → '1.3'. Producers
            // without the analyzer pass emit [] (the key is always
            // present so consumers can bind on it).
            'declared_twins' => $report->declaredTwins,
        ];
    }

    /** @return array<string, mixed> */
    private function clusterPayload(Cluster $c, Config $config, PlacementAdvisor $advisor, SourceTextStore $store): array
    {
        // One coverage measurement per cluster, reused by the three keys
        // that depend on it (item 5.4 walking an AST twice would only
        // disagree with itself).
        $coverage    = HoleCoverage::coverage($c);
        $reExpressed = $coverage !== null && $coverage > $config->maxHoleCoverage;
        return [
            'id'           => $c->id,
            // Governed by JsonSchemaSpec::clusterShape() as string|null:
            // a populated cluster states its block kind, the null case
            // is an empty member list (defensive — never pipeline
            // emitted). The explicit guard declares the null by
            // construction; bytes are identical to the previous
            // members[0]->kind ?? null for every input.
            'kind'         => $c->members === [] ? null : $c->members[0]->kind,
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
                // Item 5.1: trailing-additive run-context labels —
                // repo is the scan-root basename ('' when no root
                // matched), scope is 'single' or the repo label.
                'repo'      => $m->repo,
                'scope'     => $m->scope,
            ], $c->members),
            'pairs' => array_map(static fn(array $p) => [
                'blockA'     => $p['blockA'],
                'blockB'     => $p['blockB'],
                'matchTier'  => $p['matchTier'],
                'matchScore' => Num::json($p['matchScore']),
            ], $c->pairs),
            'holes' => array_map(static function (Hole $h) {
                // Item 5.4 + output-S27 (schema 1.7): `observed` entries
                // serialize every absence row as JSON null — the canonical
                // Hole::MISSING (structural gap, never-gathered row, or
                // verified optional-segment absence alike) as well as the
                // legacy impostor strings still carried by stale caches:
                // '<missing>' (pre-5.4 gap, lifts `degraded` honestly) and
                // the retired printable absent-sentinel (pre-1.7 optional
                // absence, which never degraded anything). The printable
                // impostors live only in this render-projection layer;
                // fresh data emits canonical rows only.
                $observed = [];
                $degraded = $h->degraded;
                foreach (array_unique($h->observedValues) as $v) {
                    if ($v === '<missing>') {
                        // Pre-5.4 cache payload: the impostor string IS a
                        // gap — normalize it and lift the flag honestly.
                        $degraded = true;
                    }
                    $observed[] = self::isAbsenceRow($v) ? null : $v;
                }
                $base = [
                    'placeholder'    => $h->placeholder,
                    'kind'           => $h->kind,
                    'inferred_type'  => $h->inferredType,
                    'suggested_name' => $h->suggestedName,
                    'observed'       => $observed,
                    'value_count'    => $h->uniqueValueCount(),
                    'degraded'       => $degraded,
                ];
                if ($h->kind === 'optional_block') {
                    // 1.7 correction: present_in_members lists the members
                    // with a genuine VALUE row — computed from the raw
                    // per-member rows (never the deduped `observed` set),
                    // so any absence — gap, never-gathered, or verified
                    // optional absence alike — reads as not-present. The
                    // pre-1.7 rule counted canonical '' gap rows as
                    // present (bug retired with the sentinel).
                    $present = [];
                    foreach ($h->observedValues as $i => $v) {
                        if (!self::isAbsenceRow($v)) $present[] = $i;
                    }
                    $base['present_in_members'] = $present;
                }
                return $base;
            }, $c->holes),
            // Item 5.1: trailing-additive cluster scope — 'multi' when
            // members span >=2 distinct non-empty repo labels, 'single'
            // otherwise. Computed from the members' own labels; never
            // persisted on Cluster, so it cannot drift from its evidence.
            'scope' => ClusterScope::of($c),
            // ── Item 5.4 trailing-additive extraction evidence ─────────────
            'template'      => self::templatePayload($c),
            'source'        => self::sourcePayload($c, $store),
            'evidence_diff' => self::evidencePayload($c, $store),
            'hole_coverage' => $coverage === null ? null : Num::json($coverage),
            're_expressed'  => $reExpressed,
            'hole_fragment' => $reExpressed ? $this->fragmentPayload($c) : null,
            // Item 5.3: trailing-additive placement advice — where the
            // extraction should live, classified from holes + labels only.
            'placement'     => $advisor->advise($c),
        ];
    }

    /**
     * The canonical template identity (item 5.4a): the cluster's medoid
     * representative when recorded, else members[0] — see
     * {@see HoleCoverage::canonicalTemplate()} for what this is NOT
     * (AntiUnifier's internal seed, which is not persisted).
     *
     * @return array{id:string|null,file:string|null,start:int|null,end:int|null}|null
     */
    private static function templatePayload(Cluster $c): ?array
    {
        $t = HoleCoverage::canonicalTemplate($c);
        if ($t === null) {
            return null;
        }
        return [
            'id'    => $t->id,
            'file'  => $t->file,
            'start' => $t->range->start,
            'end'   => $t->range->end,
        ];
    }

    /**
     * Template source text, size-capped with an explicit truncation marker
     * line (caps: SOURCE_MAX_LINES / SOURCE_MAX_BYTES). Unfetchable sources
     * (missing/unreadable, or refused by the store's symlink confinement)
     * answer `text: null` — the shape's own "no source" value — never the
     * old `<source unavailable: …>` placeholder string (output-016/-048).
     *
     * @return array{text:string|null,truncated:bool}
     */
    private function sourcePayload(Cluster $c, SourceTextStore $store): array
    {
        $t = HoleCoverage::canonicalTemplate($c);
        if ($t === null) {
            return ['text' => null, 'truncated' => false];
        }
        $source = DiffReporter::sourceOf($t, $store);
        if ($source === null) {
            return ['text' => null, 'truncated' => false];
        }
        [$text, $truncated] = self::cap($source, self::SOURCE_MAX_LINES, self::SOURCE_MAX_BYTES);
        return ['text' => $text, 'truncated' => $truncated];
    }

    /**
     * Unified diff between the template and its most REPRESENTATIVE peer:
     * among pairs touching the template, the member sitting at the median
     * match score (the middle of the cluster's variation, not its fringe).
     * Null when there is no peer, no pair evidence, or the texts are
     * byte-identical (an empty diff would be noise masquerading as proof).
     *
     * @return null|array{member:string,file:string,start:int,end:int,diff:string,truncated:bool}
     */
    private function evidencePayload(Cluster $c, SourceTextStore $store): ?array
    {
        $t = HoleCoverage::canonicalTemplate($c);
        if ($t === null || count($c->members) < 2) {
            return null;
        }
        $rep = self::representativePeer($c, $t);
        if ($rep === null) {
            return null;
        }
        // output-011: the evidence diff embeds both paths raw in its
        // `--- a/… / +++ b/…` header, and a decoded consumer that pipes
        // this field into `git apply`/`patch` sees forged stream lines if
        // either name carries a control byte. Refuse, loudly, at build
        // time — the same boundary law as DiffReporter and
        // RefactorPatchReporter. (Schema 1.6 shape is untouched: this is
        // a refusal, not a payload edit.) The guard runs BEFORE the store
        // reads: a forged path is a named refusal even when the forgery
        // itself made the file unresolvable (output-038 order-preservation).
        ControlText::reject($t->file, 'JsonReporter evidence diff', 'template file path');
        ControlText::reject($rep->file, 'JsonReporter evidence diff', 'evidence peer file path');
        $srcT = DiffReporter::sourceOf($t, $store);
        $srcR = DiffReporter::sourceOf($rep, $store);
        if ($srcT === null || $srcR === null) {
            // output-016: a side that was never fetched leaves no evidence
            // to compare — null posture, not a diff against placeholder
            // bytes (the placeholder used to ride into this field as text).
            return null;
        }
        if ($srcT === $srcR) {
            // Byte-identical sources: an empty diff is noise masquerading
            // as proof — say "no evidence" instead of showing a header.
            return null;
        }
        $differ = new \SebastianBergmann\Diff\Differ(
            new \SebastianBergmann\Diff\Output\UnifiedDiffOutputBuilder("--- a/{$t->file}\n+++ b/{$rep->file}\n", false),
        );
        [$diff, $truncated] = self::cap($differ->diff($srcT, $srcR), self::DIFF_MAX_LINES, self::DIFF_MAX_BYTES);
        return [
            'member'    => $rep->id,
            'file'      => $rep->file,
            'start'     => $rep->range->start,
            'end'       => $rep->range->end,
            'diff'      => $diff,
            'truncated' => $truncated,
        ];
    }

    private static function representativePeer(Cluster $c, Block $t): ?Block
    {
        $byId = [];
        foreach ($c->members as $m) {
            $byId[$m->id] = $m;
        }
        $scores = [];
        foreach ($c->pairs as $p) {
            $other = null;
            if (($p['blockA'] ?? null) === $t->id) {
                $other = $p['blockB'] ?? null;
            } elseif (($p['blockB'] ?? null) === $t->id) {
                $other = $p['blockA'] ?? null;
            }
            if ($other !== null && $other !== $t->id && isset($byId[$other])) {
                $scores[] = [(float)$p['matchScore'], $other];
            }
        }
        if ($scores === []) {
            // No pair touches the template (residue clusters). Take the
            // first member that is not the template itself.
            foreach ($c->members as $m) {
                if ($m !== $t) {
                    return $m;
                }
            }
            return null;
        }
        usort($scores, static fn(array $a, array $b) => $a[0] <=> $b[0]);
        $median = $scores[(int)(count($scores) / 2)][1];
        return $byId[$median] ?? null;
    }

    /**
     * The longest hole-free top-level statement run, pretty-printed and
     * capped — the re-expression target the operator can actually extract.
     * Only reached once the caller's coverage stance has fired.
     *
     * @return null|array{stmt_start:int,stmt_length:int,code:string,truncated:bool}
     */
    private function fragmentPayload(Cluster $c): ?array
    {
        $span = HoleCoverage::fragmentSpan($c);
        if ($span === null) {
            return null;
        }
        $stmts = HoleCoverage::rootStmts($c);
        $slice = array_slice($stmts, $span['start'], $span['length']);
        if ($slice === []) {
            return null;
        }
        $printer = $this->printer ?? new PrettyPrinter();
        $this->printer = $printer;
        $code = $printer->prettyPrint(array_values(array_filter($slice, static fn($s) => $s instanceof Node)));
        [$code, $truncated] = self::cap($code, self::FRAGMENT_MAX_LINES, self::FRAGMENT_MAX_BYTES);
        return [
            'stmt_start'  => $span['start'],
            'stmt_length' => $span['length'],
            'code'        => $code,
            'truncated'   => $truncated,
        ];
    }

    /**
     * Cap text to $maxLines lines AND $maxBytes bytes, appending an honest
     * marker line when anything was cut. Returns [cappedText, truncated].
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
        return [$joined, true];
    }
}
