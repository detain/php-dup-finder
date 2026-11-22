<?php
declare(strict_types=1);

namespace Phpdup\Similarity;

/**
 * Asymmetric similarity: how much of the smaller multiset is contained in the larger.
 *
 *     C(A, B) = |A ∩ B|min / min(sum(A), sum(B))
 *
 * Returns the containment score (smaller n-gram bag / union bag) capped at 1.0 when
 * the smaller multiset is a true subset of the larger (every n-gram of the smaller
 * side is present with at least the same multiplicity). Useful for detecting
 * "near-subset" relationships — e.g. block A has 5 statements and block B has 3 of
 * those 5, so block B is a near-subset of block A even though Jaccard is only ~0.6.
 *
 * The cooperating cluster phase pairs this with a size-ratio guard so the metric
 * doesn't inflate trivially-small overlaps.
 */
final class ContainmentSimilarity
{
    /**
     * @param array<string|int,int> $a
     * @param array<string|int,int> $b
     */
    public function similarity(array $a, array $b): float
    {
        if (!$a || !$b) {
            return 0.0;
        }
        // Iterate $a fully, then only the $b-only keys — avoids a temporary
        // merged-array allocation that array_keys($a + $b) would require.
        $inter   = 0;
        $sumA    = 0;
        $sumB    = 0;
        foreach ($a as $k => $av) {
            $bv = $b[$k] ?? 0;
            $inter += min($av, $bv);
            $sumA  += $av;
            $sumB  += $bv;
        }
        foreach ($b as $k => $bv) {
            if (!isset($a[$k])) {
                $sumB += $bv;
            }
        }
        $smaller = min($sumA, $sumB);
        if ($smaller === 0) {
            return 0.0;
        }
        return $inter / $smaller;
    }

    /**
     * Size ratio of the smaller multiset to the larger — i.e. how comparable the
     * two sides' bulk is. 1.0 means equal size; 0.0 means one is empty. Pair this
     * with the similarity score to filter out trivial overlaps where one bag is a
     * sliver of the other.
     *
     * @param array<string|int,int> $a
     * @param array<string|int,int> $b
     */
    public function sizeRatio(array $a, array $b): float
    {
        $sumA = array_sum($a);
        $sumB = array_sum($b);
        $larger = max($sumA, $sumB);
        if ($larger === 0) return 0.0;
        return min($sumA, $sumB) / $larger;
    }

    /**
     * Both gating metrics in ONE merged pass — containment (as {@see similarity()})
     * and size ratio (as {@see sizeRatio()}).
     *
     * engine-035: the cluster phase's Jaccard-reject branch calls similarity()
     * and sizeRatio() back-to-back on the same two bags. similarity() already
     * walks both bags and accumulates their sums; sizeRatio() then re-walks both
     * via array_sum — a second redundant O(bag) pass per candidate on the
     * engine's most common rejection path. This method exposes the sums the
     * single walk already has.
     *
     * Value-preserving by construction: the containment projection runs the
     * exact arithmetic of similarity() (same loop order, same $inter/$smaller
     * division) and the size-ratio projection the exact arithmetic of
     * sizeRatio() over the same integer sums, so every tuple equals the
     * two-call result bit-for-bit — pinned by ContainmentSimilarityTest.
     *
     * @param array<string|int,int> $a
     * @param array<string|int,int> $b
     * @return array{containment: float, sizeRatio: float}
     */
    public function containmentAndSizeRatio(array $a, array $b): array
    {
        if (!$a || !$b) {
            return ['containment' => 0.0, 'sizeRatio' => 0.0];
        }
        // Same two-walk shape as similarity(): full sweep of $a, then only the
        // $b-only keys. Every key's count lands in exactly one sum either way,
        // so $sumA/$sumB match array_sum($a)/array_sum($b) over the same bag.
        $inter = 0;
        $sumA  = 0;
        $sumB  = 0;
        foreach ($a as $k => $av) {
            $bv    = $b[$k] ?? 0;
            $inter += min($av, $bv);
            $sumA  += $av;
            $sumB  += $bv;
        }
        foreach ($b as $k => $bv) {
            if (!isset($a[$k])) {
                $sumB += $bv;
            }
        }
        $smaller = min($sumA, $sumB);
        $larger  = max($sumA, $sumB);
        // Explicit (float) casts mirror the :float return coercion of
        // similarity()/sizeRatio(): PHP divides two ints to an INT when the
        // quotient is exact (4/4 === int(1)), and the scalar return types
        // silently coerce it to float. An array return has no such coercion,
        // so without these casts a full-containment pair would emit an int
        // edge weight where the fork-zone twin emits float — breaking
        // serial↔fork byte parity (pinned by Clusterer parity tests).
        return [
            'containment' => $smaller === 0 ? 0.0 : (float)($inter / $smaller),
            'sizeRatio'   => $larger === 0 ? 0.0 : (float)($smaller / $larger),
        ];
    }
}
