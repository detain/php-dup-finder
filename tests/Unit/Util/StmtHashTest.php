<?php

declare(strict_types=1);

namespace Phpdup\Tests\Unit\Util;

use PhpParser\Node;
use PhpParser\ParserFactory;
use Phpdup\Refactor\AntiUnifier;
use Phpdup\Util\StmtHash;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Item 2.1 (§5c single fold-key law): the extracted shared statement hash
 * must be BYTE-IDENTICAL to what AntiUnifier used to compute inline — the
 * delegation is a relocation, not a re-derivation.
 */
final class StmtHashTest extends TestCase
{
    /**
     * @return array<string, array{0:string}>
     */
    public static function specimenProvider(): array
    {
        return [
            'assignment' => ['<?php $rows = $db->query("select * from t");'],
            'if' => ['<?php if ($a > 1) { bail(); }'],
            'return' => ['<?php function f() { return ["x" => (int) $y]; }'],
            'loop' => ['<?php foreach ($xs as $x) { echo $x; }'],
            'try' => ['<?php try { go(); } catch (\\Exception $e) { log($e); }'],
        ];
    }

    private static function antiUnifier(): AntiUnifier
    {
        return (new \ReflectionClass(AntiUnifier::class))->newInstanceWithoutConstructor();
    }

    /**
     * @dataProvider specimenProvider
     */
    public function testMatchesAntiUnifierPrivateFormula(string $code): void
    {
        $stmt = self::firstStatement($code);
        $private = new ReflectionMethod(AntiUnifier::class, 'stmtHash');

        self::assertSame(
            $private->invoke(self::antiUnifier(), $stmt),
            StmtHash::hash($stmt),
        );
    }

    public function testLiteralFoldsAreNotPartOfThisHash(): void
    {
        // Raw statements: literals stay distinct — the FOLD happens in the
        // Normalizer BEFORE this function sees the node (documented posture).
        $a = StmtHash::hash(self::firstStatement('<?php go("one");'));
        $b = StmtHash::hash(self::firstStatement('<?php go("two");'));
        self::assertNotSame($a, $b);

        // Canonicalized input: equal tokens => equal keys.
        $x = StmtHash::hash(self::firstStatement('<?php go("one");'));
        self::assertSame($x, StmtHash::hash(self::firstStatement('<?php go("one");')));
    }

    private static function firstStatement(string $code): Node\Stmt
    {
        $stmts = (new ParserFactory())->createForNewestSupportedVersion()->parse($code);
        self::assertIsArray($stmts);
        self::assertNotEmpty($stmts);
        $first = $stmts[0];
        if ($first instanceof Node\Stmt\Function_) {
            return $first->getStmts()[0];
        }
        self::assertInstanceOf(Node\Stmt::class, $first);
        return $first;
    }
}
