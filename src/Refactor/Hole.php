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
 *
 * Since output-S26 (php-dup-finder-output-018) the gap row carries a
 * second, wider meaning: it is also the explicit placeholder for any
 * member whose observation at this path was NEVER GATHERED — a hole
 * born while earlier subnodes diverged, or a presence claim about a
 * member that never cleanly visited the path. Either way the value set
 * is incomplete evidence, which is exactly what `degraded` says; the
 * row count always equals the cluster's member count.
 *
 * Since output-S27 (php-dup-finder-output-019) it carries a third —
 * and it is the only representation: the VERIFIED ABSENCE row of an
 * optional segment. When the walk saw a member's slot and it holds no
 * template statement, the row is the same Hole::MISSING, recorded via
 * appendObserved WITHOUT degrading — the absence is complete evidence
 * for that row (T4d: degraded:false optional holes state a fact, the
 * old printable absent-impostor is retired to a render-time projection
 * inside reporters only). Consumers tell the two apart
 * exactly as far as the data can: on an optional hole, a MISSING row
 * with degraded:false is a verified absence, and with degraded:true
 * the hole states honestly that some MISSING rows were never observed.
 */
final class Hole
{
    /** The single canonical absence marker (output-S27): "this member had
     *  no node at — or was never observed at — the hole path". Covers
     *  structural gaps, never-gathered rows (output-018), and verified
     *  optional-segment absences; the degraded flag says which. */
    public const MISSING = '';

    public string $inferredType = 'mixed';
    public string $suggestedName = '';

    /**
     * @param list<string>           $observedValues one row per cluster member,
     *                                               in cluster order; '' entries
     *                                               are the canonical absence —
     *                                               a structural gap (item 5.4),
     *                                               a never-gathered row (output-S26),
     *                                               or a verified optional-segment
     *                                               absence (output-S27; those do
     *                                               not degrade — see class doc).
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

    /**
     * Flag the value set as incomplete evidence WITHOUT adding a row —
     * for degradations the row list itself cannot express (output-018:
     * an optional-segment alignment that silently drops member-side
     * statements the template has no path for).
     */
    public function markDegraded(): void
    {
        $this->degraded = true;
    }

    /** Read accessor for the degraded flag (output-S26 acceptance surface). */
    public function isDegraded(): bool
    {
        return $this->degraded;
    }

    public function uniqueValueCount(): int
    {
        return count(array_unique($this->observedValues));
    }
}
