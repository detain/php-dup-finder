<?php

declare(strict_types=1);

namespace Phpdup\Tests\Unit\Extraction;

use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\ParserFactory;
use Phpdup\Census\SubtreeFold;
use Phpdup\Extraction\Block;
use Phpdup\Extraction\BlockExtractor;
use Phpdup\Fingerprint\SubtreeHasher;
use Phpdup\Normalization\Normalizer;
use Phpdup\Util\AstSerializer;
use PHPUnit\Framework\TestCase;

/**
 * Item 7.2b — fold IDENTITY for html_script windows. The mission's crux
 * in three claims:
 *
 *  1. two templates whose inline JS matches cluster ACROSS HTML chrome
 *     variance (byte-identical structuralHash on the window folds);
 *  2. identity holds across runs (fresh parse, fresh extractor, fresh
 *     normalizer — one formula, no run-scoped state);
 *  3. look-alikes do NOT fuse: the escaped entity form differs, and every
 *     normalization mode keeps raw text verbatim (default and aggressive
 *     never touch InlineHTML).
 *
 * ONE hash formula shared by extractor/normalizer/census/cache: all keys
 * are pure functions of AstSerializer::tokens over the fold wrapper.
 */
final class HtmlScriptFoldIdentityTest extends TestCase
{
    private const JS = "\nfunction boot(cfg){return cfg.map(function(x){return x*2;});}\nrender(boot(SETTINGS));\n";

    /**
     * @return list<Block>
     */
    private function windows(string $code): array
    {
        $stmts = (new ParserFactory())->createForNewestSupportedVersion()->parse($code);
        self::assertIsArray($stmts);
        return array_values(array_filter(
            (new BlockExtractor(1, 100000, [BlockExtractor::HTML_SCRIPT_KIND]))->extract('t.phtml', $stmts),
            static fn (Block $b): bool => $b->kind === BlockExtractor::HTML_SCRIPT_KIND
        ));
    }

    /**
     * @param list<Block> $blocks
     * @return list<string>
     */
    private function structuralHashes(array $blocks): array
    {
        $hasher = new SubtreeHasher();
        $out = [];
        foreach ($blocks as $b) {
            (new Normalizer('default'))->normalize($b);
            $out[] = $hasher->hash($b->canonical);
        }
        return $out;
    }

    public function testIdenticalJsClustersAcrossHtmlChrome(): void
    {
        // Acceptance shape of the item (mission point 10), at fold level:
        // same bytes inside <script>, nothing but chrome around them.
        $chromeA = "<div id='shell'>\n<header>nav</header>\n<script>\n" . self::JS . "</script>\n"
            . "<footer>" . str_repeat('tiny', 40) . "</footer>\n</div>\n";
        $chromeB = "<table class='x'><tr><td>\n<script type=\"text/javascript\">\n" . self::JS . "</script>\n</td></tr></table>\n";

        $wa = $this->windows($chromeA);
        $wb = $this->windows($chromeB);
        self::assertCount(1, $wa);
        self::assertCount(1, $wb);

        [$ha] = $this->structuralHashes($wa);
        [$hb] = $this->structuralHashes($wb);
        self::assertSame($ha, $hb, 'identical JS behind different HTML chrome must share the fold key');
        self::assertSame($wa[0]->fragmentLineage, $wb[0]->fragmentLineage);
    }

