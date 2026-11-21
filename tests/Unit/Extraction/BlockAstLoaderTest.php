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
 * kind + name + scope first, then an ORDER-PRESERVING claim ledger per
 * (file, kind, name, namespace, class) group — refreshing the block's
 * range, or failing loud with kind + stale hint in the message. Silence
 * is only ever acceptable when every member landed on its own node;
 * deletion-direction drift is intentionally loud (round-2 review [M-1]).
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

    /**
     * Review round-2 [M-1] Vector 1: deleting comment lines above TWO
     * same-group members shifts both up; the pure nearest-with-bias
     * scorer hands the LOWER node to the UPPER member (distance term),
     * pre-fix double-anchored both and dropped the true block with
     * RC=0. Post-fix the second member's order window is exhausted and
     * the run dies LOUD; the hint-descending order resolves honestly.
     * Silent mis-pairing must never be observable.
     */
    public function testDeletionDriftOverTwinMembersNeverSilentlyMisAnchors(): void
    {
        $file = $this->write('f.php', self::twinIfsWithTopFillers());
        $probe = $this->freshIfPair($file);
        self::assertSame([23, 25], [$probe[0]->range->start, $probe[0]->range->end]);
        self::assertSame([30, 32], [$probe[1]->range->start, $probe[1]->range->end]);

        $this->deleteFileLines($file, 8, 15); // 15 comment lines vanish above both
        // Truth after deletion: [8,10] and [15,17].

        // File order (upper member first — what the cluster member list
        // actually is): the upper member pulls the lower node; the lower
        // member must then fail LOUD instead of double-anchoring.
        $loud = null;
        $pair = $this->freshIfPair($file, [[23, 25], [30, 32]]);
        try {
            $loader = $this->loader();
            $loader->resolve($pair[0]);
            $loader->resolve($pair[1]);
        } catch (\RuntimeException $e) {
            $loud = $e->getMessage();
        }
        if ($loud !== null) {
            self::assertStringContainsString('could not relocate', $loud);
            self::assertStringContainsString('kind if', $loud);
        } else {
            // The only permitted silent completion is the honest pairing.
            self::assertSame([8, 10], [$pair[0]->range->start, $pair[0]->range->end]);
            self::assertSame([15, 17], [$pair[1]->range->start, $pair[1]->range->end]);
        }

        // Hint-descending order: monotone constraints land both members
        // on their true nodes — deletions are loud OR correct.
        $loader = $this->loader();
        $pair = $this->freshIfPair($file, [[23, 25], [30, 32]]);
        $loader->resolve($pair[1]);
        $loader->resolve($pair[0]);
        self::assertSame([8, 10], [$pair[0]->range->start, $pair[0]->range->end]);
        self::assertSame([15, 17], [$pair[1]->range->start, $pair[1]->range->end]);
    }

    /**
     * Review round-2 [M-1] Vector 2: an 8-line comment deletion moves
     * sibling B from [24,26] EXACTLY onto stale member A's hint [16,18].
     * Pre-fix, findStrict served B's node to A with zero uniqueness
     * checks and A's truth [8,10] vanished silently. Post-fix either the
     * steal is refused (B first ⇒ both correct) or the robbed member
     * starves loudly (A first ⇒ B dies on an exhausted window).
     */
    public function testSiblingDriftingOntoAStaleHintIsLoudOrCorrectNeverSilent(): void
    {
        $file = $this->write('g.php', self::vector2Geometry());
        $probe = $this->freshIfPair($file);
        self::assertSame([16, 18], [$probe[0]->range->start, $probe[0]->range->end]);
        self::assertSame([24, 26], [$probe[1]->range->start, $probe[1]->range->end]);

        $this->deleteFileLines($file, 8, 8); // A -> [8,10]; B -> [16,18] exactly A's stale hint

        // B first: it re-anchors honestly to [16,18]; then A's strict hit
        // on that foreign-CLAIMED span must be refused and A must land
        // on its true [8,10].
        $loader = $this->loader();
        [$a, $b] = $this->freshIfPair($file, [[16, 18], [24, 26]]);
        $loader->resolve($b);
        $loader->resolve($a);
        self::assertSame([16, 18], [$b->range->start, $b->range->end]);
        self::assertSame([8, 10], [$a->range->start, $a->range->end],
            'a strict hit already claimed by another member must not be served twice');

        // Fresh state, A first: A momentarily takes [16,18] (its hint),
        // but B's window then exhausts — loud death, never a silent
        // both-anchored report.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/could not relocate/');
        $loader2 = $this->loader();
        [$a2, $b2] = $this->freshIfPair($file, [[16, 18], [24, 26]]);
        $loader2->resolve($a2);
        $loader2->resolve($b2);
    }

    public function testThirdMemberWithNoLiveCounterpartFailsLoud(): void
    {
        // Count law: two stale hints anchor (unchanged file, strict
        // path), a third stale hint from an older cache has no node
        // left to claim — loud, not a re-serve of an anchored span.
        $file   = $this->write('h.php', self::twoTwinIfs());
        [$u, $l] = $this->ifBlocks($file);
        $loader = $this->loader();
        $u->unloadAst();
        $l->unloadAst();
        $loader->resolve($u);
        $loader->resolve($l);

        [$ul, $ll] = $this->ifBlocks($file);
        $ghost = new Block($file, $ll->range, 'if', 'TwinLand', 'TwinHost', null, $ul->ast ?? new Node\Stmt\Nop());
        $ghost->unloadAst();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/could not relocate/');
        $loader->resolve($ghost);
    }

    public function testAnonymousClassMembersReanchorLikeNamedOnes(): void
    {
        // Regression pin for the sentinel bug the src/ self-scan warm
        // replay caught: BlockVisitor marks anonymous classes as
        // '<anonymous>' (BlockExtractor.php:101) and stores exactly that
        // in Block::$class — the loader's scope filter must use the same
        // literal. A divergent sentinel silently drops every
        // anonymous-class candidate, turning an honest insertion drift
        // into a loud 'could not relocate' on real code (the loader's own
        // visitors' guard clauses in this very file).
        $file  = $this->write('g.php', self::anonymousTwinIfs());
        $pairs = $this->anonymousIfBlocks($file);
        self::assertCount(2, $pairs);

        $before = array_map(static fn (Block $b): array => [$b->range->start, $b->range->end], $pairs);

        $this->insertAfterOpen($file, 2);
        foreach ($pairs as $p) {
            $p->unloadAst();
        }

        $loader = $this->loader();
        $loader->resolve($pairs[0]);
        $loader->resolve($pairs[1]);

        $live = $this->ifRanges($file);
        self::assertSame($live, [
            [$pairs[0]->range->start, $pairs[0]->range->end],
            [$pairs[1]->range->start, $pairs[1]->range->end],
        ], 'pure +2 insertion drift inside anonymous scope must re-anchor honestly, in file order');
        self::assertSame([$before[0][0] + 2, $before[0][1] + 2], $live[0]);
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
        $lines = explode("\n", (string) file_get_contents($file));
        $pad   = [];
        for ($i = 0; $i < $n; $i++) {
            $pad[] = '// drift ' . $i;
        }
        array_splice($lines, 1, 0, $pad);
        file_put_contents($file, implode("\n", $lines));
    }

    /** Delete $count lines starting at 1-based line $first. */
    private function deleteFileLines(string $file, int $first, int $count): void
    {
        $lines = explode("\n", (string) file_get_contents($file));
        array_splice($lines, $first - 1, $count);
        file_put_contents($file, implode("\n", $lines));
    }

    /**
     * Blocks whose class field carries the extractor's anonymous marker
     * (BlockExtractor.php:101 stores '<anonymous>' for anonymous classes).
     *
     * @return list<Block> every if-block in the file, in file order
     */
    private function anonymousIfBlocks(string $file): array
    {
        $stmts = $this->parser->parseFile($file) ?? [];
        $ns    = $stmts[0] instanceof Node\Stmt\Namespace_ ? $stmts[0]->name?->toString() : null;
        $ifs   = (new NodeFinder())->find(
            $stmts,
            static fn (Node $n): bool => $n instanceof Node\Stmt\If_
        );
        $blocks = [];
        foreach ($ifs as $i) {
            $blocks[] = new Block($file, new LineRange($i->getStartLine(), $i->getEndLine()),
                'if', $ns, '<anonymous>', null, $i);
        }
        return $blocks;
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

    /** @return array{0:Block,1:Block} the two twin-if blocks, in file order, AST loaded */
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

    /**
     * Unloaded if-blocks hinted at $hints (default: current live
     * positions) — fresh objects, so each scenario runs on a clean
     * claim ledger.
     *
     * @param list<array{0:int,1:int}>|null $hints
     * @return array{0:Block,1:Block}
     */
    private function freshIfPair(string $file, ?array $hints = null): array
    {
        [$a, $b] = $this->ifBlocks($file);
        $out = [];
        foreach ([$a, $b] as $i => $block) {
            $hint = $hints[$i] ?? [$block->range->start, $block->range->end];
            $fresh = new Block($file, new LineRange($hint[0], $hint[1]),
                'if', $block->namespace, $block->class, null, $block->ast ?? new Node\Stmt\Nop());
            $fresh->unloadAst();
            $out[] = $fresh;
        }
        /** @var array{0:Block,1:Block} $out */
        return $out;
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

    private static function anonymousTwinIfs(): string
    {
        // Two anonymous classes (Block::$class = '<anonymous>' by the
        // extractor's marker convention), one structurally identical
        // if-block each — the exact shape of this loader's own visitor
        // pairs that a warm src/ replay exercises.
        return <<<'PHP'
            <?php

            namespace TwinLand;

            final class Host
            {
                public function run(): int
                {
                    $a = (new class {
                        public function gate(int $x): int
                        {
                            if ($x > 1) {
                                $x += 1;
                            }
                            return $x;
                        }
                    })->gate(1);
                    $b = (new class {
                        public function gate(int $x): int
                        {
                            if ($x > 2) {
                                $x += 2;
                            }
                            return $x;
                        }
                    })->gate(2);
                    return $a + $b;
                }
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

    /** if #1 at lines 23-25, if #2 at 30-32; 15 deletable fillers at 8-22. */
    private static function twinIfsWithTopFillers(): string
    {
        $fillers = '';
        for ($i = 1; $i <= 15; $i++) {
            $fillers .= sprintf("        // f%02d\n", $i);
        }
        $gap = '';
        for ($i = 1; $i <= 4; $i++) {
            $gap .= sprintf("            // g%02d\n", $i);
        }
        return "<?php\n\nnamespace TwinLand;\n\nclass TwinHost\n{\n"
            . "    public function gate(int \$x): int {\n"
            . $fillers
            . "        if (\$x > 1) {\n            \$x += 1;\n        }\n"
            . $gap
            . "        if (\$x > 2) {\n            \$x += 2;\n        }\n"
            . "        return \$x;\n    }\n}\n";
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

    /** if #1 at lines 16-18, if #2 at 24-26; 8 deletable fillers at 8-15. */
    private static function vector2Geometry(): string
    {
        $deleteme = '';
        for ($i = 1; $i <= 8; $i++) {
            $deleteme .= sprintf("        // dm%02d\n", $i);
        }
        $gap = '';
        for ($i = 1; $i <= 5; $i++) {
            $gap .= sprintf("            // gp%02d\n", $i);
        }
        return "<?php\n\nnamespace TwinLand;\n\nclass TwinHost\n{\n"
            . "    public function gate(int \$x): int {\n"
            . $deleteme
            . "        if (\$x > 1) {\n            \$x += 1;\n        }\n"
            . $gap
            . "        if (\$x > 2) {\n            \$x += 2;\n        }\n"
            . "        return \$x;\n    }\n}\n";
    }
}
