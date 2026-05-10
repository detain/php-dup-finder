<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Similarity;

use PHPUnit\Framework\TestCase;
use Phpdup\Parsing\AstParser;
use Phpdup\Semantic\DataflowSummarizer;
use Phpdup\Similarity\BehaviouralSimilarity;

final class BehaviouralSimilarityTest extends TestCase
{
    public function testIdenticalBodiesScoreHigh(): void
    {
        $a = $this->stmts('<?php function f($x) { return $x + 1; }');
        $b = $this->stmts('<?php function g($x) { return $x + 1; }');
        $this->assertGreaterThan(0.8, (new BehaviouralSimilarity())->similarity($a, $b));
    }

    public function testForeachVsArrayReduceShareSomeBehaviour(): void
    {
        $a = $this->stmts('<?php function s($items) { $sum = 0; foreach ($items as $i) { $sum += $i; } return $sum; }');
        $b = $this->stmts('<?php function s($items) { return array_reduce($items, fn($a, $b) => $a + $b, 0); }');
        $sim = (new BehaviouralSimilarity())->similarity($a, $b);
        $this->assertGreaterThan(0.0, $sim, 'should detect some similarity even with different control flow');
    }

    public function testWildlyDifferentBodiesScoreLow(): void
    {
        $a = $this->stmts('<?php function add($x, $y) { return $x + $y; }');
        $b = $this->stmts('<?php function send($payload) { $http->post("/api", $payload); echo "ok"; }');
        $this->assertLessThan(0.3, (new BehaviouralSimilarity())->similarity($a, $b));
    }

    public function testSummariserCapturesCallsAndSideEffects(): void
    {
        $node = $this->stmts('<?php function f($x) { $logger->log("hi"); return $x + 1; }');
        $summary = (new DataflowSummarizer())->summarize($node);
        $this->assertArrayHasKey('log', $summary['calls']);
        $this->assertTrue($summary['sideEffects']);
    }

    private function stmts(string $code): \PhpParser\Node
    {
        $stmts = (new AstParser())->parseCode($code);
        return $stmts[0];
    }
}
