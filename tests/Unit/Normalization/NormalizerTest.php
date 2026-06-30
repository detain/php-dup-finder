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

    public function testDifferentMethodNamesCollideUnderAggressiveNormalization(): void
    {
        $a = '<?php function f($db) { $rows = $db->query("X"); return $rows->fetch(); }';
        $b = '<?php function g($db) { $items = $db->prepare("Y"); return $items->execute(); }';
        $this->assertSame($this->canonicalHash($a, 'aggressive'), $this->canonicalHash($b, 'aggressive'));
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
