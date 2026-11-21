<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Extraction;

use PhpParser\Node;
use PhpParser\NodeFinder;
use Phpdup\Extraction\Block;
use Phpdup\Extraction\BlockAstLoader;
use Phpdup\Parsing\AstCache;
use Phpdup\Parsing\AstParser;
use Phpdup\Util\LineRange;
use PHPUnit\Framework\TestCase;

/**
 * Relocation pins for the lazy-AST loader (fix round, review item [M]-1).
 *
 * The warm cluster cache replays member blocks with the line ranges they
 * had when the cache was written; a comment-only edit above those members
 * keeps every structuralHash (so the cache hit is correct by design) while
 * silently moving the real lines. resolve() must re-anchor from content —
 * kind + name + scope first, span/distance/insertion-bias scoring for
 * nameless structural blocks — and refresh the block's range, or fail
 * loud with kind + stale hint in the message.
 */
final class BlockAstLoaderTest extends TestCase
{
    private string $dir;

    private AstParser $parser;

    protected function setUp(): void
    {
        $this->dir    = sys_get_temp_dir() . '/bal-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o775, true);
        $this->parser = new AstParser();
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->dir);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    public function testStrictHitResolvesAnUnloadedBlockWithFreshRanges(): void
    {
        $file  = $this->write('a.php', self::oneFunction());
        $block = $this->functionBlock($file, 'measure_alpha');

        $loader = $this->loader();
        $block->unloadAst();
        $node = $loader->resolve($block);

        self::assertInstanceOf(Node\Stmt\Function_::class, $node);
        self::assertSame('measure_alpha', (string) $node->name, 'strict path must land on the same function');
    }

    public function testCommentDriftAboveABlockReanchorsAndRefreshesItsRange(): void
    {
        $src   = self::oneFunction();
        $file  = $this->write('b.php', $src);
        $block = $this->functionBlock($file, 'measure_alpha');
        $oldStart = $block->range->start;

        // Two lines inserted ABOVE the function; nothing inside moves
        // relative to itself — this is exactly the cluster-cache replay
        // hazard: the stale hint is now wrong by +2.
        $this->insertAfterOpen($file, 2);
        $block->unloadAst();

        $loader = $this->loader();
        $node   = $loader->resolve($block);

        self::assertInstanceOf(Node\Stmt\Function_::class, $node);
        self::assertSame($oldStart + 2, $block->range->start, 'relocated range must be refreshed');
        self::assertSame($node->getStartLine(), $block->range->start);
        self::assertSame($node->getEndLine(), $block->range->end);
        self::assertSame($block->ast, $node, 'resolve() must attach the located node');
    }

    public function testMirrorCandidatesTieBreakTowardTheInsertionSide(): void
    {
        // Two 3-line if blocks inside the same class scope, separated by a
        // blank line. After inserting two lines above the second one, the
        // stale hint for the SECOND if sits exactly equidistant between the
        // (now-unchanged) first if and the (shifted) second. The
        // insertion-bias lever must pick the candidate at-or-below the
        // hint — a pure insert above a block shifts it DOWN, never up.
        $file = $this->write('c.php', self::twoTwinIfs());
        [$first, $hint] = $this->ifBlocks($file);

        // Sanity: the two 3-line ifs are separated by one blank line.
        self::assertSame($first->range->end + 2, $hint->range->start);
        self::assertSame($hint->range->end - $hint->range->start, $first->range->end - $first->range->start);

        $this->insertAfterOpen($file, 2);
        $hint->unloadAst();

        $this->loader()->resolve($hint);

        // Re-derive truth from the edited file: the second if is now +2.
        $moved = $this->ifRanges($file);
        self::assertCount(2, $moved);
        self::assertSame($moved[1], [$hint->range->start, $hint->range->end],
            'the equidistant mirror above must lose to the true (downward-shifted) block');
    }

    public function testScopeFilterBeatsACloserSameKindBlockInAnotherClass(): void
    {
        $file  = $this->write('d.php', self::splitScopeIfs());
        $block = $this->ifBlockIn($file, 'Upper', 1);

        // Insert 10 lines near the top: Upper's if moves +10; the
        // decoy if in Lower was already closer to the stale hint and
        // moves too — scope must decide, not distance.
        $this->insertAfterOpen($file, 10);
        $block->unloadAst();

        $this->loader()->resolve($block);

        self::assertSame('Upper', $block->class);
        $ranges = $this->ifRanges($file, classFilter: 'Upper');
        self::assertSame($ranges[0], [$block->range->start, $block->range->end],
            'relocation must land inside the block\'s own class scope');
    }

    public function testDeletedBlockStillFailsLoudWithKindAndHintedRange(): void
    {
        $file  = $this->write('e.php', self::oneFunction());
        $block = $this->functionBlock($file, 'measure_alpha');
        $hint  = (string) $block->range;

        // Content removal: no candidate at any distance — loud throw.
        file_put_contents($file, "<?php\n// the function is gone\n");
        $block->unloadAst();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches(
            '/could not relocate block .* \(kind function, hinted range ' . preg_quote($hint, '/') . '\)/'
        );
        $this->loader()->resolve($block);
    }

    // ---------------------------------------------------------------- glue

