<?php

declare(strict_types=1);

namespace Phpdup\Tests\Unit\Extraction;

use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\ParserFactory;
use Phpdup\Extraction\Block;
use Phpdup\Extraction\BlockExtractor;
use PHPUnit\Framework\TestCase;

/**
 * Item 7.2b — the 'html_script' window leg of BlockExtractor: opt-in
 * posture, the raw-text window scanner (openers, src= skips, closers,
 * ?&gt;/&lt;?php spanning, babel variants, unclosed discard), the
 * markup/slice/stmt lineage, slice caps, WindowPacker overflow, the
 * fold-bucket HTML-sentinel law, and the lastStatementLists() wire
 * contract (unchanged: fragment product only).
 */
final class BlockExtractorHtmlScriptTest extends TestCase
{
    /** The twelve traversable kinds (the implicit accept-all roster). */
    private const TRAVERSABLE = [
        'function', 'method', 'closure', 'arrow',
        'if', 'for', 'foreach', 'while', 'do',
        'try', 'switch', 'match',
    ];

    /**
     * @param list<string> $allowedKinds
     * @return list<Block>
     */
    private function extract(string $code, array $allowedKinds, int $minSize = 1, int $maxSize = 100000): array
    {
        $stmts = (new ParserFactory())->createForNewestSupportedVersion()->parse($code);
        self::assertIsArray($stmts);
        return (new BlockExtractor($minSize, $maxSize, $allowedKinds))->extract('t.phtml', $stmts);
    }

    /**
     * @param list<Block> $blocks
     * @return list<Block>
     */
    private static function scriptBlocks(array $blocks): array
    {
        return array_values(array_filter($blocks, static fn (Block $b): bool => $b->kind === BlockExtractor::HTML_SCRIPT_KIND));
    }

    /** A template whose JS window spans a real PHP escape with stmts inside. */
    private const SPAN = "<div>\n<script>\nvar a = 1;\n"
        . "<?php echo \"mid\"; \$t = 40 + 2; ?>\n"
        . "var b = 2;\n"
        . "<?= \$render ?>\n"
        . "var c = 3;\n</script>\n</div>\n";

    public function testKindIsNamedInAllKindsAndNeverImplicit(): void
    {
        self::assertContains(BlockExtractor::HTML_SCRIPT_KIND, BlockExtractor::ALL_KINDS);
        foreach ([[], self::TRAVERSABLE, ['script'], ['fragment'], ['class']] as $roster) {
            self::assertSame([], self::scriptBlocks($this->extract(self::SPAN, $roster)), 'html_script must never be implicit');
        }
    }

    public function testSpanningWindowMintsFullFragmentSequenceWithLineage(): void
    {
        $blocks = self::scriptBlocks($this->extract(self::SPAN, [BlockExtractor::HTML_SCRIPT_KIND]));
        self::assertCount(1, $blocks);
        $b = $blocks[0];
        self::assertSame(2, $b->range->start);
        self::assertSame(8, $b->range->end);
        // The crux of the design: the fold carries the whole mixed
        // sequence — cut text, real stmts, whole interior run, short-echo
        // stmt, cut text — with per-member lineage classes.
        self::assertSame(['slice', 'stmt', 'stmt', 'markup', 'stmt', 'slice'], $b->fragmentLineage);
        self::assertInstanceOf(Stmt\Block::class, $b->ast);
        self::assertIsInt($b->size);
        self::assertGreaterThan(0, $b->size);
        // Wrapper members positionally parallel the lineage list.
        self::assertCount(count($b->fragmentLineage), $b->ast->stmts);
        // Lineage matches the classifier run over the wrapper members.
        self::assertSame(BlockExtractor::lineageClasses($b->ast->stmts), $b->fragmentLineage);
    }

    public function testBabelWindowsAreOrdinaryWindows(): void
    {
        $code = "<x>\n<script language='text/babel'>\nfunction App(){return <div/>;}\n</script>\n"
            . "<script type=\"text/babel\">\nclass B extends React.Component {}\n</script>\n</x>\n";
        $blocks = self::scriptBlocks($this->extract($code, [BlockExtractor::HTML_SCRIPT_KIND]));
        self::assertCount(2, $blocks, 'language= and type= babel variants are plain raw-text windows');
        foreach ($blocks as $b) {
            self::assertSame(['slice'], $b->fragmentLineage, 'opener and closer share the run with the body: the window is edge-cut on both sides');
        }
    }

