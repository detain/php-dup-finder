<?php
declare(strict_types=1);

namespace Phpdup\Refactor;

/**
 * One promoted shared-formal parameter (php-dup-finder-output-006).
 *
 * Pure value shape produced by {@see ParamIntersection::promote()}:
 * the bare variable name plus the display type text of the first
 * agreeing member (null = untyped). Deliberately NOT a {@see Hole}:
 * a promoted parameter is agreement, not divergence — it has no
 * template path, no per-member observed values, and no place in
 * `Cluster::$holePaths`, which is what keeps the apply venue's
 * hole-driven rewrite law untouched until output-S49b wires the
 * generated-function parameter list and the call-site locals around
 * this set.
 */
final class PromotedParam
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $type = null,
    ) {
    }

    /** The '$'-carrying render form (the display convention of CodeParam::varName). */
    public function varName(): string
    {
        return '$' . $this->name;
    }
}
