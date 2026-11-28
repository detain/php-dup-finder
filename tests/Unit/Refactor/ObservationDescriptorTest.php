<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Refactor;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PHPUnit\Framework\TestCase;
use Phpdup\Parsing\AstParser;
use Phpdup\Refactor\ObservationDescriptor;

/**
 * Item 4.1 substrate: the scalar observation descriptor. Pins the
 * wire-safety shape (every field a plain scalar), the guard-recognition
 * fields (type/containsExit), callee names, the cap, determinism, and
 * the verified-absence marker.
 */
final class ObservationDescriptorTest extends TestCase
{
    /** @param list<string> $snippets */
    private static function firstMethodAst(array $snippets): Node
    {
        $parser = new AstParser();
        $code   = "<?php\nclass C {\n" . implode("\n", $snippets) . "\n}";
        $stmts  = $parser->parseCode($code);
        self::assertNotNull($stmts);
        $method = (new NodeFinder())->findFirst($stmts, fn(Node $n) => $n instanceof Node\Stmt\ClassMethod);
        self::assertInstanceOf(Node\Stmt\ClassMethod::class, $method);
        return $method;
    }

    public function testGuardIfDescriptorRecognizesExitAndCallees(): void
    {
        $method = self::firstMethodAst([
            'public function a($id) { if (!is_numeric($id)) { json_error("bad"); return; } do_work($id); }',
        ]);
        $if = (new NodeFinder())->findFirstInstanceOf($method->stmts, Node\Stmt\If_::class);
        self::assertInstanceOf(Node\Stmt\If_::class, $if);

        $d = ObservationDescriptor::build($if);
        self::assertSame('Stmt_If_', $d['type']);
        self::assertTrue($d['containsExit'], 'a guard with `return` must carry the exit witness');
        self::assertContains('is_numeric', $d['callNames']);
        self::assertContains('json_error', $d['callNames']);
        self::assertNull($d['scalar'], 'composite nodes have no leaf scalar');
    }

    public function testEveryDescriptorFieldIsWireSafe(): void
    {
        $method = self::firstMethodAst([
            'public function a($id) { if ($id === 1) { throw new \\LogicException("x"); } $this->log->info("hi"); }',
        ]);
        $d = ObservationDescriptor::build($method);

        // The socketpair law (improve_logic_info §4.1): serialize() of the
        // descriptor must travel as plain scalars — no objects anywhere.
        $round = unserialize(serialize($d));
        self::assertSame($d, $round);
        foreach ($d as $value) {
            if (is_array($value)) {
                foreach ($value as $inner) {
                    self::assertIsString($inner);
                }
                continue;
            }
            self::assertTrue($value === null || is_bool($value) || is_string($value));
        }
        self::assertTrue($d['containsExit'], 'throw must count as an exit path');
    }

    public function testExitAndDieFunctionFormsCountAsExits(): void
    {
        foreach (['exit;', 'die;', 'exit(1);', 'die("x");'] as $i => $statement) {
            $method = self::firstMethodAst([
                "public function m{$i}() { if (\$ok) { {$statement} } }",
            ]);
            $if = (new NodeFinder())->findFirstInstanceOf($method->stmts, Node\Stmt\If_::class);
            self::assertInstanceOf(Node\Stmt\If_::class, $if);
            self::assertTrue(
                ObservationDescriptor::build($if)['containsExit'],
                "exit form `{$statement}` must set containsExit"
            );
        }
    }

    public function testPlainStatementCarriesNoExitWitness(): void
    {
        $method = self::firstMethodAst([
            'public function a($id) { if ($x) { save($id); } }',
        ]);
        $if = (new NodeFinder())->findFirstInstanceOf($method->stmts, Node\Stmt\If_::class);
        self::assertInstanceOf(Node\Stmt\If_::class, $if);
        self::assertFalse(ObservationDescriptor::build($if)['containsExit']);
    }

    public function testCallNamesAreCappedAndFirstSeenOrdered(): void
    {
        $calls = [];
        for ($i = 1; $i <= 12; $i++) {
            $calls[] = "f{$i}(\$x);";
        }
        $method = self::firstMethodAst(['public function a($x) { ' . implode(' ', $calls) . ' }']);

        $d = ObservationDescriptor::build($method);
        self::assertCount(ObservationDescriptor::MAX_CALL_NAMES, $d['callNames']);
        self::assertSame('f1', $d['callNames'][0], 'traversal order is first-seen, not sorted');

        // Duplicates never consume cap budget.
        $dupes = self::firstMethodAst([
            'public function a($x) { ' . str_repeat('same($x); ', 12) . ' other($x); }',
        ]);
        self::assertSame(['same', 'other'], ObservationDescriptor::build($dupes)['callNames']);
    }

    public function testLeafScalarChannelsMatchTheSerializer(): void
    {
        $method = self::firstMethodAst(['public function a() { $x = "hello"; }']);
        $assign = (new NodeFinder())->findFirstInstanceOf($method->stmts, Node\Expr\Assign::class);
        self::assertInstanceOf(Node\Expr\Assign::class, $assign);
        $string = $assign->expr;

        $d = ObservationDescriptor::build($string);
        self::assertSame('Scalar_String_', $d['type']);
        self::assertSame('s:hello', $d['scalar']);
    }

    public function testEqualContentYieldsEqualDescriptors(): void
    {
        $one = self::firstMethodAst([
            'public function a($id) { if (!is_numeric($id)) { json_error("bad"); return; } go($id); }',
        ]);
        $two = self::firstMethodAst([
            'public function zzz($id) { if (!is_numeric($id)) { json_error("bad"); return; } go($id); }',
        ]);
        $ifOne = (new NodeFinder())->findFirstInstanceOf($one->stmts, Node\Stmt\If_::class);
        $ifTwo = (new NodeFinder())->findFirstInstanceOf($two->stmts, Node\Stmt\If_::class);
        self::assertInstanceOf($ifOne::class, $ifTwo);

        self::assertSame(
            ObservationDescriptor::build($ifOne),
            ObservationDescriptor::build($ifTwo),
            'determinism: the sidecar must be content-stable across declarations'
        );
    }

    public function testAbsentMarkerShape(): void
    {
        $absent = ObservationDescriptor::absent();
        self::assertSame(ObservationDescriptor::ABSENT_TYPE, $absent['type']);
        self::assertSame([], $absent['callNames']);
        self::assertFalse($absent['containsExit']);
        self::assertNull($absent['scalar']);
        self::assertSame(
            ['type', 'callNames', 'containsExit', 'scalar'],
            array_keys($absent),
            'the absent row must carry the identical field roster as a real observation'
        );
    }
}
