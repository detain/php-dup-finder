<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Validation;

use PhpParser\Node;
use PHPUnit\Framework\TestCase;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Parsing\AstParser;
use Phpdup\Util\LineRange;
use Phpdup\Validation\MutationTester;

/**
 * Coverage for MutationTester probe accounting (php-dup-finder-output-031).
 *
 * Before the fix the probe `continue`d over members whose AST was not
 * loaded, yet counted divergence against the full member list and
 * reported an all-unprobed cluster as `[]` — an optimistic "clean"
 * verdict that silently degraded safety when lazy-AST mode was on.
 */
final class MutationTesterTest extends TestCase
{
    private AstParser $parser;

    protected function setUp(): void
    {
        $this->parser = new AstParser();
    }

    public function testFullyProbedCleanClusterStaysEmpty(): void
    {
        $cluster = new Cluster('c1', [
            $this->blockWithAst('<?php function a($x) { return $x + 1; }'),
            $this->blockWithAst('<?php function b($y) { return $y + 2; }'),
        ], 1.0, true);

        $reasons = (new MutationTester())->probe($cluster);

        $this->assertSame([], $reasons, 'a fully probed side-effect-free cluster still earns a clean verdict');
    }

    public function testPartialSideEffectDivergenceAmongProbed(): void
    {
        $cluster = new Cluster('c2', [
            $this->blockWithAst('<?php function a($x) { return $x + 1; }'),
            $this->blockWithAst('<?php function b($p) { echo $p; }'),
        ], 1.0, false);

        $reasons = (new MutationTester())->probe($cluster);

        $this->assertNotEmpty($reasons);
        $this->assertStringContainsString('1 of 2 probed members', $reasons[0]);
        $this->assertStringContainsString('abstraction may be lossy', $reasons[0]);
    }

    public function testAllProbedSideEffectsEmitsReplayRefusal(): void
    {
        $cluster = new Cluster('c3', [
            $this->blockWithAst('<?php function a($p) { echo $p; }'),
            $this->blockWithAst('<?php function b($q) { echo strtoupper($q); }'),
        ], 1.0, false);

        $reasons = (new MutationTester())->probe($cluster);

        $this->assertNotEmpty($reasons);
        $this->assertStringContainsString('all probed members emit side effects', $reasons[0]);
    }

    /**
     * Core acceptance for output-031: a cluster carrying N null-AST
     * members must surface the accounting string and must NOT be
     * reported clean, even though every member that COULD be probed
     * is side-effect free.
     */
    public function testUnprobedMembersYieldAccountingAndNoCleanVerdict(): void
    {
        $cluster = new Cluster('c4', [
            $this->blockWithAst('<?php function a($x) { return $x + 1; }'),
            $this->blockWithAst('<?php function b($y) { return $y + 1; }'),
            $this->blockWithAst('<?php function c($z) { return $z + 1; }'),
            $this->unprobedBlock(),
            $this->unprobedBlock(),
        ], 1.0, false);

        $reasons = (new MutationTester())->probe($cluster);

        $this->assertNotEmpty($reasons, 'K>0 unprobed members can never produce a clean verdict');
        $this->assertContains('3 of 5 probed (2 unprobed: AST not loaded)', $reasons);
        // No spurious side-effect divergence: the probed members are clean.
        $this->assertCount(1, $reasons, 'accounting line is the only reason when probed members are side-effect free');
    }

    public function testUnprobedMembersDoNotInflateSideEffectDenominator(): void
    {
        // 2 probed (one with a side effect) + 3 unprobed. The divergence
        // label must read "1 of 2 probed members", NOT "1 of 5 members" —
        // the exact denominator bug the finding named.
        $cluster = new Cluster('c5', [
            $this->blockWithAst('<?php function a($x) { return $x + 1; }'),
            $this->blockWithAst('<?php function b($p) { echo $p; }'),
            $this->unprobedBlock(),
            $this->unprobedBlock(),
            $this->unprobedBlock(),
        ], 1.0, false);

        $reasons = (new MutationTester())->probe($cluster);

        $this->assertContains('2 of 5 probed (3 unprobed: AST not loaded)', $reasons);
        $this->assertContains('side-effects present in 1 of 2 probed members — abstraction may be lossy', $reasons);
        $this->assertStringNotContainsString('of 5 members', implode("\n", $reasons));
    }

    public function testAllUnprobedRefusesVerdictRatherThanReturningEmpty(): void
    {
        $cluster = new Cluster('c6', [
            $this->unprobedBlock(),
            $this->unprobedBlock(),
        ], 1.0, false);

        $reasons = (new MutationTester())->probe($cluster);

        $this->assertNotSame([], $reasons, 'an all-unprobed cluster must refuse, not report clean');
        $this->assertCount(1, $reasons);
        $this->assertStringContainsString('all 2 members unprobed', $reasons[0]);
        $this->assertStringContainsString('cannot issue a clean verdict', $reasons[0]);
    }

    public function testEmptyClusterStillReturnsEmpty(): void
    {
        $cluster = new Cluster('c7', [], 1.0, false);

        $this->assertSame([], (new MutationTester())->probe($cluster));
    }

    private function blockWithAst(string $code): Block
    {
        $stmts = $this->parser->parseCode($code);
        $this->assertNotNull($stmts, 'fixture code must parse: ' . $code);

        return $this->makeBlock($stmts[0]);
    }

    private function unprobedBlock(): Block
    {
        $stmts = $this->parser->parseCode('<?php function u() { return 1; }');
        $this->assertNotNull($stmts);
        $block = $this->makeBlock($stmts[0]);
        $block->unloadAst();

        return $block;
    }

    private function makeBlock(Node $ast): Block
    {
        return new Block(
            '/tmp/mutation-tester-fixture.php',
            new LineRange(1, 3),
            'function',
            'Phpdup\\Fixture',
            null,
            'fixture',
            $ast,
        );
    }
}