    public function testBabelWindowsPairAcrossChromeToo(): void
    {
        $a = "<x>\n<script language='text/babel'>\n" . self::JS . "</script>\n</x>\n<y>extra</y>\n";
        $b = "<z>\n<script type=\"text/babel\" data-presets=\"react\">\n" . self::JS . "</script>\n</z>\n";
        $wa = $this->windows($a);
        $wb = $this->windows($b);
        // r1 n-1: guard the premise and pin the SPECIES — a comparison of
        // empty hash lists would pass vacuously if the mint ever died.
        self::assertCount(1, $wa, 'the babel window must actually be minted (language= arm)');
        self::assertCount(1, $wb, 'the babel window must actually be minted (type= arm)');
        self::assertSame(BlockExtractor::HTML_SCRIPT_KIND, $wa[0]->kind);
        self::assertSame(BlockExtractor::HTML_SCRIPT_KIND, $wb[0]->kind);
        self::assertSame(['slice'], $wa[0]->fragmentLineage, 'species pin: the interior is one cut run');
        self::assertSame(['slice'], $wb[0]->fragmentLineage, 'species pin: same lineage, different chrome');
        self::assertSame($this->structuralHashes($wa), $this->structuralHashes($wb),
            'identical babel interior behind different chrome shares the fold key — species pinned above');
    }

    public function testEscapeFormNeverEqualsRealMarkup(): void
    {
        $plain = "<p>here</p>\n<script>\n<h1>Hi</h1>\n</script>\n";
        $entity = "<p>here</p>\n<script>\n&#104;&#101;&#108;&#108;&#111;Hi</h1>\n</script>\n";
        $ha = $this->structuralHashes($this->windows($plain));
        $he = $this->structuralHashes($this->windows($entity));
        self::assertNotSame($ha, $he, 'the document says so: escaped entities are NOT the tag they render to');
    }

    public function testIdentityAcrossRuns(): void
    {
        $code = "<i>\n<script>\n" . self::JS . "</script>\n</i>\n";
        $blocks1 = $this->windows($code);
        // r1 n-1: premise guard — empty-vs-empty would pass vacuously if
        // the mint ever died; pin the species first, then the identity.
        self::assertCount(1, $blocks1, 'the window must actually be minted');
        self::assertSame(BlockExtractor::HTML_SCRIPT_KIND, $blocks1[0]->kind);
        self::assertSame(['slice'], $blocks1[0]->fragmentLineage, 'species pin: cut-run interior');
        $first = $this->structuralHashes($blocks1);
        // Fresh parse, fresh extractor, fresh hasher — no shared state:
        $stmts = (new ParserFactory())->createForNewestSupportedVersion()->parse($code);
        self::assertIsArray($stmts);
        $blocks2 = array_values(array_filter(
            (new BlockExtractor(1, 100000, [BlockExtractor::HTML_SCRIPT_KIND]))->extract('t.phtml', $stmts),
            static fn (Block $b): bool => $b->kind === BlockExtractor::HTML_SCRIPT_KIND
        ));
        self::assertCount(1, $blocks2, 'the second run must mint the same species, not nothing');
        self::assertSame($blocks1[0]->fragmentLineage, $blocks2[0]->fragmentLineage);
        $second = $this->structuralHashes($blocks2);
        self::assertSame($first, $second);
    }

    public function testAllNormalizationModesKeepRawTextVerbatim(): void
    {
        $code = "<i>\n<script>\nvar  x=1 ; // spaced-out JS\n</script>\n</i>\n";
        $blocks = $this->windows($code);
        self::assertCount(1, $blocks);
        $b = $blocks[0];
        self::assertInstanceOf(Stmt\Block::class, $b->ast);
        $rawTokens = AstSerializer::tokens($b->ast);

        $def = clone $b;
        (new Normalizer('default'))->normalize($def);
        self::assertSame($rawTokens, AstSerializer::tokens($def->canonical), 'default mode never touches InlineHTML');

        $agg = clone $b;
        (new Normalizer('aggressive'))->normalize($agg);
        self::assertSame($rawTokens, AstSerializer::tokens($agg->canonical), 'aggressive keeps raw runs verbatim too');

        // 'strict': same promise (only literal canonicalization exists).
        $strict = clone $b;
        (new Normalizer('strict'))->normalize($strict);
        self::assertSame($rawTokens, AstSerializer::tokens($strict->canonical));
    }

