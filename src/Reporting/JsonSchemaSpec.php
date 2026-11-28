<?php
declare(strict_types=1);

namespace Phpdup\Reporting;

/**
 * Versioned schema descriptor for {@see JsonReporter}'s output.
 *
 * The phpdup CLI is the contract surface for editor / IDE integrations
 * (PhpStorm plugin, language server, third-party dashboards). When the
 * JSON shape changes the schema version bumps so consumers can reject
 * incompatible payloads loudly instead of silently mis-parsing.
 *
 * Backward-compatibility rules (semver-ish):
 *
 *   - Adding a new field at any nesting level → MINOR bump.
 *   - Removing or renaming a field, changing a field's type, or
 *     removing a value from an enum → MAJOR bump.
 *
 * Consumers should treat MAJOR mismatches as hard errors and MINOR
 * mismatches as warnings (existing fields still parse).
 *
 * GOVERNANCE (once-S55):
 *
 *   (a) Byte-governance. The rules above govern the EMITTED PAYLOAD —
 *       the JSON bytes of {@see JsonReporter::build()} output — not the
 *       text of this class. Changing a declaration here to admit a
 *       value the emitter already produces (or can produce defensively
 *       without notice) is NOT a version event: the bytes are
 *       unchanged, so no consumer's parse changes. Adding, removing or
 *       retyping a KEY, or widening what is EMITTED for an existing
 *       key, IS a version event and takes the bump its rule names.
 *
 *   (b) 1.5 exception — retroactive note (once-061 / API-10). 1.5
 *       widened the element type of `holes[].observed` from string to
 *       string|null (a structural gap became JSON null instead of the
 *       '<missing>' impostor string). Under a strict reading of
 *       "changing a field's type" above that is a MAJOR, and 1.5
 *       shipped it under MINOR. The widening was the whole intent of
 *       the release and its justification: no 1.4 consumer could
 *       branch on the difference (the impostor was junk either way —
 *       see 1.5's own note below), so the rule's purpose — loud
 *       rejection of payloads that cannot parse — was never at risk.
 *       Shipped versions are not rewritten: 1.5 stands as emitted,
 *       and consumers take `observed: (string|null)*` as the law of
 *       >= 1.5 payloads. The rule above stays verbatim for FUTURE
 *       changes: any later element-type or key-type widening is a
 *       MAJOR. This note is the record that 1.5 was an exception, not
 *       a precedent.
 *
 *   (c) Sentinel admission — holes[].placeholder (once-S55,
 *       adjudicating output-S51/S57's '__cap' against this shape).
 *       The retired printable absent sentinel needs no admission:
 *       since 1.7 it is not a payload value at all (canonicalised to
 *       null by JsonReporter::isAbsenceRow, see holeShape()); the
 *       cache-residual '<missing>' is normalised there as well. The
 *       one printable sentinel that legitimately rides holes[] is
 *       AntiUnifier::CAP_SENTINEL '__cap': a routed oversized cluster
 *       whose evidence was never unified carries a single synthetic
 *       '__cap' row (all-absence, degraded:true) declaring exactly
 *       that. It is signature-inert (SignatureBuilder skips it) and
 *       config-relevant only through the cluster's re_expressed
 *       stance. Consumers must not treat an unknown placeholder as a
 *       malformed payload; an unfilled '__cap' row means degraded
 *       evidence, never a parse bug.
 */
