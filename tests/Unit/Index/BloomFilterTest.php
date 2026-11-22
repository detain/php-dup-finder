<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Index;

use PHPUnit\Framework\TestCase;
use Phpdup\Fingerprint\ShapeletSketch;
use Phpdup\Index\BloomFilter;
use Phpdup\Util\Popcount;

final class BloomFilterTest extends TestCase
{
    public function testAddedElementMayContain(): void
    {
        $f = new BloomFilter();
        $f->add('hello');
        $this->assertTrue($f->mayContain('hello'));
    }

    public function testFalsePositiveRateBelowFivePercent(): void
    {
        $f = new BloomFilter(bits: 2048, hashes: 3);
        for ($i = 0; $i < 100; $i++) {
            $f->add("ngram-$i");
        }
        $fp = 0;
        for ($i = 1000; $i < 2000; $i++) {
            if ($f->mayContain("ngram-$i")) $fp++;
        }
        $this->assertLessThan(50, $fp, "FPR should be < 5% on 2048-bit filter with 100 elements");
    }

    public function testIdenticalFiltersOverlapToOne(): void
    {
        $a = new BloomFilter();
        $b = new BloomFilter();
        foreach (['x', 'y', 'z'] as $g) { $a->add($g); $b->add($g); }
        $this->assertEqualsWithDelta(1.0, BloomFilter::overlap($a, $b), 1e-9);
    }

    public function testDisjointFiltersOverlapToZero(): void
    {
        $a = new BloomFilter();
        $b = new BloomFilter();
        foreach (['a', 'b', 'c'] as $g) $a->add($g);
        foreach (['x', 'y', 'z'] as $g) $b->add($g);
        $this->assertLessThan(0.2, BloomFilter::overlap($a, $b));
    }

    public function testRejectsNonPowerOfTwoBits(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new BloomFilter(bits: 1000);
    }

    public function testOverlapRejectsWidthMismatch(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        BloomFilter::overlap(new BloomFilter(1024), new BloomFilter(2048));
    }

    /**
     * The filter's popcount is now the shared byte-table helper; pin its
     * weights on the same edge vectors the sketch uses — zero, the 63-bit
     * case, and the 64-bit high-bit-set (negative) patterns.
     *
     * @return array<string, array{int, int}>
     */
    public static function edgeVectorCases(): array
    {
        return [
            'zero' => [0, 0],
            'bit 63 (63rd bit, sign bit clear)' => [1 << 62, 1],
            'bit 63 plus low bits' => [(1 << 62) | 0xFF, 9],
            'high bit set (64-bit sign bit, PHP_INT_MIN)' => [PHP_INT_MIN, 1],
            'high bit set plus full low byte' => [PHP_INT_MIN | 0xFF, 9],
            'all bits set (-1)' => [-1, 64],
            'all bits set bar low bit (-2)' => [-2, 63],
            'max positive (63 bits)' => [PHP_INT_MAX, 63],
            'low byte full' => [0xFF, 8],
            'low word full' => [0xFFFFFFFF, 32],
        ];
    }

    /**
     * @dataProvider edgeVectorCases
     */
    public function testPopcountMatchesSharedHelperOnEdgeVectors(int $value, int $expected): void
    {
        $this->assertSame($expected, BloomFilter::popcount($value));
        $this->assertSame(BloomFilter::popcount($value), Popcount::table($value));
    }

    /**
     * Cross-path census: on every edge vector the filter's table path, the
     * sketch's GMP-backed shared path, and (where gmp exists) an explicit
     * local gmp reference must agree — the pre-fix divergence risk that made
     * the two classes' popcounts drift apart.
     *
     * @dataProvider edgeVectorCases
     */
    public function testPopcountPathsAgreeAcrossClasses(int $value, int $expected): void
    {
        $this->assertSame($expected, ShapeletSketch::popcount($value));
        $this->assertSame(BloomFilter::popcount($value), ShapeletSketch::popcount($value));

        if (extension_loaded('gmp')) {
            $this->assertSame(
                gmp_popcount(gmp_init(sprintf('%u', $value), 10)),
                BloomFilter::popcount($value),
            );
        }
    }

    public function testOverlapScoresSurvivePopcountDelegation(): void
    {
        // Singleton 1-hash filters: overlap must agree with the bit-set
        // semantics mayContain reports (shared bit → 1.0, disjoint → 0.0).
        // Deterministic for any hash outcome, so no flake on bit collisions.
        $a = new BloomFilter(bits: 64, hashes: 1);
        $b = new BloomFilter(bits: 64, hashes: 1);
        $a->add('x');
        $b->add('y');
        $shareBit = $a->mayContain('y');
        $overlap = BloomFilter::overlap($a, $b);
        $this->assertEqualsWithDelta($shareBit ? 1.0 : 0.0, $overlap, 1e-9);
        $this->assertEqualsWithDelta(1.0, BloomFilter::overlap($a, $a), 1e-9);
    }
}
