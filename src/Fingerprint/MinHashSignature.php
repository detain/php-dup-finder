<?php
declare(strict_types=1);

namespace Phpdup\Fingerprint;

/**
 * MinHash signature for a set of strings (typically n-gram bag keys).
 *
 * The signature is K min-of-hash values taken over K cheaply-derived
 * permutations of one base hash pair (double hashing — see
 * {@see hashesFor()}); the fraction of matching positions between two
 * signatures estimates Jaccard similarity of the underlying sets with
 * the usual bounded error O(1/sqrt(K)). At K=128 the typical error is
 * ≈10%, plenty for a candidate-generation pre-filter.
 *
 * Banding (used by {@see \Phpdup\Index\LshIndex}) splits the
 * signature into bands of {@see ROWS_PER_BAND} rows; two blocks
 * collide in any band → candidate pair. Band/row choice tunes the
 * recall/precision tradeoff: more bands = higher recall, fewer = more
 * precise.
 *
 * Status: NOT WIRED. Neither this class nor {@see \Phpdup\Index\LshIndex}
 * is on the production candidate path today (the live generator is
 * \Phpdup\Index\NgramInvertedIndex). Adjudicated keep-as-tested-
 * building-block by the engine-S31a catalog ledger.
 */
final class MinHashSignature
{
    public const SIZE          = 128;
    public const ROWS_PER_BAND = 4;
    public const BANDS         = self::SIZE / self::ROWS_PER_BAND; // 32

    /**
     * Salt byte appended for the second base hash. crc32() accepts no
     * initial-value argument, so independence of the (h1, h2) pair
     * comes from hashing the element twice with one cheap 1-byte
     * salted copy — still 2 hash calls + 1 allocation per element.
     */
    public const HASH_SALT = "\x01";

    /** Substitute multiplier when the second base hash lands on 0. */
    public const ZERO_GUARD = 0x9E3779B1;

    /**
     * @param array<string|int,int> $bag
     * @return list<int>  list<SIZE> of 32-bit hash values
     */
    public static function compute(array $bag): array
    {
        $sig = array_fill(0, self::SIZE, PHP_INT_MAX);
        if ($bag === []) {
            return $sig;
        }
        foreach (array_keys($bag) as $element) {
            $hashes = self::hashesFor((string)$element);
            foreach ($hashes as $i => $h) {
                if ($h < $sig[$i]) {
                    $sig[$i] = $h;
                }
            }
        }
        return $sig;
    }

    /**
     * Estimate Jaccard similarity between two MinHash signatures.
     *
     * @param list<int> $a
     * @param list<int> $b
     */
    public static function jaccard(array $a, array $b): float
    {
        $size = min(count($a), count($b));
        if ($size === 0) return 0.0;
        $matches = 0;
        for ($i = 0; $i < $size; $i++) {
            if ($a[$i] === $b[$i]) $matches++;
        }
        return $matches / $size;
    }

    /**
     * The SIZE row hashes for one element: row i is the Kirsch–
     * Mitzenmacher double-hash combination
     *
     *     h_i(x) = (h1(x) + i * h2(x)) mod 2^32
     *
     * computed as one integer walk — two crc32() calls per ELEMENT,
     * then SIZE add/mask steps in integer registers.
     *
     * Cost note (engine-028 ledger row 8 / PERF-14): the previous
     * derivation ran 2 crc32() per ROW over a freshly concatenated
     * `element . byte . row` string — 256 hash calls + 512 string
     * allocations per gram, i.e. a ~128× cost multiplier that made
     * this class unaffordable on the thousand-gram bags a candidate
     * pre-filter targets. Per-row string seeding bought slightly
     * stronger row independence than double hashing; the residual
     * bias of the integer walk is dwarfed by the O(1/sqrt(128))
     * MinHash estimation error the signature already carries.
     *
     * Known bias (disclosed, not hidden): when h2 is even the walk
     * steps within a coset of 2^32 / gcd(h2, 2^32); two of the 128
     * rows can only coincide for h2 divisible by 2^25 (probability
     * 2^-25 per element). h2 == 0 (probability 2^-32) is mapped to
     * {@see ZERO_GUARD} so no row function degenerates to a constant.
     *
     * @return list<int> SIZE values, each in [0, 2^32)
     */
    private static function hashesFor(string $element): array
    {
        $h1 = crc32($element) & 0xFFFFFFFF;
        $h2 = crc32($element . self::HASH_SALT) & 0xFFFFFFFF;
        if ($h2 === 0) {
            $h2 = self::ZERO_GUARD;
        }
        $out = [];
        $acc = $h1;
        for ($i = 0; $i < self::SIZE; $i++) {
            $out[] = $acc;
            $acc   = ($acc + $h2) & 0xFFFFFFFF;
        }
        return $out;
    }
}
