<?php
declare(strict_types=1);

namespace Phpdup\Util;

/**
 * Shared 64-bit Hamming weight (popcount) for the bit-set similarity paths.
 *
 * {@see \Phpdup\Fingerprint\ShapeletSketch} and {@see \Phpdup\Index\BloomFilter}
 * both score overlap as popcount(a&b) / popcount(a|b). They used to each carry
 * their own counting code — a GMP call behind a per-call extension check plus a
 * bcmath division-loop fallback with an undeclared ext-bcmath dependency, and a
 * private byte-table copy. This is the single implementation they share.
 *
 * Paths:
 *
 *  - {@see of()} prefers GMP when the extension is loaded. The availability
 *    check is hoisted to a once-per-process static lookup, never re-run per
 *    call. When GMP is absent the result comes from {@see table()} instead,
 *    so hosts without ext-gmp (and hosts without ext-bcmath, which the old
 *    fallback required) still count correctly rather than fataling.
 *  - {@see table()} is the always-available path: the eight bytes of $x are
 *    summed through a 256-entry lookup. PHP's arithmetic right-shift is safe
 *    here because every term is masked to its one byte after shifting. The
 *    classic SWAR masks are NOT safe in PHP — the 0x5555…-style constants
 *    overflow to float and the subtraction steps break on sign-bit-heavy
 *    inputs — so this class never uses them.
 *
 * Both paths count the bits of the raw 64-bit pattern, i.e. the unsigned
 * reading of the integer: -1 has weight 64, PHP_INT_MIN has weight 1. They
 * agree on every 64-bit pattern (pinned by the ShapeletSketch/BloomFilter
 * unit tests), so sketch and filter scores are host-portable.
 */
final class Popcount
{
    /**
     * Hamming weight of the unsigned 64-bit reading of $x.
     */
    public static function of(int $x): int
    {
        static $hasGmp = null;
        if ($hasGmp === null) {
            $hasGmp = extension_loaded('gmp');
        }

        if ($hasGmp) {
            // sprintf('%u') renders the raw bit pattern as an unsigned
            // decimal string; gmp_popcount then weights that pattern.
            return gmp_popcount(gmp_init(sprintf('%u', $x), 10));
        }

        return self::table($x);
    }

    /**
     * Forced-fallback path: byte-table sum only, GMP never consulted.
     */
    public static function table(int $x): int
    {
        /** @var ?array<int, int> $table */
        static $table = null;
        if ($table === null) {
            $table = self::buildByteTable();
        }

        return $table[$x & 0xFF]
            + $table[($x >> 8) & 0xFF]
            + $table[($x >> 16) & 0xFF]
            + $table[($x >> 24) & 0xFF]
            + $table[($x >> 32) & 0xFF]
            + $table[($x >> 40) & 0xFF]
            + $table[($x >> 48) & 0xFF]
            + $table[($x >> 56) & 0xFF];
    }

    /**
     * @return array<int, int> byte value → number of set bits
     */
    private static function buildByteTable(): array
    {
        $table = [];
        for ($byte = 0; $byte < 256; $byte++) {
            $count = 0;
            $rest = $byte;
            while ($rest !== 0) {
                $count += $rest & 1;
                $rest >>= 1;
            }
            $table[$byte] = $count;
        }

        return $table;
    }

    private function __construct()
    {
    }
}
