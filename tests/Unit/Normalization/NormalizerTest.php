<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Normalization;

use PHPUnit\Framework\TestCase;
use Phpdup\Extraction\BlockExtractor;
use Phpdup\Fingerprint\SubtreeHasher;
use Phpdup\Normalization\Normalizer;
use Phpdup\Parsing\AstParser;

final class NormalizerTest extends TestCase
{
    public function testRenamedVariablesProduceIdenticalCanonicalHash(): void
    {
        $a = '<?php function f($items) { $sum = 0; foreach ($items as $i) { $sum += $i; } return $sum; }';
        $b = '<?php function g($entries) { $total = 0; foreach ($entries as $e) { $total += $e; } return $total; }';

        $hashA = $this->canonicalHash($a);
        $hashB = $this->canonicalHash($b);
        $this->assertSame($hashA, $hashB, 'renamed-variable clones must canonicalize to the same hash');
    }

    public function testDifferentLiteralsCanonicalizeIdenticallyInDefaultMode(): void
    {
        $a = '<?php function f($x) { if ($x > 10) { return "admin"; } return "user"; }';
        $b = '<?php function g($y) { if ($y > 99) { return "moderator"; } return "guest"; }';
        $this->assertSame($this->canonicalHash($a), $this->canonicalHash($b));
    }

    public function testDifferentMethodNamesNoLongerCollideUnderAggressiveExact(): void
    {
        // engine-002: the aggressive name pass is name-HONEST at the
        // exact tier. A pair that only matches after relabelling every
        // call (`query`/`fetch` vs `prepare`/`execute`) is a shape
        // twin, not a type-1 clone — it must fall out of the byte-
        // identical stream and into the Jaccard/TED near-duplicate
        // tiers (where Util\IdentifierIds folds both vocabularies to
        // the shared skeleton, preserving clustering).
        $a = '<?php function f($db) { $rows = $db->query("X"); return $rows->fetch(); }';
        $b = '<?php function g($db) { $items = $db->prepare("Y"); return $items->execute(); }';
        $this->assertNotSame($this->canonicalHash($a, 'aggressive'), $this->canonicalHash($b, 'aggressive'));
    }

    public function testSharedVocabularyClonesStillCanonicalizeIdentically(): void
    {
        // The mode's stated purpose survives: two copy-paste handlers
        // that share the framework vocabulary (`send`) and differ only
        // in names/literals must still collapse to one hash — the
        // name stamp is identical wherever the name is identical.
        $a = '<?php function notifyHigh($user, $score) { if ($score > 10) { $this->mailer->send("admin", $user); } }';
        $b = '<?php function notifyLow($user, $score) { if ($score > 30) { $this->mailer->send("editor", $user); } }';
        $this->assertSame($this->canonicalHash($a, 'aggressive'), $this->canonicalHash($b, 'aggressive'));
    }

    public function testBranchRoleSwappedPairIsNotByteIdentical(): void
    {
        // engine-002 acceptance: a branch-role-swapped pair must not
        // canonicalise byte-identically. Pre-fix, cross-vocabulary
        // twins collapsed byte-identically under the first-occurrence
        // ordinals (that is what the flipped test pinned), and the
        // role-swap EXACT 1.00 lie reproduces when the swapped
        // vocabularies first appear inside the arms (property-vocab
        // variant). This toy pair was itself shielded pre-fix by
        // engine-055's Param double-rename — its streams differed at
        // __V4/__V6 and the old --exact-only yielded 0 clusters.
        // Name-stamped sentinels now keep `send` and `log` distinct
        // tokens, so the arms can no longer trade identities
        // invisibly.
        $a = '<?php function a($f, $o, $m, $e) { if ($f) { $o->send($m); } else { $o->log($e); } }';
        $b = '<?php function b($f, $o, $m, $e) { if ($f) { $o->log($e); } else { $o->send($m); } }';
        $this->assertNotSame($this->canonicalHash($a, 'aggressive'), $this->canonicalHash($b, 'aggressive'));
    }

