<?php
declare(strict_types=1);

namespace Phpdup\Reporting;

use Phpdup\Cli\Config;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Extraction\BlockExtractor;
use Phpdup\Refactor\Hole;
use Phpdup\Refactor\HoleCoverage;
use Phpdup\Refactor\PlacementAdvisor;
use Phpdup\Util\ControlText;
use Phpdup\Util\Json;
use Phpdup\Util\Num;
use Phpdup\Util\PathCanonical;
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
 *
 * output-S41 (php-dup-finder-output-044, cost-only, payload-preserving):
 * two gates stop paying for output the caps discard. (1) The evidence
 * diff caps AFTER computing a full-text LCS — for multi-thousand-line
 * members that is a quadratic DP whose result is then cut to
 * DIFF_MAX_LINES/DIFF_MAX_BYTES. The fix bounds the DIFFER, not the
 * output: sources longer than {@see EVIDENCE_WINDOW_LINES} lines are
 * sliced to a head window before the single `diff()` call, and the
 * bounded payload discloses itself — `truncated: true` plus a
 * window-note marker line, never a silently narrowed comparison.
 * (2) `HoleCoverage::coverage()` walked the whole template AST even
 * when a cluster carries NO hole paths — the walk's answer is always
 * `[≥1, 0]` then, i.e. float `0.0`, so zero-hole clusters now answer
 * `0.0` at the caller without the walk. `hole_coverage` keeps its
 * emit-for-every-cluster posture (1.7 shape untouched, no new keys, no
 * flag); clusters WITH holes tally exactly as before. The counters
 * {@see coverageTallies()}, {@see evidenceDiffComputations()},
 * {@see evidenceWindowSlices()} and {@see evidenceDiffMaxInputLines()}
 * are the spy surface for those claims, per the
 * SourceTextStore::readCount() / RefactorPatchReporter::detectUnsafeComputations()
 * precedent.
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
    /**
     * Item 3.1 ingestion (MINE-D): row cap of the conditional
     * `summary.idiom_census` block — the doc's "top-50 ranked census"
     * frame; presentation bound, not counting bound (hashes/sites
     * totals above it are corpus-complete).
     */
    private const CENSUS_TOP_ROWS = 50;

    /**
     * output-S41 (php-dup-finder-output-044): per-side line budget handed
     * to the Differ for `evidence_diff`. Two times the cap's line budget:
     * a fully-different window still overflows DIFF_MAX_LINES into the
     * cap marker, and the LCS DP stays bounded at this size squared no
     * matter how huge the members get (the old code diffed whole
     * multi-thousand-line blocks to throw >90% of the bytes away).
     */
    private const EVIDENCE_WINDOW_LINES = self::DIFF_MAX_LINES * 2;

    private ?PrettyPrinter $printer = null;

    // ── output-S41 cost spies (assertion surface for the two gates; same
    //    posture as SourceTextStore::readCount() and
    //    RefactorPatchReporter::detectUnsafeComputations()) ────────────────
    private int $coverageTallies = 0;
    private int $evidenceDiffComputations = 0;
    private int $evidenceWindowSlices = 0;
    private int $evidenceDiffMaxInputLines = 0;

    public function __construct(private readonly ?SourceTextStore $sourceText = null) {}

    /** Clusters whose coverage went through the full AST tally (gate: only hole-carrying ones do). */
    public function coverageTallies(): int
    {
        return $this->coverageTallies;
    }

    /** Real Differ invocations the evidence leg performed (window-equal slices answer without one). */
    public function evidenceDiffComputations(): int
    {
        return $this->evidenceDiffComputations;
    }

    /** Evidence diffs taken on a head window instead of the full member sources. */
    public function evidenceWindowSlices(): int
    {
        return $this->evidenceWindowSlices;
    }

    /** Max lines fed to the Differ on ANY side, across all evidence diffs this instance ran. */
    public function evidenceDiffMaxInputLines(): int
    {
        return $this->evidenceDiffMaxInputLines;
    }

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
        $payload = [
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
                // 1.8 trailing (E2: items 0.6/1.2 + the once-S37 carrier
                // emit + once-S70 ml_status). blocks_oversized is the
                // item-0.6 honesty counter — the mass the size ceiling
                // left invisible, at the granularity that escaped: fully
                // dropped parents once each (windowing on: parents from
                // which no statement window survived; off: every oversized
                // drop) plus one per statement a SALVAGED parent's window
                // packing silently gave up on (E2 r1 MINOR-1),
                // fresh-extraction only (cache-reused files never
                // recount — IndexStore carries blocks, not drops). The
                // next three are the once-S37 scan-signal carriers, the
                // emit their staging docblock promised for exactly this
                // bump. ml_status is the MASTER-side pair client's dial
                // state (once-S70); worker dials inside the fork zone are
                // invisible here, null = no client was wired at all.
                'blocks_oversized'     => $report->oversizedBlocks,
                'scan_notes'           => $report->scanNotes,
                'parse_failures'       => $report->parseFailures,
                'clusters_replayed'    => $report->clustersReplayed,
                'ml_status'            => $report->mlStatus,
            ],
            // Echo of the run config — key set governed by
            // JsonSchemaSpec::configShape() (once-S55); the three knobs
            // S55 parked for the owner window joined the echo with the
            // 1.8 bump, alongside the item-1.2 posture this ships.
            'config' => [
                'paths'                => $report->config->paths,
                'min_block_size'       => $report->config->minBlockSize,
                'normalization_mode'   => $report->config->normalizationMode,
                'similarity_threshold' => Num::json($report->config->similarityThreshold),
                'tree_threshold'       => Num::json($report->config->treeThreshold),
                'min_cluster_impact'   => $report->config->minClusterImpact,
                'max_hole_coverage'    => Num::json($report->config->maxHoleCoverage),
                'score_mode'           => $report->config->scoreMode,
                'scope'                => $report->config->scope,
                'window_oversized'     => $report->config->windowOversized,
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
        // Item 3.1 ingestion (MINE-D): the conditional trailing summary
        // key. Present ONLY when the run armed idiom_mining and a census
        // exists — absent otherwise, which is what keeps every frozen
        // payload byte-identical (the 2.3 class_shape conditional-key
        // precedent one level down; no SCHEMA_VERSION move, since no
        // default run's bytes change). Declared by
        // JsonSchemaSpec::summaryShape(true) / censusBlockShape().
        if ($report->idiomCensus !== null) {
            $payload['summary']['idiom_census'] = self::censusPayload($report->idiomCensus);
        }
        // Item 4.2 (git-history correlation): the conditional trailing
        // summary key, same MINE-D demand-armed family — present ONLY
        // when the run armed `history`; absent otherwise, so every frozen
        // payload stays byte-identical (no SCHEMA_VERSION move: the
        // default run's bytes do not change). Declared by
        // JsonSchemaSpec::summaryShape(historyArmed: true) /
        // historyBlockShape().
        if ($report->clusterHistory !== null) {
            $payload['summary']['cluster_history'] = self::historyPayload($report->clusterHistory);
        }
        // Item 5.2 (W2b): the 'vendored duplicates' section, third key of
        // the same demand-armed family — present ONLY when the run armed
        // --scope (the info-doc ruling: demotion of vendored↔vendored
        // rides the scope slice); absent when scope is unarmed, so every
        // frozen payload stays byte-identical (no SCHEMA_VERSION move).
        // Declared by JsonSchemaSpec::summaryShape(vendoredArmed: true) /
        // vendoredBlockShape(). A measured vendored-free corpus is the
        // zero-shaped block, never null — null means the pass was not
        // armed. The section is data the split already built at the
        // report seam; this leg is pure pass-through, like the sibling
        // conditional keys above.
        if ($report->vendoredDuplicates !== null) {
            $payload['summary']['vendored_duplicates'] = $report->vendoredDuplicates;
        }

        // Items 3.5/3.3 (MINE-E): same conditional trailing-key law
        // (MINE-D r1 §4 ruling) — each key present ONLY when its
        // demand was armed and the census object exists; OFF runs emit
        // neither, keeping every frozen payload byte-identical with no
        // SCHEMA_VERSION move. Declared by
        // JsonSchemaSpec::summaryShape(callsArmed/literalsArmed) +
        // callBlockShape()/literalBlockShape().
        if ($report->callSequences !== null) {
            $payload['summary']['call_sequences'] = self::callSequencePayload($report->callSequences);
        }
        if ($report->literalCensus !== null) {
            $payload['summary']['literal_census'] = self::literalPayload($report->literalCensus);
        }
        return $payload;
    }

    /**
     * The armed census's report block (item 3.1's "ranked census"
     * surface): corpus totals plus the top-ranked maximal idiom rows in
     * the census's own deterministic order (sites desc, files desc,
     * hash asc). Locations follow the substrate's two-pass memory policy
     * — hashes seen at a single site carry none by design — and the row
     * list is capped (CENSUS_TOP_ROWS) so an armed run on a huge corpus
     * still emits a bounded block; the cap truncates presentation, never
     * counting.
     *
     * @return array<string, mixed>
     */
    private static function censusPayload(\Phpdup\Census\SubtreeCensus $census): array
    {
        $rows = [];
        foreach ($census->maximalRows() as $row) {
            if (count($rows) >= self::CENSUS_TOP_ROWS) {
                break;
            }
            $rows[] = [
                'hash'       => $row->exactHash,
                'abstract'   => $row->abstractHash,
                'nodes'      => $row->nodes,
                'root'       => $row->rootType,
                'sites'      => $row->sites,
                'files'      => $row->files,
                'locations'  => $row->locations,
            ];
        }
        return [
            'hashes' => $census->hashCount(),
            'sites'  => $census->sitesTotal(),
            'rows'   => $rows,
        ];
    }

    /**
     * The armed history block (item 4.2): window bound, measured count,
     * presentation cap, and the per-cluster verdict rows. The analyzer
     * already capped rows (ClusterHistoryAnalyzer::MAX_ROWS) and co-change
     * pairs (MAX_COCHANGE) and abbreviated shas, so this leg is pure
     * pass-through — the reporter never re-runs git, and the row shape is
     * declared by JsonSchemaSpec::historyBlockShape().
     *
     * @return array<string, mixed>
     */
    private static function historyPayload(\Phpdup\Git\ClusterHistory $history): array
    {
        return [
            'window_months' => $history->windowMonths,
            'clusters'      => count($history->rows),
            'omitted'       => $history->omitted,
            'rows'          => $history->rows,
        ];
    }

    /**
     * The armed call-protocol census block (item 3.5): same bounded-row
     * posture as the idiom block — corpus totals plus top maximal
     * protocols in the census's deterministic order. Each row carries
     * the ordered callee names and, per the item letter, the table of
     * constant arguments that DIFFER per slot ({slot, call, values of
     * {token, sites}, none} — holes count in `none`; the tables are
     * corpus-complete, the location samples are the two-pass witness
     * set).
     *
     * @return array<string, mixed>
     */
    private static function callSequencePayload(\Phpdup\Census\CallSequenceCensus $census): array
    {
        $rows = [];
        foreach ($census->maximalRows() as $row) {
            if (count($rows) >= self::CENSUS_TOP_ROWS) {
                break;
            }
            $rows[] = [
                'names'     => $row->names,
                'length'    => $row->length,
                'sites'     => $row->sites,
                'files'     => $row->files,
                'locations' => $row->locations,
                'arg_diff'  => $row->argDiff,
            ];
        }
        return [
            'windows' => $census->windowCount(),
            'sites'   => $census->sitesTotal(),
            'rows'    => $rows,
        ];
    }

    /**
     * The armed literal-census block (item 3.3): per-bucket totals
     * (every bucket line of the item letter has its own kind), the
     * repeated entries in the census's deterministic order, and the
     * edit-distance family view over string entries —
     * `families_computed` states honestly when the quadratic family
     * pass was skipped by its distinct-entry cap.
     *
     * @return array<string, mixed>
     */
    private static function literalPayload(\Phpdup\Census\LiteralCensus $census): array
    {
        $rows = [];
        foreach ($census->rows() as $row) {
            if (count($rows) >= self::CENSUS_TOP_ROWS) {
                break;
            }
            $rows[] = [
                'kind'      => $row->kind,
                'hash'      => $row->hash,
                'sample'    => $row->sample,
                'roles'     => $row->roles,
                'sites'     => $row->sites,
                'files'     => $row->files,
                'locations' => $row->locations,
            ];
        }
        return [
            'kinds'              => $census->bucketTotals(),
            'sites'              => $census->sitesTotal(),
            'rows'               => $rows,
            'families'           => $census->families(),
            'families_computed'  => $census->familiesComputed(),
        ];
    }

    /** @return array<string, mixed> */
    private function clusterPayload(Cluster $c, Config $config, PlacementAdvisor $advisor, SourceTextStore $store): array
    {
        // One coverage measurement per cluster, reused by the three keys
        // that depend on it (item 5.4 walking an AST twice would only
        // disagree with itself). output-S41: the walk happens only when
        // it can change the answer — coverageOf() documents the law.
        $coverage    = $this->coverageOf($c);
        $reExpressed = $coverage !== null && $coverage > $config->maxHoleCoverage;
        // output-S41: canonical template resolved once per cluster —
        // template/source/evidence all ask the same question.
        $template    = HoleCoverage::canonicalTemplate($c);
        $payload = [
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
            'template'      => self::templatePayload($template),
            'source'        => $this->sourcePayload($template, $store),
            'evidence_diff' => $this->evidencePayload($c, $template, $store),
            'hole_coverage' => $coverage === null ? null : Num::json($coverage),
            're_expressed'  => $reExpressed,
            'hole_fragment' => $reExpressed ? $this->fragmentPayload($c) : null,
            // Item 5.3: trailing-additive placement advice — where the
            // extraction should live, classified from holes + labels only.
            'placement'     => $advisor->advise($c),
        ];
        // Item 2.3: the class-shape table — a CONDITIONAL key, present
        // only when the cluster's first member is a class block (the
        // kind is opt-in, so default payloads keep their byte-frozen
        // key set: this is precisely why it cannot ride hole_fragment's
        // always-present-null style — every extra always-key moves the
        // golden files, and the shape table exists to cluster without
        // moving them).
        if ($c->members !== [] && $c->members[0]->kind === BlockExtractor::CLASS_KIND) {
            $payload['class_shape'] = self::classShapePayload($c);
        }
        // Item 6.3: the subsumption annotation — a CONDITIONAL key in
        // exactly the class_shape posture: present only when the armed
        // fold attributed a maximal family to this cluster. An unarmed
        // (default) run never populates the field, so no payload the
        // frozen goldens measure can ever grow this key; the armed rows
        // are the doc's "report maximal clones only" made auditable —
        // the folded families are named here instead of multiplying the
        // cluster count above.
        if ($c->subsumes !== []) {
            $payload['subsumes'] = $c->subsumes;
        }
        return $payload;
    }

    /**
     * Item 2.3 — the doc's skeleton-family table, computed from the
     * members' shape sidecars (never persisted on Cluster, same law as
     * `scope` above).
     *
     * shape_exact: every member shares one name-blanked skeleton hash
     * (declaration twins: RateLimitState pairs, interface clones).
     * shared_methods: per method NAME present in ≥2 members, how many
     * members carry it and the distinct hash counts on both lanes,
     * with the doc's three-way verdict —
     *   identical-in-all    one strict `s` hash (byte-equal bodies):
     *                       pull the method up as-is;
     *   parameterizable     splits on the strict lane but one run-fold
     *                       `d` hash: same shape modulo literals/vars —
     *                       the template-method proposal;
     *   divergent           body SHAPE differs — flagged for 6.1-grade
     *                       unification before any base-class claim.
     *
     * @return array{shape_exact:bool,class_names:list<string>,shared_methods:array<string,array{members_with_method:int,distinct_strict_hashes:int,distinct_fold_hashes:int,verdict:string}>}
     */
    private static function classShapePayload(Cluster $c): array
    {
        $hashes = [];
        $names  = [];
        $byMethodStrict  = [];
        $byMethodFold    = [];
        $methodSeen      = [];
        foreach ($c->members as $m) {
            // Mixed-kind clusters are pre-existing Clusterer behavior (the
            // ngram tiers are kind-blind; a class's bag overlaps its own
            // members' heavily). The shape table describes the CLASS
            // members only — a co-clustered method/fragment block must
            // never contaminate class_names or the verdict counts.
            if ($m->kind !== BlockExtractor::CLASS_KIND) {
                continue;
            }
            $hashes[$m->structuralHash] = true;
            $names[] = $m->name ?? '';
            foreach ($m->shapeMethods as $method => $pair) {
                $byMethodStrict[$method][$pair['s']] = true;
                $byMethodFold[$method][$pair['d']] = true;
                $methodSeen[$method][spl_object_id($m)] = true;
            }
        }
        $shared = [];
        foreach ($methodSeen as $method => $owners) {
            $with = count($owners);
            if ($with < 2) {
                continue; // a member nobody shares nothing to propose
            }
            $sDistinct = count($byMethodStrict[$method] ?? []);
            $dDistinct = count($byMethodFold[$method] ?? []);
            $shared[$method] = [
                'members_with_method'   => $with,
                'distinct_strict_hashes' => $sDistinct,
                'distinct_fold_hashes'   => $dDistinct,
                'verdict' => $sDistinct <= 1
                    ? 'identical-in-all'
                    : ($dDistinct <= 1 ? 'parameterizable' : 'divergent'),
            ];
        }
        ksort($shared);
        return [
            'shape_exact'  => count($hashes) === 1,
            'class_names'  => $names,
            'shared_methods' => $shared,
        ];
    }

    /**
     * output-S41 (php-dup-finder-output-044) lazy-tally leg: the coverage
     * walk only runs when it can still change the answer.
     *
     * HoleCoverage::coverage()'s contract is preserved to the bit:
     *  - no generalized AST → null (delegated: the callee's own guard);
     *  - generalized AST + NO hole paths → the tally can only ever return
     *    [total ≥ 1, covered = 0] — a Node root counts itself — so its
     *    answer is the float 0.0 WITHOUT the walk (0/N === 0.0 exactly,
     *    IEEE). `re_expressed` stays unreachable on such clusters because
     *    Config validates max_hole_coverage within 0..1 and
     *    0.0 > (0..1) is never true;
     *  - generalized AST + holes present → full tally, unchanged.
     */
    private function coverageOf(Cluster $c): ?float
    {
        if ($c->generalizedAst instanceof Node && $c->holePaths === []) {
            return 0.0;
        }
        $this->coverageTallies++;
        return HoleCoverage::coverage($c);
    }

    /**
     * The canonical template identity (item 5.4a): the cluster's medoid
     * representative when recorded, else members[0] — see
     * {@see HoleCoverage::canonicalTemplate()} for what this is NOT
     * (AntiUnifier's internal seed, which is not persisted). Resolved once
     * per cluster by clusterPayload() (output-S41) and shared by the three
     * template consumers.
     *
     * @return array{id:string|null,file:string|null,start:int|null,end:int|null}|null
     */
    private static function templatePayload(?Block $t): ?array
    {
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
    private function sourcePayload(?Block $t, SourceTextStore $store): array
    {
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
     * output-S41 (php-dup-finder-output-044) cap-before-Differ leg: the
     * emitted blob is cut to DIFF_MAX_LINES/DIFF_MAX_BYTES, so for members
     * longer than EVIDENCE_WINDOW_LINES the Differ is fed only that head
     * window — the quadratic LCS can no longer be paid in full just to
     * throw most of the bytes away. The narrowing is DISCLOSED, never
     * silent: a windowed slice always answers `truncated: true` plus a
     * marker line naming the compared prefix, and a window whose two sides
     * are equal answers the header + marker WITHOUT a Differ run (an
     * empty-hunk diff is predictable without paying LCS for it). Sources
     * at or under the window take the untouched pre-S41 path: identical
     * bytes, truncation as computed by cap() alone.
     *
     * @return null|array{member:string,file:string,start:int,end:int,diff:string,truncated:bool}
     */
    private function evidencePayload(Cluster $c, ?Block $t, SourceTextStore $store): ?array
    {
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
        // Git-style header operands (once-S101 fix1, php-dup-finder-once-126
        // XP-11): absolute scan paths used to interpolate as `--- a//abs/…`
        // — a doubled slash git itself never emits. Only the header join is
        // canonicalised; the `file` member fields below keep the raw
        // scanner path (JSON consumers match them against scan output).
        $header = '--- a/' . PathCanonical::diffOperand($t->file) . "\n"
            . '+++ b/' . PathCanonical::diffOperand($rep->file) . "\n";

        // ── output-S41 cap-before-Differ ───────────────────────────────────
        $linesT = self::evidenceLines($srcT);
        $linesR = self::evidenceLines($srcR);
        $windowed = count($linesT) > self::EVIDENCE_WINDOW_LINES
            || count($linesR) > self::EVIDENCE_WINDOW_LINES;
        if ($windowed) {
            $this->evidenceWindowSlices++;
            $winT = implode("\n", array_slice($linesT, 0, self::EVIDENCE_WINDOW_LINES)) . "\n";
            $winR = implode("\n", array_slice($linesR, 0, self::EVIDENCE_WINDOW_LINES)) . "\n";
            if ($winT === $winR) {
                // The window holds no hunk and no Differ run is owed: the
                // disclosure states exactly what was compared — and that
                // nothing beyond it was.
                return $this->evidenceFields($rep, self::note(
                    $header,
                    self::windowNote($linesT, $linesR) . ' are identical; no hunk in the window.',
                ), true);
            }
            $srcT = $winT;
            $srcR = $winR;
        }

        $this->evidenceDiffComputations++;
        $this->evidenceDiffMaxInputLines = max(
            $this->evidenceDiffMaxInputLines,
            $windowed ? self::EVIDENCE_WINDOW_LINES : count($linesT),
            $windowed ? self::EVIDENCE_WINDOW_LINES : count($linesR),
        );
        $differ = new \SebastianBergmann\Diff\Differ(
            new \SebastianBergmann\Diff\Output\UnifiedDiffOutputBuilder($header, false),
        );
        [$diff, $truncated] = self::cap($differ->diff($srcT, $srcR), self::DIFF_MAX_LINES, self::DIFF_MAX_BYTES);
        if ($windowed) {
            // The cap marker (when present) counts the WINDOW diff's bytes;
            // the note below is what tells the truth about the comparison
            // never having seen the rest of either side.
            $diff      = self::note($diff, self::windowNote($linesT, $linesR) . ' were diffed.');
            $truncated = true;
        }
        return $this->evidenceFields($rep, $diff, $truncated);
    }

    /**
     * @return array{member:string,file:string,start:int,end:int,diff:string,truncated:bool}
     */
    private function evidenceFields(Block $rep, string $diff, bool $truncated): array
    {
        return [
            'member'    => $rep->id,
            'file'      => $rep->file,
            'start'     => $rep->range->start,
            'end'       => $rep->range->end,
            'diff'      => $diff,
            'truncated' => $truncated,
        ];
    }

    /** Cap-marker dialect: one honest `# …` line appended to a text blob. */
    private static function note(string $text, string $note): string
    {
        return rtrim($text, "\n") . "\n" . $note;
    }

    /**
     * One side's lines in cap()'s grammar (rtrim the terminator, then split).
     *
     * @return list<string>
     */
    private static function evidenceLines(string $source): array
    {
        return explode("\n", rtrim($source, "\n"));
    }

    /**
     * @param list<string> $linesT
     * @param list<string> $linesR
     */
    private static function windowNote(array $linesT, array $linesR): string
    {
        return '# … evidence window: first ' . self::EVIDENCE_WINDOW_LINES
            . ' of ' . count($linesT) . '/' . count($linesR)
            . ' template/peer lines';
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