final class JsonSchemaSpec
{
    /**
     * 1.8 (minor, additive — the owner-opened 1.7→1.8 emit window,
     * improve_logic items 0.6/1.2 + rulings row 3): five TRAILING keys
     * in `summary` and four in the `config` echo. Every 1.7 field keeps
     * its name, type and position, so 1.7 consumers parse 1.8 payloads
     * untouched.
     *
     *   summary: `blocks_oversized` (int|null — blocks the extraction
     *   size ceiling left FULLY invisible; item 0.6's honesty counter,
     *   null = producer does not track, mirroring the 1.2 accounting
     *   posture), `scan_notes` (list<string> — the FileScanner skip
     *   notes cross the Report carrier once signal-free producers keep
     *   emitting []), `parse_failures` (list<object{file:string,
     *   message:string}>), `clusters_replayed` (bool|null — whether the
     *   cluster cache answered this run) and `ml_status` (string|null —
     *   'off' | 'idle' | 'reachable' | 'unreachable' for the run's
     *   MASTER-SIDE pair-scoring client; null = no ML client wired.
     *   Fork-zone workers dial their own endpoints and those dials are
     *   invisible here — the field states the master's knowledge, never
     *   a fabrication).
     *   config: `max_hole_coverage`, `score_mode`, `scope` (the three
     *   knobs once-S55 parked for exactly this window — rulings row 3
     *   opens it) and `window_oversized` (bool — the item-1.2
     *   statement-window extraction posture this commit ships).
     *
     * The three scan-signal carriers (scan_notes/parse_failures/
     * clusters_replayed) were staged on Report by once-S37 with the
     * promise that their JSON emit lands together with this bump; the
     * promise is kept here, in this commit.
     *
     * 1.7 (minor, semantic correction inside existing keys): canonical
     * absence (php-dup-finder-output-019). A hole's `observed` list nulls
     * EVERY absence row: the canonical in-memory marker (Hole::MISSING)
     * now carries structural gaps, never-gathered rows and verified
     * optional-segment absences alike — the retired printable absent
     * sentinel is no longer a data value (stale payloads still normalize
     * to null here; pre-5.4 '<missing>' still lifts `degraded`, a
     * sentinel row never did). Consequence for optional holes: on
     * `degraded:false`, a null row states a VERIFIED absence — the member
     * provably lacks the segment; on `degraded:true`, null rows are
     * honestly ambiguous (some were never observed).
     * `present_in_members` is corrected to list members with a genuine
     * VALUE row: absence-marked members are not present, so the indices
     * are computed from per-member rows, never the deduped `observed`
     * set, and a gap row no longer counts as present (the pre-1.7 bug).
     * No key is added, removed or retyped — consumers parsing 1.6 parse
     * 1.7 untouched; the correction is in what null rows can now mean.
     *
     * 1.6 (minor, additive): item 5.3 placement advice. Every cluster gains
     * one TRAILING key `placement` — {shape, rationale, same_host,
     * lca_namespace, host_packages} classifying where the extraction should
     * live ('move' | 'expression_function' | 'data_table' |
     * 'defaulted_function' | 'parameterized_function'). host_packages is a
     * sorted package-name list only when members span >=2 scan roots with
     * parseable composer manifests; otherwise null with the reason folded
     * into `rationale` (conservative mode — no canonical-usage probe
     * exists, so placement answers host topology, never call dominance).
     * All 1.5 fields keep name, type and position.
     *
     * 1.5 (minor, additive): item 5.4 extraction-quality evidence. Every
     * cluster grows six TRAILING keys — `template` (canonical template
     * identity: the medoid when recorded, else members[0]), `source`
     * (capped template text + truncation flag), `evidence_diff` (capped
     * unified diff template↔median-scored peer, null when there is no
     * peer/difference), `hole_coverage` (0..1 fraction of the template
     * inside holes, null without an AST), `re_expressed` (bool: the
     * operator's `max_hole_coverage` stance is exceeded) and
     * `hole_fragment` (the longest hole-free statement run, set only when
     * re_expressed). Every hole object gains a trailing `degraded` bool,
     * and entries in `holes[].observed` may now be null — a structural gap
     * ("member had no node here") is JSON null, not the old `<missing>`
     * impostor string. All 1.4 fields keep name, type and position, so 1.4
     * consumers parse 1.5 payloads untouched; a consumer that stringified
     * `observed` unfiltered was already rendering junk.
     *
     * 1.4 (minor, additive): item 5.1 repo/scope surfacing — trailing
     * `scope` key on every cluster ('single' | 'multi', computed from
     * the members' repo labels) plus trailing `repo` and `scope` keys
     * on every cluster member (scan-root basename / 'single' or the
     * repo label; '' when the path matched no configured root). Every
     * 1.3 field keeps its name, type and position, so 1.3 consumers
     * parse 1.4 payloads untouched. The `--scope` filter and
     * `--score-mode` knob change WHICH clusters and WHICH scores are
     * emitted, never the shape; defaults reproduce 1.3 semantics
     * exactly (score-mode global is byte-identical math).
     *
     * 1.3 (minor, additive): trailing `declared_twins` top-level key —
     * the item 4.3 claim-vs-reality table (one entry per doc-comment
     * twin declaration, verdicts holds|drifted|unresolved|unmeasurable|
     * harvested). Every 1.2 field keeps its name and type — including
     * `clusters[].id`, whose VALUES moved to the canonical FindingId
     * stream (item 4.4), a value change, not a shape change. 1.2
     * consumers parse 1.3 payloads untouched; per the rules above a
     * MINOR bump is a warning, not an error.
     *
     * 1.2 (minor, additive): four trailing file-accounting keys in
     * `summary` (files_scanned, files_reused, files_processed,
     * files_without_blocks). Every 1.1 field keeps its name and type, so
     * 1.1 consumers parse 1.2 payloads untouched.
     */
    public const SCHEMA_VERSION = '1.8';

