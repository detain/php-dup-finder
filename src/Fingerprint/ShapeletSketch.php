<?php
declare(strict_types=1);

namespace Phpdup\Fingerprint;

use PhpParser\Node;
use Phpdup\Util\AstSerializer;
use Phpdup\Util\Popcount;

/**
 * Constant-time structural-similarity sketch: a 64-bit fingerprint that
 * encodes the (node-type, depth) histogram of an AST.
 *
 * Used as a cheap pre-filter for {@see \Phpdup\Similarity\AptedDistance}:
 * two trees with very different depth/type distributions can be rejected
 * by {@see overlap()} in a few ALU ops, avoiding the O(n²) tree-edit DP.
 *
 * Encoding:
 *   - 64-bit integer.
 *   - For every node of type T at depth D, set bit `hash(T, D) % 64`.
 *   - The result captures an approximate structural fingerprint;
 *     dissimilar trees will have low Jaccard-by-popcount.
 *
 * The sketch is collision-tolerant — false negatives (sketches that
 * agree but trees differ) are fine because the tree-edit DP runs after
 * the sketch passes; false positives (sketches that look identical for
 * structurally divergent trees) are bounded by the 64-bit space.
 */
final class ShapeletSketch
{
    /**
     * Compute the 64-bit sketch for a node (root + descendants).
     */
    public static function sketch(Node $root): int
    {
        $bits = 0;
        self::walk($root, 0, $bits);
        return $bits;
    }

    /**
     * Approximate Jaccard overlap of two sketches in [0, 1]. Treats
     * sketches as 64-bit sets where bit-i means "node-type+depth bucket
     * i is present at least once in the tree".
     */
    public static function overlap(int $a, int $b): float
    {
        $union = self::popcount($a | $b);
        if ($union === 0) {
            return 1.0;
        }
        return self::popcount($a & $b) / $union;
    }

    private static function walk(Node $node, int $depth, int &$bits): void
    {
        $cap = $depth > 7 ? 7 : $depth;
        $type = AstSerializer::shortType($node);
        // crc32 is cheap and good enough for bucket distribution.
        $bucket = (crc32($type . '|' . $cap)) & 63;
        $bits |= 1 << $bucket;
        foreach ($node->getSubNodeNames() as $sub) {
            $val = $node->$sub;
            if ($val instanceof Node) {
                self::walk($val, $depth + 1, $bits);
            } elseif (is_array($val)) {
                foreach ($val as $v) {
                    if ($v instanceof Node) {
                        self::walk($v, $depth + 1, $bits);
                    }
                }
            }
        }
    }

    /**
     * Hamming weight: count of set bits in $x, read as the unsigned 64-bit
     * pattern of the raw bits (so -1 weighs 64 and PHP_INT_MIN weighs 1).
     *
     * Delegates to the shared {@see Popcount} helper used by the Bloom
     * filter as well: GMP when the extension is loaded (checked once per
     * process, not per call), otherwise the always-correct byte-table
     * path. The former ext-bcmath division-loop fallback is gone — it
     * duplicated the table logic behind an undocumented extension
     * dependency and its docblock described a SWAR that never existed.
     */
    public static function popcount(int $x): int
    {
        return Popcount::of($x);
    }
}
