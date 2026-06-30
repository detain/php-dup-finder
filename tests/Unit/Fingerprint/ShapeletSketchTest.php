<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Fingerprint;

use PHPUnit\Framework\TestCase;
use Phpdup\Fingerprint\ShapeletSketch;
use Phpdup\Parsing\AstParser;

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
     * Verify that when GMP is available, the fallback and GMP produce identical results.
     * This test is only meaningful when ext-gmp is loaded.
     */
    public function testFallbackMatchesGmpWhenAvailable(): void
    {
        if (!extension_loaded('gmp')) {
            $this->markTestSkipped('GMP extension not available');
        }

        for ($i = 0; $i < 100; $i++) {
            // Generate random values including edge cases
            $value = mt_rand(0, PHP_INT_MAX);

            $gmpResult = gmp_popcount(gmp_init(sprintf('%u', $value), 10));

            // Access fallback directly via reflection for testing
            $reflection = new \ReflectionMethod(ShapeletSketch::class, 'fallbackPopcount');
            $reflection->setAccessible(true);
            $fallbackResult = $reflection->invoke(null, $value);

            $this->assertSame(
                $gmpResult,
                $fallbackResult,
                sprintf('Fallback popcount(%d) = %d, GMP popcount = %d', $value, $fallbackResult, $gmpResult),
            );
        }

        // Also test negative values (two's complement 64-bit representation)
        $negativeValues = [-1, -2, -127, -128, PHP_INT_MIN];
        foreach ($negativeValues as $value) {
            $gmpResult = gmp_popcount(gmp_init(sprintf('%u', $value), 10));

            $reflection = new \ReflectionMethod(ShapeletSketch::class, 'fallbackPopcount');
            $reflection->setAccessible(true);
            $fallbackResult = $reflection->invoke(null, $value);

            $this->assertSame(
                $gmpResult,
                $fallbackResult,
                sprintf('Fallback popcount(%d) = %d, GMP popcount = %d', $value, $fallbackResult, $gmpResult),
            );
        }
    }
}
