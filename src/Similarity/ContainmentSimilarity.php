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
}