    public function testOneHashFormulaOverTheSameStream(): void
    {
        // Extractor fold (SubtreeHasher over canonical), census fold
        // (SubtreeFold over the wrapper) — both pure functions of
        // AstSerializer::tokens: equal streams <=> equal keys, so warm
        // cache, census and pair-score all agree on ONE identity.
        $code = "<i>\n<script>\n" . self::JS . "</script>\n</i>\n";
        $code2 = "<q>\n<script defer>\n" . self::JS . "</script>\n</q>\n";
        $wa = $this->windows($code);
        $wb = $this->windows($code2);
        $ta = AstSerializer::tokens($wa[0]->ast);
        $tb = AstSerializer::tokens($wb[0]->ast);
        self::assertSame($ta, $tb, 'window interiors carry identical token streams');

        $foldA = SubtreeFold::hashPair($wa[0]->ast);
        $foldB = SubtreeFold::hashPair($wb[0]->ast);
        self::assertSame($foldA['exact'], $foldB['exact']);
        self::assertSame($foldA['abstract'], $foldB['abstract']);

        $pair = $this->structuralHashes([$wa[0]]);
        $pair2 = $this->structuralHashes([$wb[0]]);
        self::assertSame($pair, $pair2);

        // And the discrimination side: ONE byte different flips every key.
        $wMod = $this->windows("<i>\n<script>\n" . self::JS . "\n" . "</script>\n</i>\n");
        $tMod = AstSerializer::tokens($wMod[0]->ast);
        self::assertNotSame($ta, $tMod);
        self::assertNotSame($foldA['exact'], SubtreeFold::hashExact($wMod[0]->ast));
        self::assertNotSame($pair, $this->structuralHashes($wMod));
    }

    public function testWindowedScanningBoundsEveryMember(): void
    {
        // 60KB script body: fold members are capped slices, and the whole
        // scan is windowed (never the entire template file's bytes).
        $body = str_repeat("// pad\n", 6000);
        $code = "<i>chrome chrome chrome</i>\n<script>\n" . $body . "</script>\n<i>tail</i>\n";
        $blocks = $this->windows($code);
        self::assertCount(1, $blocks);
        $b = $blocks[0];
        self::assertInstanceOf(Stmt\Block::class, $b->ast);
        $seen = 0;
        foreach ($b->ast->stmts as $m) {
            self::assertInstanceOf(Stmt\InlineHTML::class, $m);
            self::assertLessThanOrEqual(BlockExtractor::SCRIPT_TEXT_WINDOW_BYTES, strlen($m->value));
            $seen += strlen($m->value);
        }
        self::assertSame(strlen("\n" . $body), $seen, 'only the window interior is carried — chrome bytes stay outside the fold');
        $again = $this->windows($code);
        self::assertSame($this->structuralHashes([$b]), $this->structuralHashes($again), 'slicing is deterministic across runs');
    }

    public function testLineageParallelismHoldsForMixedFolds(): void
    {
        // A window that spans an escape keeps 'stmt' members among the
        // slices — the loader's positional match relies on the lists
        // being parallel, list-index by list-index.
        $code = "<i>\n<script>\nvar a=1;\n<?php \$mid = go(1,2,3); ?>\nvar b=2;\n</script>\n</i>\n";
        $blocks = $this->windows($code);
        self::assertCount(1, $blocks);
        $b = $blocks[0];
        self::assertSame(BlockExtractor::lineageClasses($b->ast instanceof Stmt\Block ? $b->ast->stmts : []), $b->fragmentLineage);
        self::assertContains('stmt', $b->fragmentLineage);
        self::assertContains('slice', $b->fragmentLineage);
        foreach ($b->ast instanceof Stmt\Block ? $b->ast->stmts : [] as $i => $m) {
            self::assertInstanceOf($b->fragmentLineage[$i] === 'stmt' ? Node\Stmt::class : Stmt\InlineHTML::class, $m);
        }
    }
}
