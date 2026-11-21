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
    public const SCHEMA_VERSION = '1.3';

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
            'members'                => 'list<object>',
            'pairs'                  => 'list<object>',
            'holes'                  => 'list<object>',
        ];
    }
}
