<?php
declare(strict_types=1);

namespace Phpdup\Normalization;

/**
 * Marker class kept for module-layout completeness — the actual
 * name-canonicalization rules live inside Normalizer's visitor so the
 * three passes can share a single AST traversal. This class documents
 * the policy in one place.
 *
 * Policy (aggressive mode only):
 *   - method/function/static-call names → __CALLn
 *   - property/static-property names    → __PROPn
 *   - class names in new/instanceof/::  → __CLASSn
 *   - structural functions (isset, count, etc.) keep their names
 */
final class NameCanonicalizer
{
    public const PLACEHOLDER_CALL  = '__CALL';
    public const PLACEHOLDER_PROP  = '__PROP';
    public const PLACEHOLDER_CLASS = '__CLASS';
}
