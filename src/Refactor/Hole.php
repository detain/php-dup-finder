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
 *
 * Since item 4.1 the row list has a scalar sidecar: {@see
 * self::$descriptors}, positionally aligned with $observedValues,
 * carrying an {@see ObservationDescriptor} per observation. It draws
 * the per-row distinction the hole-wide degraded flag cannot: a
 * MISSING row whose descriptor type is ObservationDescriptor::ABSENT_TYPE
 * is a WITNESSED absence even on a degraded hole, while a MISSING row
 * with a null descriptor is ambiguous (never-gathered pad or pre-4.1
 * legacy) and no consumer may claim against it. Holes built without
 * the sidecar (older fixtures, hand-made test holes) simply carry
 * fewer descriptor rows — {@see self::descriptorAt()} answers null
 * there, which reads exactly as "unwitnessed".
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
    /**
     * Item 4.1: scalar observation descriptors, positionally aligned
     * with $observedValues. One entry per row: the observed node's
     * {@see ObservationDescriptor}, the ABSENT marker of a witnessed
     * optional-segment absence, or null (never-gathered gap, or a
     * legacy row written before the sidecar existed).
     *
     * @var list<array{type: string, callNames: list<string>, containsExit: bool, scalar: ?string}|null>
     */
    public array $descriptors = [];

    /**
     * Item 4.1: descriptor of the TEMPLATE-side node this hole diverges
     * on, captured at hole birth where the node is in hand (null for
     * whole-array collapses, which have no template node, and for holes
     * built outside AntiUnifier). It is what lets the drift analyzer
     * state "the guard the cluster's template holds" without the (often
     * stripped, cached-run) generalizedAst.
     *
     * @var array{type: string, callNames: list<string>, containsExit: bool, scalar: ?string}|null
     */
    public ?array $templateDescriptor = null;

    public function __construct(
        public readonly string $placeholder,
        public readonly string $kind,
        public array $observedValues = [],
        public bool $degraded = false,
    ) {
    }

    public function appendObserved(string $repr, ?array $descriptor = null): void
    {
        $this->observedValues[] = $repr;
        $this->descriptors[]    = $descriptor;
    }

    /** Record "no node at the hole path" for the next member (item 5.4). */
    public function appendMissing(): void
    {
        $this->observedValues[] = self::MISSING;
        $this->descriptors[]    = null;
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

    /**
     * Item 4.1: the descriptor of row $memberIndex, null when the row
     * was never witnessed (gap pad), carries no sidecar (legacy or
     * hand-built hole), or the index is out of range.
     *
     * @return array{type: string, callNames: list<string>, containsExit: bool, scalar: ?string}|null
     */
    public function descriptorAt(int $memberIndex): ?array
    {
        return $this->descriptors[$memberIndex] ?? null;
    }

    /**
     * Item 4.1: was member $memberIndex WITNESSED absent at this hole —
     * the row is the canonical absence marker AND carries the absent
     * descriptor recorded at the alignment site? This is the strong
     * reading of {@see self::MISSING}: unlike the degraded flag (a
     * hole-wide hedge), it licenses a per-member absence claim even on
     * a degraded alignment. MISSING-without-marker stays ambiguous.
     */
    public function isVerifiedAbsent(int $memberIndex): bool
    {
        $row = $this->observedValues[$memberIndex] ?? self::MISSING;
        if ($row !== self::MISSING) {
            return false;
        }
        $descriptor = $this->descriptorAt($memberIndex);
        return $descriptor !== null && $descriptor['type'] === ObservationDescriptor::ABSENT_TYPE;
    }

    public function uniqueValueCount(): int
    {
        return count(array_unique($this->observedValues));
    }
}