    /**
     * Top-level shape of {@see JsonReporter::build()} output. This is
     * a documentation-grade reference — not a JSON-Schema validator.
     *
     * @return array<string, string>
     */
    public static function topLevelShape(): array
    {
        return [
            'phpdup_version'  => 'string',
            'schema_version'  => 'string',  // mirrors SCHEMA_VERSION
            'summary'         => 'object',
            'config'          => 'object',  // echo of the run config; see configShape()
            'clusters'        => 'list<object>',
            'declared_twins'  => 'list<object>  // item 4.3, trailing (1.3); see declaredTwinShape()',
        ];
    }

    /**
     * Shape of one `declared_twins` row (item 4.3). All values are
     * JSON scalars or null: `keyword` is the lowercased declaration
     * phrase; `reference` the path/symbol it names; `kind` is
     * 'claim' | 'directive' (@phpdup-ignore harvest); `similarity` is
     * the best measured Jaccard (null when unresolvable/unmeasurable);
     * `verdict` is 'holds' | 'drifted' | 'unresolved' | 'unmeasurable'
     * | 'harvested'; `cluster_id` links the claimant's cluster when it
     * is one (canonical FindingId, item 4.4); `finding_id` is set only
     * for directive rows.
     *
     * @return array<string, string>
     */
    public static function declaredTwinShape(): array
    {
        return [
            'file'          => 'string',
            'keyword'       => 'string',
            'reference'     => 'string',
            'kind'          => 'string',
            'line'          => 'int',
            'comment'       => 'string',
            'finding_id'    => 'string|null',
            'target_file'   => 'string|null',
            'target_blocks' => 'int',
            'similarity'    => 'float|null',
            'cluster_id'    => 'string|null',
            'verdict'       => 'string',
        ];
    }

    /**
     * Shape of the `summary` object (the four file-accounting keys are
     * trailing-additive since schema 1.2; the six original keys predate
     * versioning). The legacy `files` key means
     * "files scanned" and keeps its historical position; `files_scanned`
     * restates it. The three accounting buckets are `int|null`: null is
     * emitted by producers that predate per-bucket tracking (library API,
     * server workers) and means "not tracked", distinct from 0 = tracked
     * and empty. When non-null, scanned = reused + processed +
     * without_blocks + parse_errors (see JsonReporter). Since 1.8 five
     * further trailing keys ride here — blocks_oversized (the item-0.6
     * extraction-honesty counter, block-level: it is deliberately NOT
     * part of the file-balance identity above), the three once-S37
     * scan-signal carriers, and summary.ml_status (see the 1.8 changelog
     * on SCHEMA_VERSION).
     *
     * @param bool $armed true asks for the shape of an ARMED run
     *     (idiom_mining on): the unconditional keys above plus the
     *     conditional trailing `idiom_census` block. The default false
     *     states the key set of every ordinary payload — the belts
     *     (JsonReporterTest, CliSmokeTest) pin THAT set against real
     *     bytes, and the conditional key rides only the armed spelling
     *     so an unarmed payload carrying it stays the pin-failure it is
     *     (item 3.1 ingestion, MINE-D; the 2.3 class_shape precedent one
     *     level down).
     *
     * @return array<string, string>
     */
    public static function summaryShape(bool $armed = false): array
    {
        $shape = [
            'files'                => 'int',   // = files scanned (historical key)
            'blocks'               => 'int',
            'parse_errors'         => 'int',
            'clusters'             => 'int',
            'duplicated_lines'     => 'int',
            'total_impact'         => 'int',
            'files_scanned'        => 'int',
            'files_reused'         => 'int|null',
            'files_processed'      => 'int|null',
            'files_without_blocks' => 'int|null',
            // 1.8 trailing (items 0.6 / S37 carriers / S70 ml):
            'blocks_oversized'     => "int|null  // item 0.6: blocks the size ceiling left fully invisible; fresh-extraction count, null = untracked producer",
            'scan_notes'           => 'list<string>  // FileScanner skip notes (once-S37 carrier, emitted since 1.8); [] when the producer tracked none',
            'parse_failures'       => 'list<object{file:string,message:string}>  // per-file parse errors (once-S37 carrier, emitted since 1.8)',
            'clusters_replayed'    => 'bool|null  // cluster-cache replay happened (once-S37 carrier); null = untracked',
            'ml_status'            => "string|null  // master-side ML pair client: 'off'|'idle'|'reachable'|'unreachable'; null = no client wired (fork-zone dials invisible — E2 disclose)",
        ];
        if ($armed) {
            $shape['idiom_census'] = 'object  // item 3.1 ingestion: the census block (see censusBlockShape()); present ONLY on idiom_mining-armed runs — an unarmed payload never grows this key (frozen goldens, 2.3 class_shape posture)';
        }
        return $shape;
    }

