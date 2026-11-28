<?php

declare(strict_types=1);

namespace Phpdup\Tests\Unit\Extraction;

use PhpParser\Node\Stmt;
use PhpParser\ParserFactory;
use Phpdup\Cli\Config;
use Phpdup\Extraction\Block;
use Phpdup\Extraction\BlockAstLoader;
use Phpdup\Extraction\BlockExtractor;
use Phpdup\Fingerprint\SubtreeHasher;
use Phpdup\Normalization\Normalizer;
use Phpdup\Parsing\AstCache;
use Phpdup\Parsing\AstParser;
use Phpdup\Persistence\IndexStore;
use Phpdup\Refactor\HtmlFragmentApplyRefusal;
use Phpdup\Util\AstSerializer;
use PHPUnit\Framework\TestCase;

/**
 * Item 7.2b — the BlockAstLoader html_script arm: the fold species the
 * other synthetics REFUSE is the one the loader can SERVE, because
 * Block::$fragmentLineage makes positional re-derivation from raw bytes
 * honest. Pinned here: serve-on-identity (tokens byte-equal after
 * unload/reload), content-gate refusal when bytes move under a held
 * position, typed refusal when the fold disappears, idempotence, and the
 * belt that html_script never joins the CANDIDATE_KINDS / matchesKind
 * parse-tree rosters (it is a synthetic fold, served only by its own arm).
 */
final class BlockAstLoaderHtmlScriptTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/t1a-loader-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0o700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/**/*') ?: [] as $f) {
            @unlink($f);
        }
        foreach (array_reverse(glob($this->dir . '/*') ?: []) as $d) {
            @rmdir($d);
            @unlink($d);
        }
        @rmdir($this->dir);
    }

    private function writeTemplate(string $name, string $code): string
    {
        $path = $this->dir . '/' . $name;
        file_put_contents($path, $code);
        return $path;
    }

    /**
     * @return list<Block>
     */
    private function mint(string $path, int $minSize = 1): array
    {
        $stmts = (new ParserFactory())->createForNewestSupportedVersion()->parse(file_get_contents($path));
        self::assertIsArray($stmts);
        return array_values(array_filter(
            (new BlockExtractor($minSize, 100000, [BlockExtractor::HTML_SCRIPT_KIND]))->extract($path, $stmts),
            static fn (Block $b): bool => $b->kind === BlockExtractor::HTML_SCRIPT_KIND
        ));
    }

    private function loader(?Config $config = null): BlockAstLoader
    {
        return new BlockAstLoader(new AstCache($this->dir . '/ast-' . bin2hex(random_bytes(3))), new AstParser(), $config);
    }

    private const TEMPLATE = "<div id='shell'>\n<script>\nvar a = 1;\n"
        . "<?php \$mid = go(1, 2, 3); ?>\n"
        . "var b = 2;\n</script>\n</div>\n";

    public function testUnloadServeIsTokenIdentical(): void
    {
        $path = $this->writeTemplate('a.phtml', self::TEMPLATE);
        [$block] = $this->mint($path);
        $before = AstSerializer::tokens($block->ast);
        $block->unloadAst();
        self::assertTrue($block->isAstUnloaded(), 'lazyAst unloads this species — the arm is the live path');

        $served = $this->loader()->resolve($block);
        self::assertNotNull($served);
        self::assertInstanceOf(Stmt\Block::class, $served);
        self::assertSame($before, AstSerializer::tokens($served), 're-derivation must serve the byte-identical fold');
        self::assertSame($block->fragmentLineage, BlockExtractor::lineageClasses($served instanceof Stmt\Block ? $served->stmts : []));
        // Idempotence: served blocks carry their AST — second resolve is the fast path.
        self::assertSame($served, $this->loader()->resolve($block));
    }

    public function testContentGateRefusesDriftUnderAHeldPosition(): void
    {
        $path = $this->writeTemplate('b.phtml', self::TEMPLATE);
        [$block] = $this->mint($path);
        $config = Config::defaults([$path]);
        (new Normalizer($config->normalizationMode))->normalize($block);
        $block->structuralHash = (new SubtreeHasher())->hash($block->canonical);

        // Same line geometry, same lineage, ONE byte of content moved:
        // position alone must not serve it (the [M-1] class of hazard).
        $edited = str_replace('var a = 1;', 'var a = 2;', self::TEMPLATE);
        file_put_contents($path, $edited);
        $block->unloadAst();

        $this->expectException(HtmlFragmentApplyRefusal::class);
        $this->loader($config)->resolve($block);
    }

    public function testUnconfiguredLoaderServesPositionalMatchWithoutHash(): void
    {
        // No config threaded (synthetic callers/fixtures): the positional
        // (range+lineage+context) match is the contract — pin that the
        // gate is opt-in, not a hard dependency.
        $path = $this->writeTemplate('c.phtml', self::TEMPLATE);
        [$block] = $this->mint($path);
        $block->unloadAst();
        self::assertNotNull($this->loader()->resolve($block));
    }

    public function testMovedTemplateRefusesTyped(): void
    {
        $path = $this->writeTemplate('d.phtml', self::TEMPLATE);
        [$block] = $this->mint($path);
        $block->unloadAst();
        file_put_contents($path, "<div>everything replaced\n<footer>totally different bytes here</footer>\n</div>\n");

        try {
            $this->loader()->resolve($block);
            self::fail('expected typed refusal');
        } catch (HtmlFragmentApplyRefusal $e) {
            self::assertStringContainsString('html_script', $e->getMessage());
            self::assertStringContainsString('--kinds html_script', $e->getMessage());
            self::assertStringContainsString($path, $e->getMessage());
        }
    }

    public function testFoldsNeverEnterTheParseTreeCandidateRosters(): void
    {
        // Belt (ScriptKindTest pattern): the positional machinery must
        // never treat html_script as a relocatable parse-tree kind — the
        // dedicated derive arm is the ONLY serving surface.
        $ref = new \ReflectionClass(BlockAstLoader::class);
        $candidates = $ref->getConstant('CANDIDATE_KINDS');
        self::assertIsArray($candidates);
        self::assertNotContains(BlockExtractor::HTML_SCRIPT_KIND, $candidates);

        $matches = new \ReflectionMethod(BlockAstLoader::class, 'matchesKind');
        $probe = new Stmt\Block([], ['startLine' => 1, 'endLine' => 2]);
        self::assertFalse($matches->invoke(null, $probe, BlockExtractor::HTML_SCRIPT_KIND), 'matchesKind default-false must hold for the new kind');
    }

    public function testRebaseServesUnloadedFoldMembers(): void
    {
        // The warm-replay surface: an unloaded html_script member of a
        // cluster is re-derived (not thrown at, not passed to the
        // content-ledger), and a lost fold still refuses typed.
        $path = $this->writeTemplate('e.phtml', self::TEMPLATE);
        [$block] = $this->mint($path);
        $block->unloadAst();

        $cluster = new \Phpdup\Clustering\Cluster('t1a-e', [$block], 1.0, true);
        $loader = $this->loader();
        // freshScan=false is the warm posture; set via non-public state
        // only through construction: a loader that parsed nothing this
        // process starts with freshScan false (engine-040 replay shape).
        $loader->rebaseClusters([$cluster], []);
        self::assertFalse($block->isAstUnloaded(), 'warm replay served the fold back');
        self::assertInstanceOf(Stmt\Block::class, $block->ast);

        $block2 = $this->mint($path)[0];
        $block2->unloadAst();
        $block2->range = new \Phpdup\Util\LineRange(40, 44); // stale position
        $cluster2 = new \Phpdup\Clustering\Cluster('t1a-e2', [$block2], 1.0, true);
        $this->expectException(HtmlFragmentApplyRefusal::class);
        $this->loader()->rebaseClusters([$cluster2], []);
    }

    // ── r1 m-2: the WARM BLOB hop — coverage parity with the fragment
    //    species' FragmentCacheKeyReplayTest. The serve/refusal pins above
    //    exercise in-memory unloaded blocks; these cross an actual
    //    IndexStore file so the Block::$fragmentLineage hop itself is
    //    proven: serialize carries it, load hands it back verbatim, and
    //    the derive arm serves the post-hop block (or refuses a lineage
    //    the hop never carried) exactly as it does the in-memory ones.

    public function testIndexStoreKeyBindsTheHtmlScriptRoster(): void
    {
        // Layer-1 mirror of the fragment template: naming html_script
        // reshapes the block set, so the persisted blob must never serve
        // a default-roster run (or vice versa) — the key alone fences it.
        $stage = new \Phpdup\Pipeline\Stages\PreprocessStage(useCache: true);
        $keyOf = new \ReflectionMethod(\Phpdup\Pipeline\Stages\PreprocessStage::class, 'indexStoreConfigKey');

        $on  = new Config(paths: ['/x'], exclude: [], allowedKinds: ['html_script']);
        $off = new Config(paths: ['/x'], exclude: [], allowedKinds: []);

        self::assertNotSame($keyOf->invoke($stage, $on), $keyOf->invoke($stage, $off),
            'html_script minting reshapes the block set — the IndexStore key must bind the roster');
        self::assertSame($keyOf->invoke($stage, $on), $keyOf->invoke($stage, $on),
            'non-vacuity: the key is a pure function of the config');
    }

    public function testWarmBlobRoundTripServesTheDerivedFold(): void
    {
        $path = $this->writeTemplate('f.phtml', self::TEMPLATE);
        [$block] = $this->mint($path);
        $before = AstSerializer::tokens($block->ast);
        $lineage = $block->fragmentLineage;
        self::assertNotSame([], $lineage, 'premise: a real fold carries its lineage');
        $block->unloadAst();

        $dir = $this->dir . '/idx';
        mkdir($dir, 0o700, true);
        (new IndexStore($dir, 't1a-html-script'))->save($path, [$block]);

        $reloaded = (new IndexStore($dir, 't1a-html-script'))->load($path);
        self::assertIsArray($reloaded);
        self::assertCount(1, $reloaded);
        $hop = $reloaded[0];
        self::assertSame($lineage, $hop->fragmentLineage, 'the lineage must ride serialize verbatim');
        self::assertSame($block->range->start, $hop->range->start);
        self::assertSame($block->range->end, $hop->range->end);
        self::assertTrue($hop->isAstUnloaded());

        $served = $this->loader()->resolve($hop);
        self::assertInstanceOf(Stmt\Block::class, $served);
        self::assertSame($before, AstSerializer::tokens($served),
            'post-hop re-derivation serves the pre-hop fold byte-identically');
    }

    public function testWarmBlobCarriesLoadedFoldMembers(): void
    {
        // The lazyAst=false leg: the fold wrapper itself rides the blob.
        // Sliced InlineHTML members (incl. the phpdupHtmlSlice marker
        // attribute) are Node subclasses / scalar attributes, so the
        // allow-list carries them — an allow-list gap would surface here
        // as a corrupt-blob refusal, not a silent degradation.
        $path = $this->writeTemplate('g.phtml', self::TEMPLATE);
        [$block] = $this->mint($path);
        $before = AstSerializer::tokens($block->ast);

        $dir = $this->dir . '/idx-loaded';
        mkdir($dir, 0o700, true);
        (new IndexStore($dir, 't1a-html-script'))->save($path, [$block]);

        $reloaded = (new IndexStore($dir, 't1a-html-script'))->load($path);
        self::assertIsArray($reloaded);
        $hop = $reloaded[0];
        self::assertFalse($hop->isAstUnloaded(), 'the loaded fold survives the hop as a loaded fold');
        self::assertInstanceOf(Stmt\Block::class, $hop->ast);
        self::assertSame($before, AstSerializer::tokens($hop->ast));
        self::assertSame($block->fragmentLineage, $hop->fragmentLineage);
    }

    public function testLineagelessLegacyFoldRefusesFailClosed(): void
    {
        // Pre-hop warm shape: a blob whose html_script block arrives with
        // NO lineage (a field the writer never emitted restores as the
        // class default []). Position may match the file perfectly — the
        // derive arm still demands exact lineage, so the block is refused
        // typed rather than blind-served (the TokenCacheTest
        // legacy-never-satisfies-the-reader precedent shape, :117/:328).
        $path = $this->writeTemplate('h.phtml', self::TEMPLATE);
        [$block] = $this->mint($path);
        $block->unloadAst();
        $block->fragmentLineage = [];

        $dir = $this->dir . '/idx-legacy';
        mkdir($dir, 0o700, true);
        (new IndexStore($dir, 't1a-legacy'))->save($path, [$block]);
        $reloaded = (new IndexStore($dir, 't1a-legacy'))->load($path);
        self::assertIsArray($reloaded);
        $hop = $reloaded[0];
        self::assertSame([], $hop->fragmentLineage);

        $this->expectException(HtmlFragmentApplyRefusal::class);
        $this->loader()->resolve($hop);
    }
}
