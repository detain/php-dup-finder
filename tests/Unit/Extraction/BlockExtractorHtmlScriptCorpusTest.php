<?php

declare(strict_types=1);

namespace Phpdup\Tests\Unit\Extraction;

use PhpParser\ParserFactory;
use Phpdup\Extraction\Block;
use Phpdup\Extraction\BlockExtractor;
use Phpdup\Fingerprint\SubtreeHasher;
use Phpdup\Normalization\Normalizer;
use PHPUnit\Framework\TestCase;

/**
 * Item 7.2b — corpus acceptance on the real tests/Fixtures/html-script/
 * .phtml specimens (the mission's tenth point, pinned at unit level; the
 * pipeline-level twin rides tests/Integration):
 *
 *  - two templates whose inline JS matches cluster ACROSS HTML chrome
 *    variance (byte-identical structuralHash on the window folds);
 *  - the babel pair (language= vs type= opener) fuses identically;
 *  - the escaped-window specimen proves the fold is a REAL mixed
 *    sequence: short-echo and php-block statements ride inside the script
 *    window as stmt members while raw markup rides as markup/slice
 *    sentinels, positionally parallel in Block::$fragmentLineage;
 *  - external <script src=...> contributes NO window;
 *  - the default roster stays inert: no html_script block is ever minted
 *    without explicit opt-in (never-implicit-at-mint law).
 */
final class BlockExtractorHtmlScriptCorpusTest extends TestCase
{
    private const DIR = __DIR__ . '/../../Fixtures/html-script';

    /**
     * @return list<Block>
     */
    private function mint(string $fixture, int $minSize = 1, int $maxSize = 100000, array $roster = [BlockExtractor::HTML_SCRIPT_KIND]): array
    {
        $path = self::DIR . '/' . $fixture;
        $code = file_get_contents($path);
        self::assertIsString($code, $fixture . ' must exist');
        $stmts = (new ParserFactory())->createForNewestSupportedVersion()->parse($code);
        self::assertIsArray($stmts, $fixture . ' must parse clean');

        return array_values(array_filter(
            (new BlockExtractor($minSize, $maxSize, $roster))->extract($fixture, $stmts),
            static fn (Block $b): bool => $b->kind === BlockExtractor::HTML_SCRIPT_KIND
        ));
    }

    private function hash(Block $b): string
    {
        (new Normalizer('default'))->normalize($b);

        return (new SubtreeHasher())->hash($b->canonical);
    }

    public function testChromePairMintsIdenticalFoldAcrossHtmlVariance(): void
    {
        $a = $this->mint('chrome-a.phtml');
        $b = $this->mint('chrome-b.phtml');
        self::assertCount(1, $a);
        self::assertCount(1, $b);
        self::assertSame($a[0]->range->start, 15);
        self::assertSame($b[0]->range->start, 23);
        self::assertNotSame($a[0]->range->start, $b[0]->range->start, 'chrome shifts the window position');
        self::assertSame($this->hash($a[0]), $this->hash($b[0]), 'identical JS must fuse across HTML variance');
        self::assertSame(['slice'], $a[0]->fragmentLineage);
        self::assertSame(['slice'], $b[0]->fragmentLineage);
    }

    public function testChromePairSurvivesProductionMinSize(): void
    {
        $a = $this->mint('chrome-a.phtml', 8, 800);
        self::assertCount(1, $a, 'weight 10 clears the default min block size');
        self::assertGreaterThanOrEqual(8, $a[0]->size);
    }

    public function testBabelPairFusesDespiteDifferingTagForms(): void
    {
        $a = $this->mint('babel-a.phtml');
        $b = $this->mint('babel-b.phtml');
        self::assertCount(1, $a);
        self::assertCount(1, $b);
        self::assertSame($this->hash($a[0]), $this->hash($b[0]), 'language= vs type= openers are outside the window body');
    }

    public function testEscapedWindowCarriesRealStatementMembers(): void
    {
        $blocks = $this->mint('escaped-window.phtml');
        self::assertCount(1, $blocks, 'the external <script src=...> must contribute no window');
        $w = $blocks[0];
        self::assertSame(12, $w->range->start);
        self::assertSame(22, $w->range->end);
        self::assertSame(
            ['slice', 'stmt', 'stmt', 'markup', 'stmt', 'markup', 'stmt', 'slice'],
            $w->fragmentLineage,
            'php-block assignments and short-echoes ride as stmt members, wholly-inside runs as markup, cut runs as slice'
        );

        // The wrapper must actually contain the real statements: two
        // assignments + two echo statements between raw-text sentinels.
        self::assertIsObject($w->ast);
        $kinds = array_map(static fn (object $n): string => $n::class, $w->ast->stmts);
        self::assertSame(
            ['slice', 'stmt', 'stmt', 'markup', 'stmt', 'markup', 'stmt', 'slice'],
            $w->fragmentLineage
        );
        self::assertSame(8, count($kinds));
        $stmtNodes = array_values(array_filter($kinds, static fn (string $c): bool => $c !== \PhpParser\Node\Stmt\InlineHTML::class));
        self::assertCount(4, $stmtNodes, 'exactly four real PHP statements live inside the window');
    }

    public function testEscapedWindowDoesNotFuseWithChromeSpecimens(): void
    {
        $escaped = $this->hash($this->mint('escaped-window.phtml')[0]);
        $chrome = $this->hash($this->mint('chrome-a.phtml')[0]);
        self::assertNotSame($escaped, $chrome);
    }

    public function testDefaultRosterNeverMintsHtmlScript(): void
    {
        foreach (['chrome-a.phtml', 'chrome-b.phtml', 'babel-a.phtml', 'babel-b.phtml', 'escaped-window.phtml'] as $fixture) {
            $blocks = $this->mint($fixture, 8, 800, []);
            self::assertCount(0, $blocks, $fixture . ' must stay inert under the default roster');
        }
    }
}
