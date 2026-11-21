<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Ir;

use PhpParser\Node;
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

    public function testThreeArmIfKeepsElseifAndTrailingElseArms(): void
    {
        // engine-024 direct repro, pinned standalone (fix-round-1
        // LOW-2): the finding's literal three-arm shape lifted on its
        // own — not only as the if-chain counterpart inside the
        // switch-parity test. The elseif survives as a nested
        // BranchIr and BOTH of its arms carry bodies: `q()` on the
        // then side, `r()` on the else terminal. Under the old
        // else-XOR-elseif fold one of these two arms is discarded.
        $stmts = (new AstParser())->parseCode(
            '<?php if ($a) { p(); } elseif ($b) { q(); } else { r(); }'
        );
        $this->assertNotEmpty($stmts);
        $ir = (new IrLifter())->lift($stmts[0]);
        $this->assertInstanceOf(BranchIr::class, $ir);
        $this->assertInstanceOf(BranchIr::class, $ir->else);
        $this->assertInstanceOf(BlockIr::class, $ir->else->then);
        $this->assertNotEmpty($ir->else->then->stmts,
            'the elseif body (q()) must survive the three-arm fold');
        $this->assertInstanceOf(BlockIr::class, $ir->else->else);
        $this->assertNotEmpty($ir->else->else->stmts,
            'the trailing else body (r()) must survive the three-arm fold');
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
        // Refusal semantics (engine-041): only \Error-family lift
        // failures launder to null (pinned by
        // testLiftRefusesEngineErrorsAndCountsThem); \Exception-family
        // programmer bugs propagate (pinned by
        // testInjectedExceptionIsNotSwallowed). Normal paths lift.
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

    public function testAssignOpConcatLiftsToSameIrAsBinaryConcat(): void
    {
        // $x .= $y and $x = $x . $y should produce identical IR
        $dotAssign = $this->liftFunction('<?php function f($x, $y) { $x .= $y; }');
        $dotBinary = $this->liftFunction('<?php function f($x, $y) { $x = $x . $y; }');
        $printer = new IrPrinter();
        $this->assertSame(
            $printer->tokens($dotAssign),
            $printer->tokens($dotBinary),
            '$x .= $y must lift to identical IR as $x = $x . $y',
        );
    }

    public function testPostIncLiftsToSameIrAsPlusEquals(): void
    {
        // $i++ and $i += 1 should produce identical IR
        $postInc = $this->liftFunction('<?php function f($i) { $i++; }');
        $plusEq = $this->liftFunction('<?php function f($i) { $i += 1; }');
        $printer = new IrPrinter();
        $this->assertSame(
            $printer->tokens($postInc),
            $printer->tokens($plusEq),
            '$i++ must lift to identical IR as $i += 1',
        );
    }

    public function testPreDecLiftsToSameIrAsMinusEquals(): void
    {
        $preDec = $this->liftFunction('<?php function f($i) { --$i; }');
        $minusEq = $this->liftFunction('<?php function f($i) { $i -= 1; }');
        $printer = new IrPrinter();
        $this->assertSame(
            $printer->tokens($preDec),
            $printer->tokens($minusEq),
            '--$i must lift to identical IR as $i -= 1',
        );
    }

    public function testAssignOpPlusLiftsToAssignIr(): void
    {
        $ir = $this->liftFunction('<?php function f($x, $y) { $x += $y; }');
        $this->assertInstanceOf(BlockIr::class, $ir);
        $stmt = $ir->stmts[0];
        $this->assertInstanceOf(AssignIr::class, $stmt);
        $this->assertSame('var', $stmt->target);
    }

    public function testAssignOpPropertyLiftsToPropAssignIr(): void
    {
        $ir = $this->liftFunction('<?php function f($obj) { $obj->count += 1; }');
        $this->assertInstanceOf(BlockIr::class, $ir);
        $stmt = $ir->stmts[0];
        $this->assertInstanceOf(AssignIr::class, $stmt);
        $this->assertSame('prop', $stmt->target);
    }

    public function testFirstClassAndPartialPlaceholdersLiftAsAbsentArgs(): void
    {
        // php-parser 5.9 hands the lifter arg lists that may contain
        // ArgPlaceholder nodes ("?" / "name: ?" partial application) and
        // VariadicPlaceholder ("...") next to the real Args. Both are
        // syntax holes with no value to lift, so the IR must come out
        // exactly as if the holes were never there.
        $withHoles = $this->liftFunction('<?php function f($x) { foo(?, bar: ?, "lit", $x); }');
        $withoutHoles = $this->liftFunction('<?php function f($x) { foo("lit", $x); }');
        $printer = new IrPrinter();
        $this->assertSame($printer->tokens($withoutHoles), $printer->tokens($withHoles));
        $call = $withHoles->stmts[0];
        $this->assertInstanceOf(CallIr::class, $call);
        $this->assertSame('foo', $call->symbol);
        $this->assertCount(2, $call->args);
    }

    public function testSwitchLiftsIdenticallyToItsIfChainForm(): void
    {
        // engine-025 acceptance: a switch and its hand-written
        // if-chain counterpart — encoding the SAME PHP fallthrough
        // semantics — must emit identical IR token streams. Here
        // `case 2` sits after `default:`: it is reached both by an
        // exact match and by falling through from default, which the
        // chain spells as `else { r(); q(); }`.
        $printer = new IrPrinter();
        $switch = $this->liftFunction('<?php function f($x) {
            switch ($x) {
                case 1: p(); break;
                default: r();
                case 2: q(); break;
            }
        }');
        $chain = $this->liftFunction('<?php function f($x) {
            if ($x == 1) { p(); } elseif ($x == 2) { q(); } else { r(); q(); }
        }');
        $this->assertSame($printer->tokens($chain), $printer->tokens($switch),
            'switch and its if-chain counterpart must emit identical IR token streams');
        $tokens = implode(' ', $printer->tokens($switch));
        $this->assertStringContainsString('call:equal(', $tokens,
            'switch arms must lift to the same `equal` symbol a genuine `==` lifts to');
        $this->assertStringNotContainsString('==', $tokens,
            'the legacy `==` split-brain symbol must be gone');
        $this->assertStringNotContainsString('call:break_', $tokens,
            'trailing bare breaks are desugaring residue and must not survive');
    }

    public function testSwitchDefaultBreakStopsFallthroughIntoLaterCase(): void
    {
        // `default: ... break;` ends at the break: the post-default
        // `case 2` is only reached by an exact match, never by
        // falling through from default — while `case 1` (no break)
        // does fall into default's body.
        $printer = new IrPrinter();
        $switch = $this->liftFunction('<?php function f($x) {
            switch ($x) {
                case 1: p();
                default: r(); break;
                case 2: q(); break;
            }
        }');
        $chain = $this->liftFunction('<?php function f($x) {
            if ($x == 1) { p(); r(); } elseif ($x == 2) { q(); } else { r(); }
        }');
        $this->assertSame($printer->tokens($chain), $printer->tokens($switch));
    }

    public function testSwitchWithoutDefaultEndsInNullElse(): void
    {
        // No default means no else arm — no empty trailing BlockIr
        // and no trailing break residue; parity with a bare if.
        $printer = new IrPrinter();
        $switch = $this->liftFunction('<?php function f($x) {
            switch ($x) { case 1: p(); break; }
        }');
        $chain = $this->liftFunction('<?php function f($x) { if ($x == 1) { p(); } }');
        $this->assertSame($printer->tokens($chain), $printer->tokens($switch));
        $outer = $switch->stmts[0];
        $this->assertInstanceOf(BranchIr::class, $outer);
        $this->assertNull($outer->else);
    }

    public function testNonTrailingBreakKeepsItsTokenAndFoldsOnward(): void
    {
        // Elision policy (documented on foldCaseStmts): only a
        // trailing bare break terminates a case. A break that is not
        // the final statement — here guarded inside an if — keeps its
        // `call:break_` token and the arm still folds with the next
        // physical case.
        $printer = new IrPrinter();
        $switch = $this->liftFunction('<?php function f($x, $y) {
            switch ($x) {
                case 1: if ($y) { break; } p();
                case 2: q(); break;
            }
        }');
        $chain = $this->liftFunction('<?php function f($x, $y) {
            if ($x == 1) { if ($y) { break; } p(); q(); } elseif ($x == 2) { q(); }
        }');
        $this->assertSame($printer->tokens($chain), $printer->tokens($switch));
        $tokens = implode(' ', $printer->tokens($switch));
        $this->assertStringContainsString('call:break_', $tokens);
    }

    public function testLiftRefusesEngineErrorsAndCountsThem(): void
    {
        // engine-041: \Error-family failures stay a graceful, OBSERVABLE
        // refusal rather than silent null laundering.
        $lifter = new IrLifter();
        $node = new Node\Stmt\Expression(new Node\Expr\FuncCall(new IrLiftErrorName('probe')));
        $this->assertNull($lifter->lift($node));
        $this->assertSame(1, $lifter->liftRefusals());
        $this->assertSame('ir-lift-error-probe', $lifter->lastLiftError());
        $this->assertNotNull($lifter->lift($this->parseFunction('<?php function f() { return 1; }')));
        $this->assertSame(1, $lifter->liftRefusals(),
            'successful lifts neither reset nor grow the refusal counter');
    }

    public function testInjectedExceptionIsNotSwallowed(): void
    {
        // engine-041: a programmer bug in the \Exception family must
        // propagate loudly out of lift() instead of masquerading as
        // an unsupported-shape fallback.
        $lifter = new IrLifter();
        $node = new Node\Stmt\Expression(new Node\Expr\FuncCall(new IrLiftExceptionName('probe')));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ir-lift-bug-probe');
        $lifter->lift($node);
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

/**
 * Probe node: toString() fails the way an engine-level problem does.
 * IrLifter::lift() must launder this into a counted null refusal.
 */
final class IrLiftErrorName extends Node\Name
{
    public function toString(): string
    {
        throw new \Error('ir-lift-error-probe');
    }
}

/**
 * Probe node: toString() throws a programmer-bug \Exception — the
 * lifter must let it propagate (engine-041).
 */
final class IrLiftExceptionName extends Node\Name
{
    public function toString(): string
    {
        throw new \RuntimeException('ir-lift-bug-probe');
    }
}
