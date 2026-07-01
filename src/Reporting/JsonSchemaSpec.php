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
    public const SCHEMA_VERSION = '1.1';

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
