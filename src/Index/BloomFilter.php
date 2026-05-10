<?php
declare(strict_types=1);

namespace Phpdup\Index;

/**
 * Fixed-size Bloom filter over arbitrary string elements.
 *
 * Bit width is chosen at construction; the filter uses two
 * MurmurHash3-style hashes (xxHash-128 truncated halves) and
 * Kirsch-Mitzenmacher double hashing to derive K bit positions.
 *
 * For our use (n-gram presence per block) the parameters that matter:
 *
 *   m bits / n elements ≈ -m / (n * ln(2)²)  → false positive rate
 *
 * We default to m=2048 bits per block and k=3 hashes — empirically
 * yields ≈1% FPR on a typical block's 50-200 ngram bag.
 *
 * No deletion. The filter is immutable after the last add(); callers
 * pass it to {@see overlap()} for fast bit-AND popcount.
 */
final class BloomFilter
{
    /** @var array<int,int> word index → 64-bit word */
    private array $words = [];

    public function __construct(
        public readonly int $bits = 2048,
        public readonly int $hashes = 3,
    ) {
        if ($bits <= 0 || ($bits & ($bits - 1)) !== 0) {
            throw new \InvalidArgumentException("bits must be a positive power of two");
        }
        if ($hashes <= 0) {
            throw new \InvalidArgumentException("hashes must be > 0");
        }
        $words = intdiv($bits, 64);
        for ($i = 0; $i < $words; $i++) {
            $this->words[$i] = 0;
        }
    }

    public function add(string $element): void
    {
        foreach ($this->positionsFor($element) as $bit) {
            $w = $bit >> 6;
            $b = $bit & 63;
            $this->words[$w] |= (1 << $b);
        }
    }

    public function mayContain(string $element): bool
    {
        foreach ($this->positionsFor($element) as $bit) {
            $w = $bit >> 6;
            $b = $bit & 63;
            if (($this->words[$w] & (1 << $b)) === 0) {
                return false;
            }
        }
        return true;
    }

    /**
     * Approximate Jaccard-style overlap of two filters: popcount(a&b) /
     * popcount(a|b). Small filters yield zero on disjoint inputs.
     */
    public static function overlap(self $a, self $b): float
    {
        if ($a->bits !== $b->bits) {
            throw new \InvalidArgumentException("bits mismatch: {$a->bits} vs {$b->bits}");
        }
        $and = 0;
        $or  = 0;
        foreach ($a->words as $i => $aw) {
            $bw = $b->words[$i] ?? 0;
            $and += self::popcount($aw & $bw);
            $or  += self::popcount($aw | $bw);
        }
        return $or === 0 ? 0.0 : $and / $or;
    }

    /** @return list<int> bit positions in [0, bits) */
    private function positionsFor(string $s): array
    {
        // Use xxh3 64-bit; split into two 32-bit halves to feed
        // Kirsch-Mitzenmacher's i*h1 + h2 generator.
        $h = hash('xxh3', $s, true);
        $h1 = unpack('N', substr($h, 0, 4))[1];
        $h2 = unpack('N', substr($h, 4, 4))[1];
        $mask = $this->bits - 1;
        $out = [];
        for ($i = 0; $i < $this->hashes; $i++) {
            $out[] = ($h1 + $i * $h2) & $mask;
        }
        return $out;
    }

    /**
     * Per-byte iterative popcount.
     *
     * The classic SWAR popcount overflows to float on PHP for some
     * sign-bit-heavy 64-bit inputs (the `$x - (($x >> 1) & 0x55…)`
     * step underflows when $x is negative). PHP's signed-only ints
     * mean the saturating bit tricks don't translate, so we sum the
     * eight bytes of $x using a 256-entry lookup. Cost is one shift
     * + one AND + one table lookup per byte — fast enough for the
     * filter-overlap inner loop and immune to overflow.
     */
    public static function popcount(int $x): int
    {
        static $table = null;
        if ($table === null) {
            $table = [];
            for ($i = 0; $i < 256; $i++) {
                $c = 0;
                $v = $i;
                while ($v) { $c += $v & 1; $v >>= 1; }
                $table[$i] = $c;
            }
        }
        return $table[$x & 0xFF]
             + $table[($x >> 8)  & 0xFF]
             + $table[($x >> 16) & 0xFF]
             + $table[($x >> 24) & 0xFF]
             + $table[($x >> 32) & 0xFF]
             + $table[($x >> 40) & 0xFF]
             + $table[($x >> 48) & 0xFF]
             + $table[($x >> 56) & 0xFF];
    }
}