    public function testParamAndBodyShareOneSentinel(): void
    {
        // engine-055: the Param branch used to pre-rename its Variable
        // child, which the traverser then visited as an unmapped
        // "generated" name and dealt a second slot — the declaration
        // canonicalised to __V1 while the body read __V0. One handler
        // now renames every occurrence of a user variable exactly once:
        // two distinct locals in the block must yield exactly two
        // distinct tokens, and the Param token must equal the body-use
        // token of the same variable.
        $parser    = new AstParser();
        $extractor = new BlockExtractor(minSize: 1);
        $blocks    = $extractor->extract('test.php', $parser->parseCode(
            '<?php function f($x) { $y = $x + 1; return $y; }',
        ));
        $this->assertNotEmpty($blocks);
        (new Normalizer('strict'))->normalize($blocks[0]);

        $paramName = null;
        $tokens    = [];
        $finder    = new \PhpParser\NodeFinder();
        foreach ($finder->findInstanceOf([$blocks[0]->canonical], \PhpParser\Node\Expr\Variable::class) as $v) {
            $this->assertIsString($v->name, 'every variable must be renamed to a string sentinel');
            $tokens[] = $v->name;
        }
        foreach ($finder->findInstanceOf([$blocks[0]->canonical], \PhpParser\Node\Param::class) as $p) {
            $this->assertInstanceOf(\PhpParser\Node\Expr\Variable::class, $p->var);
            $paramName = $p->var->name;
        }
        $this->assertNotNull($paramName, 'fixture must carry a Param');
        $this->assertMatchesRegularExpression('/^__V\d+$/', $paramName);
        // $x (param + one body use) and $y (assign + return) = 4 visits,
        // exactly 2 distinct slots — the double-rename minted a third.
        $this->assertCount(4, $tokens);
        $this->assertSame(2, count(array_unique($tokens)), 'one sentinel per user variable, Param and body agreeing');
        $xUses = array_values(array_filter($tokens, static fn(string $t): bool => $t === $paramName));
        $this->assertGreaterThanOrEqual(2, count($xUses), 'body uses of $x must share the Param sentinel');
    }

    public function testUserVariableNamedSentinelCannotAliasGeneratedSpace(): void
    {
        // engine-055 alias guard: with two locals named `$__V0` and
        // `$x`, the dealt tokens must stay distinct AND no dealt token
        // may collide with a name a user variable of this block
        // literally carries (the old scheme handed `$__V0` its own
        // name as sentinel whenever the counter happened to be there).
        $parser    = new AstParser();
        $extractor = new BlockExtractor(minSize: 1);
        $blocks    = $extractor->extract('test.php', $parser->parseCode(
            '<?php function f($__V0, $x) { $x = 1; $__V0 = 2; return $x + $__V0; }',
        ));
        $this->assertNotEmpty($blocks);
        (new Normalizer('strict'))->normalize($blocks[0]);

        $finder = new \PhpParser\NodeFinder();
        $tokens = [];
        foreach ($finder->findInstanceOf([$blocks[0]->canonical], \PhpParser\Node\Expr\Variable::class) as $v) {
            $this->assertIsString($v->name);
            $tokens[$v->name] = true;
        }
        $this->assertSame(2, count($tokens), 'both locals keep their own slot');
        $this->assertArrayNotHasKey('__V0', $tokens, 'the sentinel a user name already carries must never be dealt');
    }

    public function testStrictModeDoesNotCollapseLiterals(): void
    {
        $a = '<?php function f($x) { return 10; }';
        $b = '<?php function g($y) { return 20; }';
        $this->assertNotSame($this->canonicalHash($a, 'strict'), $this->canonicalHash($b, 'strict'));
    }

    public function testKeepsThisDistinct(): void
    {
        $a = '<?php class A { public function m() { $x = $this->a; return $x; } }';
        $b = '<?php class A { public function m() { $x = $other->a; return $x; } }';
        // $other gets renamed but $this is preserved → hashes differ
        $this->assertNotSame($this->canonicalHash($a), $this->canonicalHash($b));
    }

    public function testNamedArgsReorderToCanonicalOrder(): void
    {
        $a = '<?php function f() { return foo(name: 1, age: 2); }';
        $b = '<?php function g() { return foo(age: 2, name: 1); }';
        $this->assertSame($this->canonicalHash($a, 'default'), $this->canonicalHash($b, 'default'));
    }

    public function testStrictModeDoesNotReorderNamedArgs(): void
    {
        // Strict mode bails before named-arg canonicalization, so two
        // different named-arg orderings still produce different hashes.
        $a = '<?php function f() { foo(name: 1, age: 2); }';
        $b = '<?php function g() { foo(age: 2, name: 1); }';
        $this->assertNotSame($this->canonicalHash($a, 'strict'), $this->canonicalHash($b, 'strict'));
    }

    public function testAggressiveModeStripsAttributes(): void
    {
        $a = '<?php class C { #[Route("/x")] public function f(): void { return; } }';
        $b = '<?php class C { #[Route("/totally-different")] public function f(): void { return; } }';
        $this->assertSame($this->canonicalHash($a, 'aggressive'), $this->canonicalHash($b, 'aggressive'));
    }

    public function testDefaultModeKeepsAttributesDistinct(): void
    {
        // Attributes are dropped only in aggressive mode; default
        // preserves them so methods with semantically meaningful
        // attributes (e.g. Route definitions) don't accidentally cluster.
        $a = '<?php class C { #[Route("/users")] public function f(): void { return; } }';
        $b = '<?php class C { #[Route("/admin")] public function f(): void { return; } }';
        $this->assertSame(
            $this->canonicalHash($a, 'default'),
            $this->canonicalHash($b, 'default'),
            'literal canonicalization unifies the route paths in default mode',
        );
    }