    private function loader(): BlockAstLoader
    {
        return new BlockAstLoader(new AstCache($this->dir . '/astcache'), $this->parser);
    }

    private function write(string $name, string $src): string
    {
        $path = $this->dir . '/' . $name;
        file_put_contents($path, $src);
        return $path;
    }

    /** Insert $n comment lines right after the `<?php` header line. */
    private function insertAfterOpen(string $file, int $n): void
    {
        $lines   = explode("\n", (string) file_get_contents($file));
        $pad     = [];
        for ($i = 0; $i < $n; $i++) {
            $pad[] = '// drift ' . $i;
        }
            array_splice($lines, 1, 0, $pad);
        file_put_contents($file, implode("\n", $lines));
    }

    /** @return list<Block> function-kind blocks (name, range) */
    private function functionBlock(string $file, string $name): Block
    {
        $stmts = $this->parser->parseFile($file) ?? [];
        $fn    = (new NodeFinder())->findFirst(
            $stmts,
            static fn (Node $n): bool => $n instanceof Node\Stmt\Function_ && (string) $n->name === $name
        );
        self::assertInstanceOf(Node\Stmt\Function_::class, $fn);
        $ns = $stmts[0] instanceof Node\Stmt\Namespace_ ? $stmts[0]->name?->toString() : null;
        return new Block($file, new LineRange($fn->getStartLine(), $fn->getEndLine()),
            'function', $ns, null, $name, $fn);
    }

    /** @return array{0:Block,1:Block} the two twin-if blocks, in file order */
    private function ifBlocks(string $file): array
    {
        $stmts = $this->parser->parseFile($file) ?? [];
        $ns    = $stmts[0] instanceof Node\Stmt\Namespace_ ? $stmts[0]->name?->toString() : null;
        $ifs   = (new NodeFinder())->find(
            $stmts,
            static fn (Node $n): bool => $n instanceof Node\Stmt\If_
        );
        self::assertCount(2, $ifs);
        $blocks = [];
        foreach ($ifs as $i) {
            $blocks[] = new Block($file, new LineRange($i->getStartLine(), $i->getEndLine()),
                'if', $ns, 'TwinHost', null, $i);
        }
        return $blocks;
    }

    private function ifBlockIn(string $file, string $class, int $nth): Block
    {
        $stmts = $this->parser->parseFile($file) ?? [];
        $ns    = $stmts[0] instanceof Node\Stmt\Namespace_ ? $stmts[0]->name?->toString() : null;
        $want  = null;
        $classNode = (new NodeFinder())->findFirst(
            $stmts,
            static fn (Node $n): bool => $n instanceof Node\Stmt\Class_ && (string) $n->name === $class
        );
        self::assertNotNull($classNode, "class $class must exist in fixture");
        /** @var Node\Stmt\Class_ $classNode */
        foreach ($classNode->getMethods() as $m) {
            $found = (new NodeFinder())->find(
                $m->getStmts(),
                static fn (Node $n): bool => $n instanceof Node\Stmt\If_
            );
            if ($found !== []) {
                $want = $found[$nth - 1] ?? null;
                break;
            }
        }
        self::assertNotNull($want);
        return new Block($file, new LineRange($want->getStartLine(), $want->getEndLine()),
            'if', $ns, $class, null, $want);
    }

    /** @return list<array{0:int,1:int}> all if-block [start,end] pairs in order */
    private function ifRanges(string $file, ?string $classFilter = null): array
    {
        $stmts = $this->parser->parseFile($file) ?? [];
        if ($classFilter !== null) {
            $cls = (new NodeFinder())->findFirst(
                $stmts,
                static fn (Node $n): bool => $n instanceof Node\Stmt\Class_ && (string) $n->name === $classFilter
            );
            self::assertNotNull($cls);
            $stmts = [$cls];
        }
        $out = [];
        foreach ((new NodeFinder())->find($stmts,
            static fn (Node $n): bool => $n instanceof Node\Stmt\If_) as $i) {
            $out[] = [$i->getStartLine(), $i->getEndLine()];
        }
        return $out;
    }

    // ----------------------------------------------------------- fixtures

    private static function oneFunction(): string
    {
        return <<<'PHP'
            <?php

            namespace TwinLand;

            function measure_alpha(array $rows): float
            {
                $sum = 0.0;
                foreach ($rows as $r) {
                    $sum += $r;
                }
                return $sum;
            }
            PHP;
    }

    private static function twoTwinIfs(): string
    {
        return <<<'PHP'
            <?php

            namespace TwinLand;

            class TwinHost
            {
                public function gate(int $x): int
                {
                    if ($x > 1) {
                        $x += 1;
                    }

                    if ($x > 2) {
                        $x += 2;
                    }
                    return $x;
                }
            }
            PHP;
    }

    private static function splitScopeIfs(): string
    {
        return <<<'PHP'
            <?php

            namespace TwinLand;

            class Upper
            {
                public function gate(int $x): int
                {
                    if ($x > 1) {
                        $x += 1;
                    }
                    return $x;
                }
            }

            class Lower
            {
                public function gate(int $x): int
                {
                    if ($x > 9) {
                        if ($x > 10) {
                            $x += 3;
                        }
                    }
                    return $x;
                }
            }
            PHP;
    }
}
