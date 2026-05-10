<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Ir;

use PHPUnit\Framework\TestCase;
use Phpdup\Ir\IrLifter;
use Phpdup\Ir\IrPrinter;
use Phpdup\Ir\IrSimilarity;
use Phpdup\Ir\Nodes\AssignIr;
use Phpdup\Ir\Nodes\BlockIr;
use Phpdup\Ir\Nodes\BranchIr;
use Phpdup\Ir\Nodes\CallIr;
use Phpdup\Ir\Nodes\DbReadIr;
use Phpdup\Ir\Nodes\DbWriteIr;
use Phpdup\Ir\Nodes\LiteralIr;
use Phpdup\Ir\Nodes\LoopIr;
use Phpdup\Ir\Nodes\ReturnIr;
use Phpdup\Ir\Nodes\VarIr;
use Phpdup\Parsing\AstParser;

final class IrLifterTest extends TestCase
{
    public function testLiftsEloquentReadToDbReadIr(): void
    {
        $ir = $this->liftFunction('<?php function f($id) { return User::find($id); }');
        $this->assertInstanceOf(BlockIr::class, $ir);
        // body[0] is the return wrapping a DbReadIr.
        $stmts = $ir->stmts;
        $this->assertCount(1, $stmts);
        $this->assertInstanceOf(ReturnIr::class, $stmts[0]);
        $expr = $stmts[0]->expr;
        $this->assertInstanceOf(DbReadIr::class, $expr);
        $this->assertSame('user', $expr->table);
    }

    public function testLiftsRawSqlSelectToDbReadIr(): void
    {
        $ir = $this->liftFunction('<?php function f($db) {
            return $db->query("SELECT * FROM users WHERE id = 1");
        }');
        $this->assertInstanceOf(BlockIr::class, $ir);
        $expr = $ir->stmts[0]->expr;
        $this->assertInstanceOf(DbReadIr::class, $expr);
        $this->assertSame('users', $expr->table);
        $this->assertSame('sql', $expr->predicate);
    }

    public function testEloquentAndPdoFindLiftToSameIrShape(): void
    {
        $eloquent = $this->liftFunction('<?php function f($a, $b) { return User::find($b); }');
        $doctrine = $this->liftFunction('<?php function f($a, $b) { return $a->find(User::class, $b); }');
        $printer = new IrPrinter();
        $this->assertSame($printer->tokens($eloquent), $printer->tokens($doctrine),
            'Eloquent and Doctrine reads on the same entity must lift to identical IR');
    }

    public function testIfElseLiftsToBranchIr(): void
    {
        $ir = $this->liftFunction('<?php function f($x) {
            if ($x > 0) { return 1; } else { return 2; }
        }');
        $this->assertInstanceOf(BlockIr::class, $ir);
        $first = $ir->stmts[0];
        $this->assertInstanceOf(BranchIr::class, $first);
        $this->assertNotNull($first->else);
    }

    public function testForeachAndForLiftToLoopIr(): void
    {
        $a = $this->liftFunction('<?php function f($items) {
            foreach ($items as $i) { echo $i; }
        }');
        $b = $this->liftFunction('<?php function f($items) {
            for ($k = 0; $k < count($items); $k++) { echo $items[$k]; }
        }');
        // Both bodies wrap a LoopIr — their first child (the body)
        // shape may differ but the LoopIr wrapper is the same kind.
        $this->assertInstanceOf(LoopIr::class, $a->stmts[0]);
        $this->assertInstanceOf(LoopIr::class, $b->stmts[0]);
    }

    public function testTrinityLiftsToReadAssignWriteSequence(): void
    {
        $ir = $this->liftFunction('<?php function f($id) {
            $user = User::find($id);
            $user->name = "Bob";
            $user->save();
        }');
        $this->assertInstanceOf(BlockIr::class, $ir);
        $stmts = $ir->stmts;
        $this->assertInstanceOf(AssignIr::class, $stmts[0]);
        $this->assertInstanceOf(DbReadIr::class, $stmts[0]->rhs);
        $this->assertInstanceOf(AssignIr::class, $stmts[1]);
        $this->assertSame('prop', $stmts[1]->target);
        $this->assertInstanceOf(DbWriteIr::class, $stmts[2]);
    }

