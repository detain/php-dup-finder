<?php
declare(strict_types=1);

namespace Phpdup\Refactor;

/**
 * A point in the generalized AST where members of a cluster disagree.
 *
 * Each Hole carries:
 *   - placeholder: e.g. "$__P0" — what stands in for the values in the
 *     generalized template
 *   - kind: 'literal' | 'identifier' | 'name' | 'call' | 'subtree'
 *   - observedValues: one entry per cluster member, in the same order
 *   - inferredType: best-guess PHP type for the synthesized parameter
 *   - suggestedName: heuristic parameter name
 *
 * Item 5.4: a structural gap (the member simply had no node at the hole
 * path — a shorter block) is recorded as the EMPTY STRING, never as a
 * printable token. The `<missing>` string the old repr() emitted read
 * like a PHP fragment and was indistinguishable from data. Reporters
 * surface a gap as JSON null plus the `degraded` flag on the hole — a
 * placeholder is a structural fact, not text.
 */
final class Hole
{
    /** Observed-value marker for "this member had no node at the hole path". */
    public const MISSING = '';

    public string $inferredType = 'mixed';
    public string $suggestedName = '';

    /**
     * @param list<string>           $observedValues '' entries are structural gaps (item 5.4).
     * @param bool                   $degraded       true once any observation was recorded as a
     *                                                 structural gap — the value set is incomplete
     *                                                 evidence and reporters say so instead of
     *                                                 inventing text.
     */
    public function __construct(
        public readonly string $placeholder,
        public readonly string $kind,
        public array $observedValues = [],
        public bool $degraded = false,
    ) {
    }

    public function appendObserved(string $repr): void
    {
        $this->observedValues[] = $repr;
    }

    /** Record "no node at the hole path" for the next member (item 5.4). */
    public function appendMissing(): void
    {
        $this->observedValues[] = self::MISSING;
        $this->degraded         = true;
    }

    public function uniqueValueCount(): int
    {
        return count(array_unique($this->observedValues));
    }
}