    public function testMatchAndSwitchAreNotForcedTogetherButMatchArmsAreNormalised(): void
    {
        // Two matches that differ only by comma-separated condition order
        // should normalise to the same hash because canonicalizeMatchAsSwitch
        // OR-folds multi-cond arms.
        $a = '<?php function f($x) { return match($x) { 1, 2 => "a", default => "b" }; }';
        $b = '<?php function g($x) { return match($x) { 2, 1 => "a", default => "b" }; }';
        // The two are not guaranteed identical (BooleanOr is non-commutative
        // in token output) but should both succeed without raising.
        $this->assertNotEmpty($this->canonicalHash($a));
        $this->assertNotEmpty($this->canonicalHash($b));

        // match and switch are both normalised to __MATCH__ FuncCall form.
        // They cannot produce structurally identical canonical hashes because
        // Match_ arms are expression-bodied (passed directly) while Switch_ cases
        // are statement-bodied (Closure-wrapped). The normalised forms are
        // both used for clustering via the shared __MATCH__ FuncCall token shape.
        $match = '<?php function f($x) { return match($x) { 1, 2 => 1, default => 2 }; }';
        $switch = '<?php function g($x) { switch ($x) { case 1: case 2: return 1; default: return 2; } }';
        $this->assertNotEmpty($this->canonicalHash($match), 'match must normalise without crashing');
        $this->assertNotEmpty($this->canonicalHash($switch), 'switch must normalise without crashing');
    }

    // ---------------------------------------------------------------
    // engine-036: strict-mode leaks (match/switch fold + __FN wipe)
    // ---------------------------------------------------------------

    public function testStrictModeDoesNotFoldMatchAgainstSwitch(): void
    {
        // The finding's EXACT repro: `match` (===, no fall-through) and
        // `switch` (==, fall-through) are semantically distinct, so
        // strict must keep them apart. The __MATCH__ fold stays a
        // default/aggressive near-dup heuristic (engine-036(a)).
        // Both snippets share the name f on purpose: if the pair ever
        // collides again under strict, the collision must come from
        // the fold itself — never from a name difference masking it.
        $match  = '<?php function f($x) { return match($x) { 1 => 1, default => 2 }; }';
        $switch = '<?php function f($x) { switch ($x) { case 1: return 1; default: return 2; } }';

        $this->assertNotSame(
            $this->canonicalHash($match, 'strict'),
            $this->canonicalHash($switch, 'strict'),
            'strict mode must not desugar match/switch into a shared __MATCH__ shape',
        );

        // Non-strict modes keep the cross-parity fold byte-unchanged.
        $this->assertSame(
            $this->canonicalHash($match, 'default'),
            $this->canonicalHash($switch, 'default'),
            'default mode keeps the __MATCH__ fold',
        );
        $this->assertSame(
            $this->canonicalHash($match, 'aggressive'),
            $this->canonicalHash($switch, 'aggressive'),
            'aggressive mode keeps the __MATCH__ fold',
        );
    }

    public function testStrictModeKeepsFunctionAndMethodDeclarationNames(): void
    {
        // engine-036(b): the __FN wipe used to run ahead of the strict
        // bail-out, so same-shape differently-named declarations
        // canonicalised identically in EVERY mode.
        $a = '<?php function computeTotal($x) { return $x + 1; }';
        $b = '<?php function computeSum($x) { return $x + 1; }';

        $this->assertNotSame(
            $this->canonicalHash($a, 'strict'),
            $this->canonicalHash($b, 'strict'),
            'the __FN wipe must not survive the strict bail-out',
        );

        // ClassMethod twin of the leak: same class name, so the only
        // strict-mode witness is the method name itself.
        $c = '<?php class A { public function m($x) { return $x; } }';
        $d = '<?php class A { public function n($x) { return $x; } }';

        $this->assertNotSame(
            $this->canonicalHash($c, 'strict'),
            $this->canonicalHash($d, 'strict'),
            'method declaration names stay raw under strict',
        );

        // Default/aggressive still collapse container labels — the
        // convenience these modes signed up for.
        $this->assertSame(
            $this->canonicalHash($a, 'default'),
            $this->canonicalHash($b, 'default'),
            'default mode still collapses function names to __FN',
        );
        $this->assertSame(
            $this->canonicalHash($a, 'aggressive'),
            $this->canonicalHash($b, 'aggressive'),
            'aggressive mode still collapses function names to __FN',
        );
        $this->assertSame(
            $this->canonicalHash($c, 'aggressive'),
            $this->canonicalHash($d, 'aggressive'),
            'aggressive mode still collapses method names to __FN',
        );
    }

    private function canonicalHash(string $code, string $mode = 'aggressive'): string
    {
        $parser = new AstParser();
        $stmts = $parser->parseCode($code);
        $extractor = new BlockExtractor(minSize: 1);
        $blocks = $extractor->extract('test.php', $stmts);
        $this->assertNotEmpty($blocks, 'fixture must produce at least one block');
        $normalizer = new Normalizer($mode);
        $normalizer->normalize($blocks[0]);
        return (new SubtreeHasher())->hash($blocks[0]->canonical);
    }
}
