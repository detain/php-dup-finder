<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Similarity;

use PHPUnit\Framework\TestCase;
use Phpdup\Extraction\BlockExtractor;
use Phpdup\Normalization\Normalizer;
use Phpdup\Parsing\AstParser;
use Phpdup\Similarity\AptedDistance;

final class AptedDistanceTest extends TestCase
{
    public function testIdenticalTreesAreSimilarityOne(): void
    {
        $a = $this->canonical('<?php function f($x) { if ($x > 10) { return "a"; } return "b"; }');
        $sim = (new AptedDistance())->similarity($a, $a);
        $this->assertEqualsWithDelta(1.0, $sim, 0.001);
    }

    public function testRenamedVariablesAreFullySimilarUnderAggressiveNormalization(): void
    {
        $a = $this->canonical('<?php function f($items) { $sum = 0; foreach ($items as $i) { $sum += $i; } return $sum; }');
        $b = $this->canonical('<?php function g($entries) { $total = 0; foreach ($entries as $e) { $total += $e; } return $total; }');
        $this->assertEqualsWithDelta(1.0, (new AptedDistance())->similarity($a, $b), 0.001);
    }

    public function testCompletelyDifferentTreesAreLowSimilarity(): void
    {
        $a = $this->canonical('<?php function f($x) { return $x * 2; }');
        $b = $this->canonical('<?php function g($items) { foreach ($items as $i) { for ($j = 0; $j < 10; $j++) { echo $i + $j; } } }');
        $sim = (new AptedDistance())->similarity($a, $b);
        $this->assertLessThan(0.5, $sim, "unrelated trees produced similarity $sim");
    }

    public function testBoundedDistanceShortCircuitsBelowThreshold(): void
    {
        $a = $this->canonical('<?php function f($x) { return $x * 2; }');
        $b = $this->canonical('<?php function g($items) { foreach ($items as $i) { for ($j = 0; $j < 10; $j++) { echo $i + $j; } } }');
        $sim = (new AptedDistance())->similarity($a, $b, 0.95);
        $this->assertSame(0.0, $sim, 'pair below threshold must short-circuit to 0.0');
    }

    public function testNearDuplicatesScoreHighButNotPerfect(): void
    {
        // Same shape, different literal value (under aggressive both literals → 0
        // so they should fully match — this asserts canonicalisation
        // is doing its job before TED).
        $a = $this->canonical('<?php function f($u, $s) { if ($s > 10) { send("admin", $u); } }');
        $b = $this->canonical('<?php function g($u, $s) { if ($s > 99) { send("editor", $u); } }');
        $sim = (new AptedDistance())->similarity($a, $b);
        $this->assertGreaterThan(0.95, $sim, "expected high similarity for parameterizable variants, got $sim");
    }

    public function testSimilarityIsDeterministicAcrossMultipleCalls(): void
    {
        $a = $this->canonical('<?php function f($x) { if ($x > 10) { return "a"; } return "b"; }');
        $b = $this->canonical('<?php function g($y) { if ($y > 20) { return "x"; } return "y"; }');

        $sim1 = (new AptedDistance())->similarity($a, $b);
        $sim2 = (new AptedDistance())->similarity($a, $b);
        $sim3 = (new AptedDistance())->similarity($a, $b);

        $this->assertEquals($sim1, $sim2, 'similarity must be deterministic across calls');
        $this->assertEquals($sim2, $sim3, 'similarity must be deterministic across calls');
    }

    public function testNoMemoryLeakFromRepeatedSimilarityCalls(): void
    {
        $a = $this->canonical('<?php function f($x) { if ($x > 10) { return "a"; } return "b"; }');
        $b = $this->canonical('<?php function g($y) { if ($y > 20) { return "x"; } return "y"; }');

        $apted = new AptedDistance();

        $memBefore = memory_get_usage(true);
        for ($i = 0; $i < 1000; $i++) {
            $apted->similarity($a, $b);
        }
        $memAfter = memory_get_usage(true);

        $growth = $memAfter - $memBefore;
        $this->assertLessThanOrEqual(
            4 * 1024 * 1024,
            $growth,
            'Repeated similarity() calls should not cause significant memory growth'
        );
    }

    public function testDifferentParserInstancesProduceSameSimilarity(): void
    {
        $code = '<?php function f($x) { return $x * 2; }';

        $a1 = $this->canonical($code);
        $a2 = $this->canonical($code);

        $b1 = $this->canonical('<?php function g($y) { return $y * 3; }');
        $b2 = $this->canonical('<?php function h($z) { return $z * 3; }');

        $sim1 = (new AptedDistance())->similarity($a1, $b1);
        $sim2 = (new AptedDistance())->similarity($a2, $b2);
        $this->assertEquals($sim1, $sim2, 'same tree structure must give same similarity');
    }

    private function canonical(string $code, string $mode = 'aggressive'): \PhpParser\Node
    {
        $parser = new AstParser();
        $extractor = new BlockExtractor(minSize: 1);
        $stmts = $parser->parseCode($code);
        $blocks = $extractor->extract('test.php', $stmts);
        $this->assertNotEmpty($blocks);
        (new Normalizer($mode))->normalize($blocks[0]);
        return $blocks[0]->canonical;
    }
}