    public function testExternalSrcScriptCarriesNoWindow(): void
    {
        $code = "<x>\n<script src=\"/js/a.js\"></script>\n<script\n  type=\"module\"\n  src=\"app.js\">\n</script>\n</x>\n";
        self::assertSame([], self::scriptBlocks($this->extract($code, [BlockExtractor::HTML_SCRIPT_KIND])));
    }

    public function testUnclosedWindowIsDiscardedAndEmptyBodyToo(): void
    {
        $open = "<x>\n<script>\nvar a = 1;\nstill inside\n</x>\n";
        self::assertSame([], self::scriptBlocks($this->extract($open, [BlockExtractor::HTML_SCRIPT_KIND])), 'unclosed at end: no bounded fold exists');
        $empty = "<x>\n<script></script>\n</x>\n";
        self::assertSame([], self::scriptBlocks($this->extract($empty, [BlockExtractor::HTML_SCRIPT_KIND])), 'empty body: nothing to fold');
    }

    public function testMultipleWindowsAcrossRunsAndMembers(): void
    {
        $code = "<x>\n<script>one();</script>\ntext between\n<script>two();</script>\nmore\n<script>three();</script>\n</x>\n";
        $blocks = self::scriptBlocks($this->extract($code, [BlockExtractor::HTML_SCRIPT_KIND]));
        self::assertCount(3, $blocks);
        self::assertSame([2, 4, 6], array_map(static fn (Block $b): int => $b->range->start, $blocks));
    }

    public function testHugeInteriorRunIsSlicedUnderTheByteCap(): void
    {
        $body = str_repeat("payload_line;\n", 600); // ~8.4KB of JS bytes
        $code = "<x>\n<script>\n" . $body . "</script>\n</x>\n";
        // Window interior = the newline after the opener + the body.
        $interiorBytes = strlen($body) + 1;
        $blocks = self::scriptBlocks($this->extract($code, [BlockExtractor::HTML_SCRIPT_KIND]));
        self::assertCount(1, $blocks);
        $b = $blocks[0];
        // Cut at both edges (opener line kept out, closer line kept out):
        // the interior is sliced at SCRIPT_TEXT_WINDOW_BYTES boundaries.
        self::assertNotEmpty($b->fragmentLineage);
        foreach ($b->fragmentLineage as $cls) {
            self::assertSame('slice', $cls);
        }
        self::assertGreaterThan(1, count($b->fragmentLineage), 'an 8KB run must not ride as one member');
        $total = 0;
        if ($b->ast instanceof Stmt\Block) {
            foreach ($b->ast->stmts as $m) {
                self::assertInstanceOf(Stmt\InlineHTML::class, $m);
                self::assertLessThanOrEqual(BlockExtractor::SCRIPT_TEXT_WINDOW_BYTES, strlen($m->value));
                $total += strlen($m->value);
            }
        }
        self::assertSame($interiorBytes, $total, 'slicing must preserve the window interior bytes exactly');
    }

    public function testOversizedWindowPacksToWindowSpeciesWithLineage(): void
    {
        $body = str_repeat("var v = compute(1,2,3);\n", 600); // 8.4KB ≈ 264 pseudo-nodes, sliced at the 4096B cap
        $code = "<x>\n<script>\n" . $body . "</script>\n</x>\n";
        // maxSize below the whole-window weight but above ONE slice:
        // WindowPacker salvages runs of the slices, kind 'window' as always.
        $blocks = $this->extract($code, [BlockExtractor::HTML_SCRIPT_KIND], 2, 200);
        $kinds = array_values(array_unique(array_map(static fn (Block $b): string => $b->kind, $blocks)));
        self::assertNotContains(BlockExtractor::HTML_SCRIPT_KIND, $kinds, 'the whole window exceeds maxSize: only packed pieces survive');
        self::assertContains(BlockExtractor::WINDOW_KIND, $kinds);
        foreach ($blocks as $b) {
            self::assertSame(BlockExtractor::WINDOW_KIND, $b->kind);
            if ($b->ast instanceof Stmt\Block) {
                self::assertSame(BlockExtractor::lineageClasses($b->ast->stmts), $b->fragmentLineage, 'sink decorator stamped lineage on pieces');
            }
        }
    }

