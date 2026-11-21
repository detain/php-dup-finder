<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Normalization;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PHPUnit\Framework\TestCase;
use Phpdup\Extraction\Block;
use Phpdup\Extraction\BlockExtractor;
use Phpdup\Fingerprint\SubtreeHasher;
use Phpdup\Normalization\Normalizer;
use Phpdup\Parsing\AstParser;

/**
 * Regression pins for BUG-A (improve_logic.md item 0.3): a switch case whose
 * body is a single bare `return;` used to fatal Normalizer's switch→match
 * folding (Arg::__construct(null) TypeError) — poisoning every forked
 * PreprocessWorker that drew the file. The fix must (a) stop the fatal and
 * (b) encode the bare return EXPLICITLY as a statement-list Closure rather
 * than aliasing it to the value-return form.
 */
final class NormalizerBareReturnTest extends TestCase
{
    /**
     * @dataProvider modes
     */
    public function testBareReturnSwitchCaseDoesNotFatalInAnyMode(string $mode): void
    {
        $hash = $this->canonicalHash(
            '<?php function f($x) { switch ($x) { case 1: return; } handle($x); }',
            $mode,
        );
        $this->assertNotSame('', $hash, "bare-return case must normalize cleanly in $mode mode");
    }

    public function testBareReturnEncodesAsExplicitStatementClosure(): void
    {
        $block = $this->normalizeBlock(
            '<?php function f($x) { switch ($x) { case 1: return; } }',
            'default',
        );

        $body = $this->matchCaseBody($block, 0);
        $this->assertInstanceOf(Node\Expr\Closure::class, $body, 'bare return must take the statement-list branch');
        $this->assertCount(1, $body->stmts);
        $this->assertInstanceOf(Node\Stmt\Return_::class, $body->stmts[0]);
        $this->assertNull($body->stmts[0]->expr, 'the encoded return must keep its value-less shape');
    }

    public function testValueReturnCaseStaysLean(): void
    {
        $block = $this->normalizeBlock(
            '<?php function f($x) { switch ($x) { case 1: return 2; } }',
            'default',
        );

        $body = $this->matchCaseBody($block, 0);
        $this->assertNotInstanceOf(Node\Expr\Closure::class, $body, 'lone `return <expr>;` must keep the value form');
        $this->assertInstanceOf(Node::class, $body);
    }

    public function testBareReturnIsNotAliasedToReturnNull(): void
    {
        // A bare `return;` (void return) and `return null;` are different
        // statements; the guard must not fold one into the other's shape.
        $bare  = $this->canonicalHash('<?php function f($x) { switch ($x) { case 1: return; } }', 'strict');
        $nulls = $this->canonicalHash('<?php function f($x) { switch ($x) { case 1: return null; } }', 'strict');
        $this->assertNotSame($bare, $nulls, 'bare return must not alias to the null-literal value form');
    }

    public function testBareAndValueCasesCoexistAndDifferWithinOneSwitch(): void
    {
        $block = $this->normalizeBlock(
            '<?php function f($x) { switch ($x) { case 1: return; case 2: return 2; } }',
            'default',
        );

        $bareBody  = $this->matchCaseBody($block, 0);
        $valueBody = $this->matchCaseBody($block, 1);
        $this->assertInstanceOf(Node\Expr\Closure::class, $bareBody);
        $this->assertNotInstanceOf(Node\Expr\Closure::class, $valueBody, 'the two arms must not collapse to one encoding');
    }

    public function testDefaultCaseWithBareReturnIsGuardedToo(): void
    {
        $block = $this->normalizeBlock(
            '<?php function f($x) { switch ($x) { default: return; } }',
            'default',
        );

        $body = $this->matchCaseBody($block, 0);
        $this->assertInstanceOf(Node\Expr\Closure::class, $body);
        $this->assertInstanceOf(Node\Stmt\Return_::class, $body->stmts[0]);
        $this->assertNull($body->stmts[0]->expr);
    }

