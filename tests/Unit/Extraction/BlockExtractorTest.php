<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Extraction;

use PhpParser\Node\Stmt;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use Phpdup\Extraction\BlockExtractor;

final class BlockExtractorTest extends TestCase
{
    public function testWithoutFilterAllKindsAreEmitted(): void
    {
        $blocks = $this->extract(<<<'PHP'
        <?php
        function alpha() { for ($i = 0; $i < 10; $i++) { echo $i; } }
        class Repo {
            public function find($id) { if ($id) { return $id; } else { return null; } }
        }
        PHP);

        $kinds = array_unique(array_map(fn($b) => $b->kind, $blocks));
        sort($kinds);
        $this->assertContains('function', $kinds);
        $this->assertContains('method', $kinds);
        $this->assertContains('if', $kinds);
        $this->assertContains('for', $kinds);
    }

    public function testFilterRetainsOnlySpecifiedKinds(): void
    {
        $blocks = $this->extract(<<<'PHP'
        <?php
        function alpha() { for ($i = 0; $i < 10; $i++) { echo $i; } }
        class Repo {
            public function find($id) { if ($id) { return $id; } else { return null; } }
        }
        PHP, allowedKinds: ['method', 'function']);

        $kinds = array_unique(array_map(fn($b) => $b->kind, $blocks));
        $this->assertEqualsCanonicalizing(['function', 'method'], $kinds);
    }

    public function testEmptyFilterListMeansAccept(): void
    {
        $blocks = $this->extract(<<<'PHP'
        <?php
        function f() { for ($i = 0; $i < 10; $i++) { echo $i; } }
        PHP, allowedKinds: []);

        $this->assertNotEmpty($blocks);
    }

    public function testAllKindsConstantCoversEveryClassifyKindOutputPlusScript(): void
    {
        // Item 1.3 changed the vocabulary's shape: ALL_KINDS is now the 12
        // traversable classifyKind() outputs PLUS 'script' — a kind that
        // classifyKind can never emit (it is produced only by
        // BlockExtractor::groupTopLevel() folds, and it is never part of
        // the implicit accept-all set; the opt-in posture is pinned in
        // ScriptKindTest). The equality below is the drift guard: if a
        // future traversable kind joins classifyKind without ALL_KINDS,
        // or 'script' leaks into classifyKind, this fails.
        $traversable = [
            'function', 'method', 'closure', 'arrow',
            'if', 'for', 'foreach', 'while', 'do',
            'try', 'switch', 'match',
        ];
        $this->assertEqualsCanonicalizing([...$traversable, 'script'], BlockExtractor::ALL_KINDS);

        $visitor = new \Phpdup\Extraction\BlockVisitor('virtual.php', 1, 100, [], static function (): void {});
        $classify = new \ReflectionMethod($visitor, 'classifyKind');
        $classify->setAccessible(true);
        $emitted = [];
        foreach ($this->specimenNodes() as $node) {
            $kind = $classify->invoke($visitor, $node);
            if ($kind !== null) {
                $emitted[$kind] = true;
            }
            $this->assertNotSame('script', $kind, 'classifyKind must never produce the fold kind');
        }
        $this->assertEqualsCanonicalizing($traversable, array_keys($emitted));
    }

    /** @return list<\PhpParser\Node> one node per traversable kind, incl a Stmt\Block. */
    private function specimenNodes(): array
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ast = $parser->parse(<<<'PHP'
        <?php
        function f() { for ($i = 0; $i < 9; $i++) { echo $i; } }
        class C { public function m() { while (true) { if ($x) { break; } } } }
        $cl = function () { try { echo 1; } catch (E) { echo 2; } };
        $ar = fn() => match (1) { default => 2 };
        do { echo 1; } while (false);
        foreach ($xs as $x) { echo $x; }
        sw: switch ($s) { case 1: break 2; }
        PHP) ?? [];
        $nodes = [];
        $finder = new \PhpParser\NodeFinder();
        foreach ([
            Stmt\Function_::class, Stmt\ClassMethod::class, \PhpParser\Node\Expr\Closure::class, \PhpParser\Node\Expr\ArrowFunction::class,
            Stmt\If_::class, Stmt\For_::class, Stmt\Foreach_::class, Stmt\While_::class, Stmt\Do_::class,
            Stmt\TryCatch::class, Stmt\Switch_::class, \PhpParser\Node\Expr\Match_::class,
        ] as $class) {
            $found = $finder->find($ast, static fn (\PhpParser\Node $n): bool => $n instanceof $class);
            $this->assertNotEmpty($found, "specimen code must contain {$class}");
            $nodes[] = $found[0];
        }
        // A wrapped block (the script fold's shape) must classify to null.
        $nodes[] = new Stmt\Block([$ast[1]]);
        return $nodes;
    }

    /**
     * @param list<string> $allowedKinds
     * @return list<\Phpdup\Extraction\Block>
     */
    private function extract(string $code, array $allowedKinds = []): array
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ast = $parser->parse($code) ?? [];
        return (new BlockExtractor(minSize: 1, maxSize: 2000, allowedKinds: $allowedKinds))
            ->extract('virtual.php', $ast);
    }
}
