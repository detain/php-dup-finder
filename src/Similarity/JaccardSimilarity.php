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
        $inter = 0;
        $union = 0;
        $keys = $a + $b;
        foreach (array_keys($keys) as $k) {
            $av = $a[$k] ?? 0;
            $bv = $b[$k] ?? 0;
            $inter += min($av, $bv);
            $union += max($av, $bv);
        }
        return $union === 0 ? 0.0 : $inter / $union;
    }
}
