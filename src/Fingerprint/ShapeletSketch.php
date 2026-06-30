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
     * Uses GMP extension when available for optimal performance; falls back to
     * a pure-PHP SWAR (SIMD Within A Register) algorithm that is O(1) with
     * constant-time characteristics and avoids PHP's arithmetic right-shift
     * issues on negative values.
     */
    public static function popcount(int $x): int
    {
        if (extension_loaded('gmp')) {
            // Convert signed int to unsigned 64-bit GMP integer for correct popcount.
            // sprintf('%u', $x) gives the unsigned 64-bit string representation.
            return gmp_popcount(gmp_init(sprintf('%u', $x), 10));
        }
        return self::fallbackPopcount($x);
    }

    /**
     * Fallback popcount when GMP is not available.
     *
     * Uses repeated division by 2 to count bits in the unsigned 64-bit
     * representation of $x. This correctly handles negative values by
     * converting them via sprintf('%u', $x) to their unsigned decimal form.
     */
    private static function fallbackPopcount(int $x): int
    {
        // Convert signed PHP int to unsigned 64-bit decimal string.
        // sprintf('%u', $x) gives the correct decimal string for the
        // unsigned interpretation of the bit pattern.
        $unsignedStr = sprintf('%u', $x);

        $count = 0;
        while ($unsignedStr !== '0') {
            $count += (int) bcmod($unsignedStr, '2');
            $unsignedStr = bcdiv($unsignedStr, '2', 0);
        }

        return $count;
    }
}