    /**
     * Shape of the conditional `summary.idiom_census` block (item 3.1
     * ingestion, MINE-D). `hashes`/`sites` are the corpus-complete census
     * counters (two-pass memory policy lives inside the substrate);
     * `rows` is the top-ranked list of MAXIMAL idiom rows — §5c nested-
     * family suppression applied, order sites desc / files desc / hash
     * asc — capped at JsonReporter::CENSUS_TOP_ROWS (presentation bound,
     * never a counting bound). Row locations follow the substrate's
     * >=2-sites materialisation rule: a hash seen at one site carries an
     * empty list by design, and multi-site samples are capped per hash.
     *
     * @return array<string, string>
     */
    public static function censusBlockShape(): array
    {
        return [
            'hashes' => 'int  // distinct exact-key hashes counted across the corpus',
            'sites'  => 'int  // total occurrences counted (pre-suppression)',
            'rows'   => 'list<object{hash:string,abstract:string,nodes:int,root:string,sites:int,files:int,locations:list<string>}>',
        ];
    }

    /**
     * Shape of the `config` echo block. The block states the
     * configuration the report was produced under, so a consumer can
     * reproduce the payload from the same inputs. Its key set is
     * exactly what JsonReporter::build() emits — governed here for the
     * first time by once-S55; declaring it changes no bytes.
     *
     * Three operator knobs — max_hole_coverage, score_mode and scope —
     * were parked here un-echoed by once-S55: the config block is a
     * key-set contract, not a full dump, and adding emitted keys is a
     * MINOR version event requiring the owner's 1.7→1.8 emit window
     * (plan "Shared resources"; the omission was DISCLOSED, never
     * silently dropped — once-S55 STOP row, reviews/exec/once-S55.impl.md).
     * The window opened (rulings row 3: three config-echo knobs +
     * ml_status + scan-signal keys), and this commit ships the bump: all
     * three now echo, together with `window_oversized` — the item-1.2
     * extraction posture added in the same release, bound into the
     * cache keys so the echo states what the run actually did.
     *
     * @return array<string, string>
     */
    public static function configShape(): array
    {
        return [
            'paths'                => 'list<string>',
            'min_block_size'       => 'int',
            'normalization_mode'   => "string  // 'strict' | 'default' | 'aggressive'",
            'similarity_threshold' => 'float  // Num::json-normalised',
            'tree_threshold'       => 'float  // Num::json-normalised',
            'min_cluster_impact'   => 'int',
            // 1.8 trailing (the window once-S55 parked for):
            'max_hole_coverage'    => 'float  // Num::json-normalised',
            'score_mode'           => "string  // 'global' | 'per-repo' | 'typed'",
            'scope'                => 'string',
            'window_oversized'     => 'bool  // item 1.2 extraction posture',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function pairShape(): array
    {
        return [
            'blockA'     => 'string',
            'blockB'     => 'string',
            'matchTier'  => 'string',
            'matchScore' => 'float',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function clusterShape(): array
    {
        return [
            'id'                     => 'string',
            'kind'                   => "string|null  // the members' shared block kind (method/function/...); null only when the cluster carries no members (defensive — never pipeline-emitted). Governed nullable by once-S55 to match JsonReporter::clusterPayload()'s emission; populating a member-less cluster is a producer bug, not a consumer branch",
            'exact'                  => 'bool',
            'similarity'             => 'float',
            'confidence'             => 'float',
            'safety'                 => 'float',
            'impact'                 => 'int',
            'pattern_tags'           => 'list<string>',
            // Item 2.3: CONDITIONAL key (the only one in this shape) —
            // present only on '--kinds class' clusters, so every
            // default-run payload (the byte-frozen goldens) keeps its
            // historical key set. Value:
            // {shape_exact: bool, class_names: list<string>,
            //  shared_methods: map<method, {members_with_method: int,
            //  distinct_strict_hashes: int, distinct_fold_hashes: int,
            //  verdict: identical-in-all|parameterizable|divergent}>}
            'class_shape'            => '?object  // present ONLY on opt-in class-kind clusters (item 2.3)',
            // Item 6.3: second CONDITIONAL cluster key, same posture —
            // present ONLY on idiom_mining-armed runs whose subsumption
            // fold attributed families to this maximal cluster (the
            // "annotate, do not multiply" surface of the fold; unarmed
            // payloads never grow this key, frozen goldens unchanged).
            'subsumes'               => '?list<string>  // ids of clone families folded under this maximal cluster (item 6.3, conditional)',
            'outlier_members'        => 'list<int>',
            'architectural_findings' => 'list<object>',
            'signature'              => 'string|null',
            'members'                => 'list<object>  // trailing repo/scope keys since 1.4 (item 5.1); see JsonReporter::clusterPayload()',
            'pairs'                  => 'list<object>',
            'holes'                  => 'list<object>  // trailing `degraded` bool since 1.5; `observed` entries may be null — any absence row, since 1.7 see holeShape()',
            'scope'                  => "string  // 'single' | 'multi' — item 5.1, trailing (1.4)",
            'template'               => "?object  // {id,file,start,end} canonical template — item 5.4, trailing (1.5)",
            'source'                 => "object  // {text:string|null,truncated:bool} capped template source (200 lines / 8 KiB) — trailing (1.5)",
            'evidence_diff'          => "?object  // {member,file,start,end,diff,truncated} capped (120 lines / 6 KiB) diff vs median peer; null = no peer, no pair evidence, or identical text — trailing (1.5)",
            'hole_coverage'          => 'float|null  // 0..1 fraction of template AST inside hole subtrees — trailing (1.5)',
            're_expressed'           => 'bool  // hole_coverage > config max_hole_coverage (default 1.0: never) — trailing (1.5)',
            'hole_fragment'          => "?object  // {stmt_start,stmt_length,code,truncated} longest hole-free stmt run, set only when re_expressed — trailing (1.5)",
            'placement'              => 'object  // {shape,rationale,same_host,lca_namespace,host_packages} — item 5.3, trailing (1.6); see placementShape()',
        ];
    }

    /**
     * Shape of one `holes[]` entry (item 5.4, canonicalized by output-S27).
     * `observed` is the deduped value set; a null entry means the member's
     * row was an ABSENCE of some kind — a structural gap, a never-gathered
     * row, or a verified optional-segment absence (since 1.7 all three are
     * the one canonical in-memory marker). On optional holes the
     * `degraded` flag states which reading applies: false means every
     * null row is VERIFIED absence (the member provably lacks the
     * segment); true means incomplete evidence, and the printable
     * pre-1.7 sentinel plus pre-1.5 cache-resident `<missing>` are
     * normalized to null on emission (only the latter lifts `degraded`,
     * exactly as before 1.7). `present_in_members` lists the members
     * with a genuine value row — absence-marked indices never appear,
     * so it is computed positionally from the per-member rows.
     *
     * One placeholder VALUE is sanctioned beyond the unifier's own
     * names: '__cap' (AntiUnifier::CAP_SENTINEL) — the synthetic
     * all-absence, degraded:true row a routed oversized cluster carries
     * in place of un-unified evidence. It is signature-inert and never
     * a parse bug; see the governance note (c) on this class (once-S55).
     *
     * @return array<string, string>
     */
    public static function holeShape(): array
    {
        return [
            'placeholder'        => "string  // unifier placeholder name, or the sanctioned '__cap' degraded-evidence marker (governance (c))",
            'kind'               => 'string',
            'inferred_type'      => 'string',
            'suggested_name'     => 'string',
            'observed'           => 'list<string|null>  // null = absence row (gap, never-gathered, or verified optional absence) — canonical since 1.7',
            'value_count'        => 'int',
            'degraded'           => 'bool  // trailing (1.5)',
            'present_in_members' => 'list<int>  // optional_block only; value-row indices (1.7)',
        ];
    }

    /**
     * Item 5.3 — the placement sub-object every cluster carries since 1.6.
     * shape is one of: 'move' | 'expression_function' | 'data_table' |
     * 'defaulted_function' | 'parameterized_function'. host_packages is a
     * sorted list only when members span >=2 scan roots with readable
     * composer manifests; null means conservative mode (reason folded
     * into rationale).
     *
     * @return array<string,string> field-name = doc
     */
    public static function placementShape(): array
    {
        return [
            'shape'         => "string  // 'move'|'expression_function'|'data_table'|'defaulted_function'|'parameterized_function'",
            'rationale'     => 'string  // sentences; folds in any host-package degradation reason',
            'same_host'     => 'bool  // all members share one namespaced class',
            'lca_namespace' => "string  // longest common namespace prefix, '' when none",
            'host_packages' => 'list<string>|null  // sorted require∪require-dev intersection across >=2 roots; null = conservative mode',
        ];
    }
}
