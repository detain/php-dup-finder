<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Census;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use Phpdup\Census\SubtreeFold;
use Phpdup\Util\AstSerializer;

/**
 * Item 3.1 substrate — the dedicated subtree fold (§5d): two keys per
 * subtree, subtree-local variable renumbering, and the deliberate
 * leaf-blanking policy that closes the [f09] gap (Normalizer's
 * canonicalizeLiterals never touched bareword constants; the ABSTRACT
 * key here does).
 */
final class SubtreeFoldTest extends TestCase
{
    private Parser $parser;

    protected function setUp(): void
    {
        $this->parser = (new ParserFactory())->createForHostVersion();
    }

    /**
     * @param string $code
     * @param class-string<Node> $class
     */
    private function first(string $code, string $class): Node
    {
        $ast  = $this->parser->parse($code);
        self::assertIsArray($ast);
        $node = (new NodeFinder())->findFirstInstanceOf($ast, $class);
        self::assertNotNull($node, 'fixture must contain a ' . $class);
        return $node;
    }

    public function testIdenticalSubtreesShareBothKeysAcrossRenamedVariables(): void
    {
        $k1 = SubtreeFold::hashPair($this->first(<<<'PHP'
            <?php
            function a() { return $x instanceof Foo || $y instanceof Foo; }
            PHP, Expr\BinaryOp\BooleanOr::class));
        $k2 = SubtreeFold::hashPair($this->first(<<<'PHP'
            <?php
            function b() { return $p instanceof Foo || $q instanceof Foo; }
            PHP, Expr\BinaryOp\BooleanOr::class));

        self::assertSame($k1['exact'], $k2['exact'], 'local names are not identity — slots are');
        self::assertSame($k1['abstract'], $k2['abstract']);
    }

    public function testLiteralDifferenceSplitsExactButNotAbstract(): void
    {
        $k1 = SubtreeFold::hashPair($this->first(<<<'PHP'
            <?php
            function a() { return bin2hex(random_bytes(16)); }
            PHP, Expr\FuncCall::class));
        $k2 = SubtreeFold::hashPair($this->first(<<<'PHP'
            <?php
            function a() { return bin2hex(random_bytes(32)); }
            PHP, Expr\FuncCall::class));

        self::assertNotSame($k1['exact'], $k2['exact'], 'exact keeps literal values');
        self::assertSame($k1['abstract'], $k2['abstract'], 'abstract blanks them');
    }

    public function testBarewordConstantsAreBlankedInAbstractOnly(): void
    {
        // The [f09] gap: true/false/PHP_INT_MAX are ConstFetch and leaked
        // into every "literal-free" Normalizer key. Abstract must not see
        // them; exact must still distinguish them.
        $kTrue  = SubtreeFold::hashPair($this->first(<<<'PHP'
            <?php
            function a() { return $w === true; }
            PHP, Expr\BinaryOp\Identical::class));
        $kFalse = SubtreeFold::hashPair($this->first(<<<'PHP'
            <?php
            function a() { return $w === false; }
            PHP, Expr\BinaryOp\Identical::class));
        $kMax   = SubtreeFold::hashPair($this->first(<<<'PHP'
            <?php
            function a() { return $w === PHP_INT_MAX; }
            PHP, Expr\BinaryOp\Identical::class));
        $kMine  = SubtreeFold::hashPair($this->first(<<<'PHP'
            <?php
            function a() { return $w === MY_LIMIT; }
            PHP, Expr\BinaryOp\Identical::class));

        self::assertNotSame($kTrue['exact'], $kFalse['exact']);
        self::assertNotSame($kMax['exact'], $kMine['exact']);
        self::assertSame($kTrue['abstract'], $kFalse['abstract']);
        self::assertSame($kMax['abstract'], $kMine['abstract'], 'a bareword is a literal-leaf for the abstract tier');
        self::assertSame($kTrue['abstract'], $kMax['abstract']);
    }

    public function testClassConstFetchKeepsClassBlanksMember(): void
    {
        $ast = $this->parser->parse(<<<'PHP'
            <?php
            function a() { return Foo::BAR + Foo::BAZ + Bar::BAR; }
            PHP);
        $all = (new NodeFinder())->findInstanceOf($ast, Expr\ClassConstFetch::class);
        self::assertCount(3, $all);

        $k1 = SubtreeFold::hashPair($all[0]);
        $k2 = SubtreeFold::hashPair($all[1]);
        $k3 = SubtreeFold::hashPair($all[2]);

        self::assertNotSame($k1['exact'], $k2['exact']);
        self::assertSame($k1['abstract'], $k2['abstract'], 'same class, blanked member => same abstract');
        self::assertNotSame($k1['abstract'], $k3['abstract'], 'class identity survives blanking');
    }

    public function testThisIsNotRenumberedIntoVarSlots(): void
    {
        $ka = SubtreeFold::hashPair($this->first(<<<'PHP'
            <?php
            function a() { return $x->prop + 1; }
            PHP, Expr\BinaryOp\Plus::class));
        $kb = SubtreeFold::hashPair($this->first(<<<'PHP'
            <?php
            class C { public function m() { return $this->prop + 1; } }
            PHP, Expr\BinaryOp\Plus::class));

        self::assertNotSame($ka['exact'], $kb['exact'], '$this is semantically distinct — kept verbatim');
    }

    public function testUpstreamGeneratedVarNamesPassThroughWithoutCollision(): void
    {
        // A subtree lifted from a canonicalised block carries __V0 as a
        // real name; the engine-055 guard must not re-deal that slot to
        // another variable of the same subtree.
        $pair = SubtreeFold::hashPair($this->first(<<<'PHP'
            <?php
            function a() { return __V0 + $qqq; }
            PHP, Expr\BinaryOp\Plus::class));
        // Same DEAL shape under a different user name matches …
        $same = SubtreeFold::hashPair($this->first(<<<'PHP'
            <?php
            function a() { return __V0 + $zzz; }
            PHP, Expr\BinaryOp\Plus::class));
        self::assertSame($pair['exact'], $same['exact']);

        // … while an extra real variable must not steal the __V0 token.
        $third = SubtreeFold::hashPair($this->first(<<<'PHP'
            <?php
            function a() { return __V0 + $qqq + 1; }
            PHP, Expr\BinaryOp\Plus::class));
        self::assertNotSame($pair['exact'], $third['exact']);
    }

    public function testInterpolatedStringsBlankPartsInAbstract(): void
    {
        $k1 = SubtreeFold::hashPair($this->first(<<<'PHP'
            <?php
            function a() { return "prefix{$u}sfx" . 12; }
            PHP, Expr\BinaryOp\Concat::class));
        $k2 = SubtreeFold::hashPair($this->first(<<<'PHP'
            <?php
            function a() { return "zzz{$w}yyy" . 99; }
            PHP, Expr\BinaryOp\Concat::class));

        self::assertNotSame($k1['exact'], $k2['exact']);
        self::assertSame($k1['abstract'], $k2['abstract']);
    }

    public function testFoldIsPureNeverMutatesInput(): void
    {
        $sub    = $this->first(<<<'PHP'
            <?php
            function a() { return strlen($keeper) . 'lit'; }
            PHP, Expr\BinaryOp\Concat::class);
        $before = AstSerializer::tokens($sub);
        SubtreeFold::hashPair($sub);
        self::assertSame($before, AstSerializer::tokens($sub), 'hashPair() folds clones, never the input tree');
    }
}
