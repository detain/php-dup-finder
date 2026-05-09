<?php
declare(strict_types=1);

namespace Phpdup\Normalization;

/**
 * Marker class kept for module-layout completeness — the actual
 * literal-canonicalization rules live inside Normalizer's visitor so
 * the three passes can share a single AST traversal. This class
 * documents the policy.
 *
 * Policy (default and aggressive modes):
 *   - String_  literal value → "__STR"
 *   - Int_     literal value → 0
 *   - Float_   literal value → 0.0
 *   - InterpolatedString segments collapsed to "__STR"
 */
final class LiteralCanonicalizer
{
    public const PLACEHOLDER_STR   = '__STR';
    public const PLACEHOLDER_INT   = 0;
    public const PLACEHOLDER_FLOAT = 0.0;
}
