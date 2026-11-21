<?php
declare(strict_types=1);

namespace Phpdup\Fingerprint;

/**
 * Compact int-keyed n-gram bag.
 *
 * The standard n-gram bag is `array<string, int>` — keys are
 * 16-character hex strings (xxh64). For very large corpora the
 * cumulative key storage dominates. CompactNgramBag re-encodes
 * each key as the digest's full 64-bit value held in a PHP int
 * and masked positive (sign bit cleared, 63-bit keyspace), at a
 * collision cost bounded by birthday math: ≈1% pairwise-collision
 * probability needs ≈4.3×10⁸ distinct grams in ONE corpus —
 * orders of magnitude beyond any real codebase.
 *
 * The previous scheme XOR-folded the two 32-bit halves of the
 * digest into a 32-bit key (php-dup-finder-engine-042). Two
 * defects: (a) the fold is many-to-one well beyond birthday noise
 * — every `hi ^ lo == hi' ^ lo'` pair merges (e.g. the digests
 * `0000000100000002` and `0000000200000001` both folded to 3),
 * and (b) birthday math on a 2³² keyspace crosses ≈1% at ~9k
 * distinct grams, so the old "negligible (≪ 1%)" claim held only
 * per-block, never corpus-wide: a large corpus measurably
 * inflated Jaccard intersections through silently merged grams.
 * A masked-positive 63-bit int key costs the same 8 bytes an
 * int bucket always did, and its only merge class is digests
 * differing solely in bit 63 (see {@see compact()}).
 *
 * Only the from/to converters live here — the rest of the
 * pipeline keeps using array-shaped bags so this is opt-in.
 *
 * Requires 64-bit integers: {@see compact()} refuses 32-bit
 * builds loudly (the mask is unrepresentable there and the fold
 * era's cross-platform claim is retired with this fix).
 *
 * Cache roster: compacted bags are persisted inside cached Blocks
 * (IndexStore snapshots, ClusterCache payloads). This key-species
 * change makes every pre-fix low-memory snapshot stale; the
 * IndexStore PARSER_VERSION / ClusterCache version roster carries
 * that invalidation (engine-S32 report row, folded at engine-S18b).
 */
final class CompactNgramBag
{
    /**
     * Convert a hex-keyed bag to an int-keyed one.
     *
     * Each 16-char xxh64 hex key becomes the digest value with the
     * sign bit cleared — the int64 bit pattern `& PHP_INT_MAX`,
     * computed from the two 32-bit halves so hexdec() can never
     * hit its float-promotion path (no value above 2³² is ever
     * handed to it). Equivalent to `unpack('J', pack('H16', $hex))
     * [1] & PHP_INT_MAX` on 64-bit builds, without depending on
     * the version-dependent float behavior of unsigned unpack for
     * high-bit values.
     *
     * The mapping is injective modulo the discarded sign bit: two
     * digests differing ONLY in bit 63 share a key and their
     * counts merge additively — the deliberate, disclosed price of
     * positive-only keys. Numeric-string keys (an xxh64 digest can
     * be all decimal digits) are re-stringified because PHP casts
     * such array keys to int on insertion.
     *
     * @param array<string,int> $bag
     * @return array<int,int>
     */
    public static function compact(array $bag): array
    {
        if (PHP_INT_SIZE < 8) {
            throw new \RuntimeException(
                'CompactNgramBag requires 64-bit integers; low-memory n-gram bags'
                . ' are not available on a 32-bit PHP build.',
            );
        }

        $out = [];
        foreach ($bag as $hex => $count) {
            $hex = (string)$hex;
            // Split at 32 bits: every hexdec() input is ≤ 0xFFFFFFFF,
            // always a lossless int on 64-bit PHP. Clear the digest's
            // sign bit (it lives in the high half's top bit), then
            // rejoin: key = (hi & 0x7FFFFFFF) << 32 | lo.
            $hi  = ((int)hexdec(substr($hex, 0, 8))) & 0x7FFFFFFF;
            $lo  = (int)hexdec(substr($hex, 8, 8));
            $key = ($hi << 32) | $lo;
            $out[$key] = ($out[$key] ?? 0) + $count;
        }
        return $out;
    }

    /**
     * Estimate Jaccard between two int-keyed compact bags.
     *
     * @param array<int,int> $a
     * @param array<int,int> $b
     */
    public static function jaccard(array $a, array $b): float
    {
        $intersection = 0;
        $union = 0;
        foreach ($a as $key => $cntA) {
            $cntB = $b[$key] ?? 0;
            $intersection += min($cntA, $cntB);
            $union        += max($cntA, $cntB);
        }
        foreach ($b as $key => $cntB) {
            if (!isset($a[$key])) {
                $union += $cntB;
            }
        }
        return $union > 0 ? $intersection / $union : 0.0;
    }
}
