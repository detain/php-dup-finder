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
        $this->assertSame(8, ShapeletSketch::popcount(0xFF));
        $this->assertSame(64, ShapeletSketch::popcount(-1)); // all bits set on 64-bit PHP
    }
}
