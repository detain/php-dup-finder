<?php
declare(strict_types=1);

namespace Phpdup\Fingerprint;

use PhpParser\Node;
use Phpdup\Util\AstSerializer;

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
     * Hamming weight: count of set bits in $x.
     *
     * The textbook bit-twiddling popcount uses an unsigned multiply at
     * the end. PHP only exposes signed 64-bit ints, so we sum nibbles
     * directly via the SWAR pattern up through step 3 and fold the
     * resulting per-byte counts manually. Marginally slower than the
     * single-multiply variant but correct for the full int range.
     */
    public static function popcount(int $x): int
    {
        $x = $x - (($x >> 1) & 0x5555555555555555);
        $x = ($x & 0x3333333333333333) + (($x >> 2) & 0x3333333333333333);
        $x = ($x + ($x >> 4)) & 0x0F0F0F0F0F0F0F0F;
        $sum = 0;
        for ($shift = 0; $shift < 64; $shift += 8) {
            $sum += ($x >> $shift) & 0xFF;
        }
        return $sum;
    }
}