    public function testMultiStatementCaseEndingInBareReturnUnaffected(): void
    {
        // Pre-existing behavior: two statements already take the
        // statement-list branch; a trailing bare return must not change that.
        $block = $this->normalizeBlock(
            '<?php function f($x) { switch ($x) { case 1: log_a(); return; } }',
            'default',
        );

        $body = $this->matchCaseBody($block, 0);
        $this->assertInstanceOf(Node\Expr\Closure::class, $body);
        $this->assertCount(2, $body->stmts);
    }

    public function testCachedOriginalAstIsNeverMutated(): void
    {
        $parser    = new AstParser();
        $extractor = new BlockExtractor(minSize: 1);
        $code      = '<?php function f($x) { switch ($x) { case 1: return; } }';

        $blocks = $extractor->extract('test.php', $parser->parseCode($code));
        $this->assertNotEmpty($blocks);
        $block = $blocks[0];

        // Three successive passes over the SAME cached AST in different
        // modes: each must see the untouched original (deepClone law), so
        // the strict pass here must agree with a fresh-parse strict pass.
        (new Normalizer('default'))->normalize($block);
        (new Normalizer('aggressive'))->normalize($block);
        (new Normalizer('strict'))->normalize($block);
        $chained = $this->hashOf($block);

        $fresh = $extractor->extract('test.php', $parser->parseCode($code));
        (new Normalizer('strict'))->normalize($fresh[0]);
        $this->assertSame($this->hashOf($fresh[0]), $chained, 'repeated normalize() on one cached AST must stay deterministic');

        $this->assertNotEmpty(
            (new NodeFinder())->findInstanceOf($block->ast, Node\Stmt\Switch_::class),
            'the original AST must still hold its Switch_ after normalization',
        );
    }

    public function testBareReturnClonesStillCluster(): void
    {
        $a = $this->canonicalHash('<?php function f($x) { switch ($x) { case 5: return; } }', 'default');
        $b = $this->canonicalHash('<?php function g($y) { switch ($y) { case 9: return; } }', 'default');
        $this->assertSame($a, $b, 'parameter/case-literal clones with bare returns must keep clustering');
    }

    /**
     * @return list<array{0:string}>
     */
    public static function modes(): array
    {
        return [['strict'], ['default'], ['aggressive']];
    }

    private function canonicalHash(string $code, string $mode): string
    {
        return $this->hashOf($this->normalizeBlock($code, $mode));
    }

    private function hashOf(Block $block): string
    {
        $this->assertInstanceOf(Node::class, $block->canonical, 'normalize() must leave a canonical AST');
        return (new SubtreeHasher())->hash($block->canonical);
    }

    private function normalizeBlock(string $code, string $mode): Block
    {
        $parser    = new AstParser();
        $extractor = new BlockExtractor(minSize: 1);
        $blocks    = $extractor->extract('test.php', $parser->parseCode($code));
        $this->assertNotEmpty($blocks, 'fixture must produce at least one block');
        (new Normalizer($mode))->normalize($blocks[0]);
        return $blocks[0];
    }

    /**
     * Folded case bodies sit at even Arg indexes of the __MATCH__ call:
     * [0]=cond, then per case (cond, body) pairs starting at index 2.
     */
    private function matchCaseBody(Block $block, int $caseIndex): Node
    {
        $this->assertInstanceOf(Node::class, $block->canonical);
        $calls = (new NodeFinder())->findInstanceOf($block->canonical, Node\Expr\FuncCall::class);
        foreach ($calls as $call) {
            if ($call->name instanceof Node\Name && $call->name->toString() === '__MATCH__') {
                $arg = $call->args[2 + 2 * $caseIndex] ?? null;
                $this->assertInstanceOf(Node\Arg::class, $arg, "case #$caseIndex must exist on the folded __MATCH__ call");
                $this->assertInstanceOf(Node::class, $arg->value);
                return $arg->value;
            }
        }
        $this->fail('expected a __MATCH__ FuncCall in the canonical AST');
    }
}
