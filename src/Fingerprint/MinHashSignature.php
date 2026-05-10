<?php
declare(strict_types=1);

namespace Phpdup\Fingerprint;

/**
 * MinHash signature for a set of strings (typically n-gram bag keys).
 *
 * The signature is K independent min-of-hash values; the fraction of
 * matching positions between two signatures estimates Jaccard
 * similarity of the underlying sets with bounded error
 * O(1/sqrt(K)). At K=128 the typical error is ≈10%, plenty for the
 * candidate-generation pre-filter.
 *
 * Banding (used by {@see \Phpdup\Index\LshIndex}) splits the
 * signature into bands of {@see ROWS_PER_BAND} rows; two blocks
 * collide in any band → candidate pair. Band/row choice tunes the
 * recall/precision tradeoff: more bands = higher recall, fewer = more
 * precise.
 */
final class MinHashSignature
{
    public const SIZE          = 128;
    public const ROWS_PER_BAND = 4;
    public const BANDS         = self::SIZE / self::ROWS_PER_BAND; // 32

    /**
     * @param array<string,int> $bag
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
     * @return list<int> SIZE distinct 32-bit hashes derived via
     *                   double-hashing — h_i(x) = (h1(x) + i * h2(x)) mod 2^32.
     */
    private static function hashesFor(string $element): array
    {
        $bin = hash('xxh3', $element, true);
        $h1 = unpack('N', substr($bin, 0, 4))[1];
        $h2 = unpack('N', substr($bin, 4, 4))[1];
        if ($h2 === 0) $h2 = 0x9E3779B1; // golden ratio prime; avoid k*0
        $out = [];
        for ($i = 0; $i < self::SIZE; $i++) {
            $out[] = ($h1 + $i * $h2) & 0xFFFFFFFF;
        }
        return $out;
    }
}
