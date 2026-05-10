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

    public function testDbTagBoostsScoreForCrossLibraryReads(): void
    {
        // Two functions doing the same single-read operation across
        // different DB libraries should score *higher* than the
        // baseline call-name Jaccard alone — the DB op-tag band
        // collapses the library/extension swap.
        $eloquent = $this->stmts('<?php function f($id) { return User::find($id); }');
        $pdo      = $this->stmts('<?php function f($pdo, $id) {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$id]);
            return $stmt->fetch();
        }');
        $sim = (new BehaviouralSimilarity())->similarity($eloquent, $pdo);
        // Bare AST signal (call names like find vs prepare) gives a
        // very low score; the tag band lifts it materially.
        $this->assertGreaterThan(0.2, $sim,
            'cross-library DB reads should score noticeably above zero with the tag band');
    }

    public function testNonDbBlocksAreUnaffectedByTagBand(): void
    {
        // Pure-arithmetic blocks have empty tag bags — the tag-Jaccard
        // is 1.0 by convention (both empty) which contributes the
        // maximum from that band but doesn't *create* spurious
        // similarity by itself; identical pure functions still score
        // high overall, distinct ones still score low.
        $a = $this->stmts('<?php function add($x, $y) { return $x + $y; }');
        $b = $this->stmts('<?php function send($p) { $http->post("/api", $p); echo "ok"; }');
        // Different shapes still score low even though tag-Jaccard=1
        // (both have empty tag bags) because every other band is
        // very different.
        $this->assertLessThan(0.55, (new BehaviouralSimilarity())->similarity($a, $b));
    }

    private function stmts(string $code): \PhpParser\Node
    {
        $stmts = (new AstParser())->parseCode($code);
        return $stmts[0];
    }
}
