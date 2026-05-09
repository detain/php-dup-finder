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
