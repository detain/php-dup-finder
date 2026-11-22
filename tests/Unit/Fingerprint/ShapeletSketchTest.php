<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Fingerprint;

use PHPUnit\Framework\TestCase;
use Phpdup\Fingerprint\ShapeletSketch;
use Phpdup\Parsing\AstParser;
use Phpdup\Util\Popcount;

final class ShapeletSketchTest extends TestCase
{
    public function testIdenticalTreesProduceIdenticalSketch(): void
    {
        $parser = new AstParser();
        $a = $parser->parseCode('<?php function f($x) { return $x + 1; }')[0];
        $b = $parser->parseCode('<?php function f($x) { return $x + 1; }')[0];
        $this->assertSame(ShapeletSketch::sketch($a), ShapeletSketch::sketch($b));
    }

    public function testDivergentShapesHaveLowOverlap(): void
    {
        $parser = new AstParser();
        $a = $parser->parseCode('<?php function f($x) { return $x + 1; }')[0];
        $b = $parser->parseCode('<?php class C { public function m(): void { for ($i=0;$i<10;$i++) { yield $i; } } }')[0];
        $overlap = ShapeletSketch::overlap(
            ShapeletSketch::sketch($a),
            ShapeletSketch::sketch($b),
        );
        $this->assertLessThan(0.7, $overlap, 'wildly different trees should have <0.7 overlap');
    }

    public function testSimilarShapesHaveHighOverlap(): void
    {
        $parser = new AstParser();
        $a = $parser->parseCode('<?php function f($x) { return $x + 1; }')[0];
        $b = $parser->parseCode('<?php function g($y) { return $y + 2; }')[0];
        $overlap = ShapeletSketch::overlap(
            ShapeletSketch::sketch($a),
            ShapeletSketch::sketch($b),
        );
        $this->assertGreaterThan(0.7, $overlap, 'siblings should have >0.7 overlap');
    }

    public function testOverlapWithEmptySketchesIsOne(): void
    {
        $this->assertSame(1.0, ShapeletSketch::overlap(0, 0));
    }

    public function testPopcount(): void
    {
        $this->assertSame(0, ShapeletSketch::popcount(0));
        $this->assertSame(1, ShapeletSketch::popcount(1));
        $this->assertSame(1, ShapeletSketch::popcount(2));
        $this->assertSame(2, ShapeletSketch::popcount(3));
        $this->assertSame(8, ShapeletSketch::popcount(0xFF));
        $this->assertSame(32, ShapeletSketch::popcount(0xFFFFFFFF));
        $this->assertSame(64, ShapeletSketch::popcount(-1)); // all 64 bits set (0xFFFFFFFFFFFFFFFF as unsigned)
    }

    /**
     * Edge vectors pinned to hand-computed weights: the zero word, the
     * 63-bit case, the 64-bit high-bit-set (negative) cases, and the
     * mixed low-byte cases. Counted over the raw unsigned 64-bit pattern.
     *
     * @return array<string, array{int, int}>
     */
    public static function edgeVectorCases(): array
    {
        return [
            'zero' => [0, 0],
            'one' => [1, 1],
            'bit 63 (63rd bit, sign bit clear)' => [1 << 62, 1],
            'bit 63 plus low bit' => [(1 << 62) | 1, 2],
            'high bit set (64-bit sign bit, PHP_INT_MIN)' => [PHP_INT_MIN, 1],
            'high bit set plus full low byte' => [PHP_INT_MIN | 0xFF, 9],
            'all bits set (-1)' => [-1, 64],
            'all bits set bar low bit (-2)' => [-2, 63],
            'max positive (63 bits)' => [PHP_INT_MAX, 63],
            'low byte full' => [0xFF, 8],
            'low halfword full' => [0xFFFF, 16],
            'low word full' => [0xFFFFFFFF, 32],
        ];
    }

    /**
     * The shared-path popcount (GMP-backed on gmp hosts) and the forced
     * byte-table fallback must both return the pinned weights — the old
     * bcmath division loop is gone and the table path is now exercised
     * directly, so hosts without gmp (or without bcmath) count too.
     *
     * @dataProvider edgeVectorCases
     */
    public function testEdgeVectorsPinBothPopcountPaths(int $value, int $expected): void
    {
        $this->assertSame($expected, ShapeletSketch::popcount($value), 'shared popcount path');
        $this->assertSame($expected, Popcount::table($value), 'forced table fallback');
    }

    /**
     * Table path == GMP path on edge vectors plus a random sweep, checked
     * against an explicit local gmp_popcount reference (not the class's own
     * dispatch), so the two paths can never silently diverge again.
     *
     * @dataProvider edgeVectorCases
     */
    public function testTableMatchesGmpReference(int $value, int $expected): void
    {
        if (!extension_loaded('gmp')) {
            $this->markTestSkipped('GMP extension not available');
        }

        $gmpReference = gmp_popcount(gmp_init(sprintf('%u', $value), 10));
        $this->assertSame($expected, $gmpReference, 'gmp reference agrees with the pin');
        $this->assertSame($gmpReference, Popcount::table($value), 'table matches gmp');
        $this->assertSame($gmpReference, Popcount::of($value), 'dispatch matches gmp');
    }

    public function testTableMatchesGmpAcrossRandomSweep(): void
    {
        if (!extension_loaded('gmp')) {
            $this->markTestSkipped('GMP extension not available');
        }

        $values = [];
        for ($i = 0; $i < 100; $i++) {
            $values[] = mt_rand(0, PHP_INT_MAX);
            $values[] = mt_rand(PHP_INT_MIN, PHP_INT_MAX); // negatives exercise the sign-extended bytes
        }

        foreach ($values as $value) {
            $gmpReference = gmp_popcount(gmp_init(sprintf('%u', $value), 10));
            $this->assertSame(
                $gmpReference,
                Popcount::table($value),
                sprintf('table popcount(%d) diverged from gmp popcount', $value),
            );
            $this->assertSame(
                $gmpReference,
                ShapeletSketch::popcount($value),
                sprintf('shared popcount(%d) diverged from gmp popcount', $value),
            );
        }
    }

    public function testOverlapUsesPinnedWeightsOnHandCraftedSketches(): void
    {
        // 0xFF & 0xF0 = 0xF0 (4 bits); union = 0xFF (8 bits) → 0.5 exactly.
        $this->assertSame(0.5, ShapeletSketch::overlap(0xFF, 0xF0));
        // Disjoint halves of the 64-bit word: ~0xFFFFFFFF is the negative
        // int whose raw pattern is the high 32 bits (0xFFFFFFFF00000000);
        // writing that hex literal directly would overflow to float.
        $this->assertSame(0.0, ShapeletSketch::overlap(0xFFFFFFFF, ~0xFFFFFFFF));
        // Full word against itself: 64/64.
        $this->assertSame(1.0, ShapeletSketch::overlap(-1, -1));
    }
}
