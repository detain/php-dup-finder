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
     * 1.2 (minor, additive): four trailing file-accounting keys in
     * `summary` (files_scanned, files_reused, files_processed,
     * files_without_blocks). Every 1.1 field keeps its name and type, so
     * 1.1 consumers parse 1.2 payloads untouched — per the rules above a
     * MINOR bump is a warning, not an error.
     */
    public const SCHEMA_VERSION = '1.2';

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