    public function testFoldBucketsKeepHtmlSentinelsWhereScriptFoldDropsThem(): void
    {
        $code = "<?php\n\$call = go();\n?>\n<b>chrome</b>\n<?php\n\$two = more();\n";
        $stmts = (new ParserFactory())->createForNewestSupportedVersion()->parse($code);
        self::assertIsArray($stmts);
        $plain = BlockExtractor::groupTopLevel($stmts);
        $withHtml = BlockExtractor::groupTopLevelFragments($stmts);
        self::assertCount(1, $plain);
        self::assertCount(1, $withHtml);
        $plainKinds = array_map(static fn (Node $n): string => $n::class, $plain[0]['stmts']);
        $fragKinds = array_map(static fn (Node $n): string => $n::class, $withHtml[0]['stmts']);
        self::assertNotContains(Stmt\InlineHTML::class, $plainKinds);
        self::assertContains(Stmt\InlineHTML::class, $fragKinds, 'the fold dropped the raw surface the 7.2b species needs');
        self::assertSame(null, $withHtml[0]['namespace']);
    }

    public function testScannerIsPureOverSequencesAndMatchesMint(): void
    {
        $stmts = (new ParserFactory())->createForNewestSupportedVersion()->parse(self::SPAN);
        self::assertIsArray($stmts);
        // Gate-free set = the loader's relocation anchor: same scan the
        // mint leg performs, reproducible from the raw top-level stmts.
        $windows = BlockExtractor::htmlScriptWindows('t.phtml', $stmts);
        self::assertCount(1, $windows);
        $w = $windows[0];
        self::assertSame(2, $w['startLine']);
        self::assertSame(8, $w['endLine']);
        self::assertSame(['slice', 'stmt', 'stmt', 'markup', 'stmt', 'slice'], $w['classes']);
        $blocks = self::scriptBlocks($this->extract(self::SPAN, [BlockExtractor::HTML_SCRIPT_KIND]));
        self::assertSame($blocks[0]->fragmentLineage, $w['classes']);
        self::assertSame($blocks[0]->range->start, $w['startLine']);
        self::assertSame($blocks[0]->range->end, $w['endLine']);
    }

    public function testWireContractLastStatementListsStaysFragmentOnly(): void
    {
        // html_script named, fragment NOT: the worker wire stays empty
        // (MINE-D consumes it — zero behaviour change for it).
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $stmts = $parser->parse(self::SPAN);
        self::assertIsArray($stmts);
        $ex = new BlockExtractor(1, 100000, [BlockExtractor::HTML_SCRIPT_KIND]);
        $blocks = $ex->extract('t.phtml', $stmts);
        self::assertNotEmpty(self::scriptBlocks($blocks));
        self::assertSame([], $ex->lastStatementLists(), 'html_script must not populate the fragment wire');
        // fragment named still populates it exactly as before:
        $ex2 = new BlockExtractor(1, 100000, [BlockExtractor::FRAGMENT_KIND]);
        $ex2->extract('t.phtml', $stmts);
        self::assertNotEmpty($ex2->lastStatementLists());
    }

    public function testLineageClassesHelper(): void
    {
        $markup = new Stmt\InlineHTML('abc', ['startLine' => 1, 'endLine' => 1]);
        $slice = new Stmt\InlineHTML('abc', ['startLine' => 1, 'endLine' => 1, 'phpdupHtmlSlice' => true]);
        $stmts = (new ParserFactory())->createForNewestSupportedVersion()->parse('<?php go();');
        self::assertIsArray($stmts);
        self::assertSame(['markup', 'slice', 'stmt'], BlockExtractor::lineageClasses([$markup, $slice, $stmts[0]]));
    }

    public function testSequenceWeightCurrency(): void
    {
        $run = new Stmt\InlineHTML(str_repeat('x', 100), ['startLine' => 1, 'endLine' => 1]); // ceil(100/32)=4
        $stmts = (new ParserFactory())->createForNewestSupportedVersion()->parse('<?php go();');
        self::assertIsArray($stmts);
        $one = $stmts[0]; // Expression ~ a couple of nodes
        $weight = BlockExtractor::sequenceWeight([$run, $one], static fn (Node $n): int => \Phpdup\Util\AstSerializer::nodeCount($n));
        $self = BlockExtractor::sequenceWeight([$run, $one], static fn (Node $n): int => \Phpdup\Util\AstSerializer::nodeCount($n));
        self::assertSame($weight, $self, 'weight is a pure function of content');
        self::assertSame(1 + 4 + \Phpdup\Util\AstSerializer::nodeCount($one), $weight);
    }
}
