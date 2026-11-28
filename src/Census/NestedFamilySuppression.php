<?php
declare(strict_types=1);

namespace Phpdup\Census;

/**
 * THE nested-family suppression rule (§5c): implemented exactly once
 * and shared by 3.1 (this census), 3.5 (the call-sequence census's
 * window rows), 6.3 and 7.2c — those consumers pass
 * their own support maps and embedding edges; the semantics never get a
 * second copy.
 *
 * Law, from the 3.1 letter: "drop any subtree that is contained in a
 * more frequent or equally frequent larger subtree" — containment is
 * witnessed by mining edges (child appears embedded under a larger
 * enumerated key), and a key that outranks every one of its observed
 * parents survives because its EXTRA occurrences are real idiom uses
 * the parent shape cannot account for (a genuine idiom reused inside
 * larger noise still counts).
 *
 * Semantics (deterministic, no hidden state):
 *  - $support: key => occurrence count (sites). Only listed keys take
 *    part; an edge endpoint that is not in $support is ignored (its
 *    parent is not in the running, so it suppresses nothing).
 *  - $edges:   parentKey => childKey => edgeCount (counts unused for
 *    the >= decision; shape kept for witness explanations).
 *  - A child is suppressed iff it has at least one LISTED parent whose
 *    support >= the child's own support. Equal support suppresses: the
 *    child is then pure nesting noise around the parent (its every
 *    sighting sits inside an equally-sighted larger shape).
 *  - Suppression is decided against ORIGINAL supports (one shot): a
 *    suppressed parent still suppresses its children — the children's
 *    occurrences lived inside the parent occurrences regardless of
 *    whether the parent itself survives the maximal filter. Chains
 *    therefore fall out of the same computation with no recursion and
 *    no order dependence.
 */
final class NestedFamilySuppression
{
    /**
     * @param array<string, int> $support key => sites
     * @param array<string, array<string, int>> $edges parent => child => count
     * @return list<string> keys surviving the maximal-family filter
     */
    public static function keepMaximal(array $support, array $edges): array
    {
        /** @var array<string, int> $maxParentSupport */
        $maxParentSupport = [];
        foreach ($edges as $parent => $children) {
            $parentSupport = $support[$parent] ?? null;
            if ($parentSupport === null) {
                continue; // parent not listed => suppresses nothing
            }
            foreach (array_keys($children) as $child) {
                if ($child === $parent) {
                    continue; // defensive: a self-edge witnesses nothing
                }
                if (!isset($maxParentSupport[$child]) || $maxParentSupport[$child] < $parentSupport) {
                    $maxParentSupport[$child] = $parentSupport;
                }
            }
        }

        $keep = [];
        foreach ($support as $key => $sites) {
            $parentSupport = $maxParentSupport[$key] ?? null;
            if ($parentSupport !== null && $parentSupport >= $sites) {
                continue; // contained in an equally/more frequent larger key
            }
            $keep[] = $key;
        }
        sort($keep); // deterministic for any consumer
        return $keep;
    }
}