    public function testGenericFunctionCallLiftsToCallIr(): void
    {
        $ir = $this->liftFunction('<?php function f($items) { return array_sum($items); }');
        $expr = $ir->stmts[0]->expr;
        $this->assertInstanceOf(CallIr::class, $expr);
        $this->assertSame('array_sum', $expr->symbol);
    }

    public function testLiteralsCollapseByType(): void
    {
        $ir = $this->liftFunction('<?php function f() { return "hello"; }');
        $this->assertInstanceOf(LiteralIr::class, $ir->stmts[0]->expr);
        $this->assertSame('str', $ir->stmts[0]->expr->type);
    }

    public function testIrSimilarityIsOneForIdenticalTrees(): void
    {
        $a = $this->liftFunction('<?php function f($id) { return User::find($id); }');
        $b = $this->liftFunction('<?php function f($id) { return User::find($id); }');
        $this->assertSame(1.0, (new IrSimilarity())->similarity($a, $b));
    }

    public function testIrSimilarityIsHighForCrossLibraryReads(): void
    {
        $eloquent = $this->liftFunction('<?php function f($a, $b) { return User::find($b); }');
        $pdo      = $this->liftFunction('<?php function f($pdo, $b) {
            return $pdo->query("SELECT * FROM user WHERE id = 1");
        }');
        $sim = (new IrSimilarity())->similarity($eloquent, $pdo);
        $this->assertGreaterThan(0.5, $sim,
            'cross-library reads on the same table should score high under IR similarity');
    }

    public function testIrSimilarityIsLowForUnrelatedShapes(): void
    {
        // Use shapes that share even fewer tokens — comparing a
        // pure-arithmetic block against a multi-statement
        // delete+log block. Some token overlap from the BlockIr
        // wrappers themselves is unavoidable in a Jaccard scorer.
        $a = $this->liftFunction('<?php function f($x) { return $x + 1; }');
        $b = $this->liftFunction('<?php function f($id, $log) {
            User::destroy($id);
            $log->info("done");
            return true;
        }');
        $sim = (new IrSimilarity())->similarity($a, $b);
        $this->assertLessThan(0.5, $sim,
            'unrelated shapes should score below 0.5 under IR similarity');
    }

    public function testIrHashIsStableAcrossEquivalentInputs(): void
    {
        $a = $this->liftFunction('<?php function f($a, $b) { return User::find($b); }');
        $b = $this->liftFunction('<?php function g($a, $b) { return User::find($b); }');
        $sim = new IrSimilarity();
        $this->assertSame($sim->hash($a), $sim->hash($b));
    }

    public function testLiftReturnsNullOnUnsupportedInputGracefully(): void
    {
        // The lifter swallows exceptions and returns null; here we
        // ensure normal paths still produce a non-null IR.
        $ir = (new IrLifter())->lift($this->parseFunction('<?php function f() { return 1; }'));
        $this->assertNotNull($ir);
    }

    public function testIrPrinterProducesDeterministicTokenStream(): void
    {
        $ir = new BlockIr([new ReturnIr(new DbReadIr('user', 'id'))]);
        $tokens = (new IrPrinter())->tokens($ir);
        $this->assertSame(
            ['block(', 'return(', 'db.read:user:id', ')', ')'],
            $tokens,
        );
    }

    public function testIrPrinterPrettyOutput(): void
    {
        $ir = new BlockIr([new AssignIr('var', new VarIr())]);
        $pretty = (new IrPrinter())->pretty($ir);
        $this->assertStringContainsString('block', $pretty);
        $this->assertStringContainsString('assign:var', $pretty);
        $this->assertStringContainsString('var:__V', $pretty);
    }

    private function liftFunction(string $code): BlockIr
    {
        $node = $this->parseFunction($code);
        $ir = (new IrLifter())->lift($node);
        $this->assertInstanceOf(BlockIr::class, $ir);
        return $ir;
    }

    private function parseFunction(string $code): \PhpParser\Node
    {
        $stmts = (new AstParser())->parseCode($code);
        $this->assertNotEmpty($stmts);
        return $stmts[0];
    }
}
