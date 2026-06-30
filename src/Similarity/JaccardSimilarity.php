<?php
declare(strict_types=1);

namespace Phpdup\Similarity;

/**
 * Jaccard similarity over n-gram multisets.
 *
 *   J(A,B) = |A ∩ B|min / |A ∪ B|max
 *
 * Where intersection counts the per-key minimum and union counts the
 * per-key maximum. Equivalent to Jaccard on multisets and is strictly
 * more discriminative than set Jaccard when blocks contain repeating
 * structures (loops with body repetition, etc.).
 */
final class JaccardSimilarity
{
    /**
     * @param array<string,int> $a
     * @param array<string,int> $b
     */
    public function similarity(array $a, array $b): float
    {
        if (!$a || !$b) {
            return 0.0;
        }
        // Iterate $a fully, then only the $b-only keys — avoids a temporary
        // merged-array allocation that array_keys($a + $b) would require.
        $inter = 0;
        $union = 0;
        foreach ($a as $k => $av) {
            $bv = $b[$k] ?? 0;
            $inter += min($av, $bv);
            $union += max($av, $bv);
        }
        foreach ($b as $k => $bv) {
            if (!isset($a[$k])) {
                $union += $bv;
            }
        }
        return $union === 0 ? 0.0 : $inter / $union;
    }
}
