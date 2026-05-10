<?php
declare(strict_types=1);

namespace Phpdup\Fingerprint;

/**
 * Compact int-keyed n-gram bag.
 *
 * The standard n-gram bag is `array<string, int>` — keys are
 * 16-character hex strings (xxh64). For very large corpora the
 * cumulative key storage dominates. CompactNgramBag re-encodes
 * the keys as `int` (truncated 64-bit hash) at the cost of a
 * negligible collision rate (≪ 1% on real corpora).
 *
 * Only the from/to converters live here — the rest of the
 * pipeline keeps using array-shaped bags so this is opt-in.
 */
final class CompactNgramBag
{
    /**
     * Convert a hex-keyed bag to an int-keyed one.
     *
     * @param array<string,int> $bag
     * @return array<int,int>
     */
    public static function compact(array $bag): array
    {
        $out = [];
        foreach ($bag as $hex => $count) {
            // Take all 16 hex chars (the full xxh64 output) but split
            // across two halves to keep the result int-sized on every
            // platform: high 8 hex chars XOR low 8 hex chars → fits
            // in a 32-bit (positive) int and avoids hexdec()'s float
            // promotion on values ≥ 2^63.
            $hi  = (int)hexdec(substr($hex, 0, 8));
            $lo  = (int)hexdec(substr($hex, 8, 8));
            $key = $hi ^ $lo;
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
