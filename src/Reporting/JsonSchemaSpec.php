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
 */
final class JsonSchemaSpec
{
    /**
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
    public const SCHEMA_VERSION = '1.5';

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
            'config'          => 'object',
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
     * Shape of the `summary` object (0.6). The legacy `files` key means
     * "files scanned" and keeps its historical position; `files_scanned`
     * restates it. The three accounting buckets are `int|null`: null is
     * emitted by producers that predate per-bucket tracking (library API,
     * server workers) and means "not tracked", distinct from 0 = tracked
     * and empty. When non-null, scanned = reused + processed +
     * without_blocks + parse_errors (see JsonReporter).
     *
     * @return array<string, string>
     */
    public static function summaryShape(): array
    {
        return [
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
            'kind'                   => 'string',
            'exact'                  => 'bool',
            'similarity'             => 'float',
            'confidence'             => 'float',
            'safety'                 => 'float',
            'impact'                 => 'int',
            'pattern_tags'           => 'list<string>',
            'outlier_members'        => 'list<int>',
            'architectural_findings' => 'list<object>',
            'signature'              => 'string|null',
            'members'                => 'list<object>  // trailing repo/scope keys since 1.4 (item 5.1); see JsonReporter::clusterPayload()',
            'pairs'                  => 'list<object>',
            'holes'                  => 'list<object>  // trailing `degraded` bool since 1.5; `observed` entries may be null (structural gap) — see holeShape()',
            'scope'                  => "string  // 'single' | 'multi' — item 5.1, trailing (1.4)",
            'template'               => "?object  // {id,file,start,end} canonical template — item 5.4, trailing (1.5)",
            'source'                 => "object  // {text:string|null,truncated:bool} capped template source (200 lines / 8 KiB) — trailing (1.5)",
            'evidence_diff'          => "?object  // {member,file,start,end,diff,truncated} capped (120 lines / 6 KiB) diff vs median peer; null = no peer, no pair evidence, or identical text — trailing (1.5)",
            'hole_coverage'          => 'float|null  // 0..1 fraction of template AST inside hole subtrees — trailing (1.5)',
            're_expressed'           => 'bool  // hole_coverage > config max_hole_coverage (default 1.0: never) — trailing (1.5)',
            'hole_fragment'          => "?object  // {stmt_start,stmt_length,code,truncated} longest hole-free stmt run, set only when re_expressed — trailing (1.5)",
        ];
    }

    /**
     * Shape of one `holes[]` entry (item 5.4). `observed` is the deduped
     * value set; a null entry means the member had NO node at the hole
     * path (structural gap — shorter block), and `degraded` is then true.
     * The legacy printable sentinels `<absent>` (optional_block presence)
     * and pre-1.5 cache-resident `<missing>` keep their semantics; the
     * latter is normalized to null on emission.
     *
     * @return array<string, string>
     */
    public static function holeShape(): array
    {
        return [
            'placeholder'        => 'string',
            'kind'               => 'string',
            'inferred_type'      => 'string',
            'suggested_name'     => 'string',
            'observed'           => 'list<string|null>',
            'value_count'        => 'int',
            'degraded'           => 'bool  // trailing (1.5)',
            'present_in_members' => 'list<int>  // optional_block only',
        ];
    }
}
