<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Refactor;

use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Extraction\BlockExtractor;
use Phpdup\Parsing\AstCache;
use Phpdup\Parsing\AstParser;
use Phpdup\Refactor\ApplyExtractor;
use Phpdup\Refactor\HtmlFragmentApplyRefusal;
use Phpdup\Reporting\RefactorPatchReporter;
use Phpdup\Reporting\Report;

/**
 * Item 7.2b — html_script clusters are detection-only in v1, the exact
 * apply-refusal family contract 1.3 gave 'script', 1.2 gave 'window' and
 * 2.1 gave 'fragment': every AST-addressing action surface refuses loudly,
 * zero writes. The NEW twist, pinned alongside: the loader half is a
 * SERVE, not a refusal (positional re-derivation is safe for this species
 * — see BlockAstLoaderHtmlScriptTest); what is refused is the ACTION,
 * never the evidence.
 */
final class HtmlFragmentApplyRefusalTest extends TestCase
{
    private const TEMPLATE = "<i>\n<script>\nvar a = 1;\n"
        . "<?php \$mid = go(1, 2, 3); ?>\n"
        . "var b = 2;\n</script>\n</i>\n";

    /** @return list<Block> */
    private function foldBlocks(): array
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ast = $parser->parse(self::TEMPLATE) ?? [];
        $blocks = (new BlockExtractor(1, 800, [BlockExtractor::HTML_SCRIPT_KIND]))->extract('t.phtml', $ast);
        $folds = array_values(array_filter($blocks, static fn (Block $b): bool => $b->kind === BlockExtractor::HTML_SCRIPT_KIND));
        $this->assertNotSame([], $folds, 'fixture must mint the fold');
        return $folds;
    }

    public function testApplyExtractorRefusesHtmlScriptCluster(): void
    {
        $folds = $this->foldBlocks();
        $ordinary = new Block('t.phtml', $folds[0]->range, 'function', null, null, 'a', $folds[0]->ast);
        $cluster = new Cluster('C-h1', [$folds[0], $ordinary], 1.0, false);
        $tmp = sys_get_temp_dir() . '/phpdup-href-' . bin2hex(random_bytes(4));
        try {
            (new ApplyExtractor())->apply($cluster, $tmp . '/out');
            $this->fail('html_script clusters must be refused, not silently skipped');
        } catch (HtmlFragmentApplyRefusal $e) {
            $this->assertStringContainsString('ApplyExtractor', $e->getMessage());
            $this->assertStringContainsString('html_script', $e->getMessage());
            $this->assertStringContainsString('1 html_script-kind member', $e->getMessage());
        }
        $this->assertDirectoryDoesNotExist($tmp . '/out', 'refusal happens before any write');
    }

    public function testRefactorPatchReporterRefusesTheSurface(): void
    {
        $folds = $this->foldBlocks();
        $root = sys_get_temp_dir() . '/phpdup-hrep-' . bin2hex(random_bytes(4));
        $report = new Report(
            files: 1, blocks: 1, parseErrors: 0,
            clusters: [new Cluster('C-h2', [$folds[0]], 1.0, false)],
            config: Config::defaults([$root]),
        );
        $out = $root . '/patches';
        try {
            (new RefactorPatchReporter())->writeTo($report, $out);
            $this->fail('patch surface must refuse the mixed-language fold');
        } catch (HtmlFragmentApplyRefusal $e) {
            $this->assertStringContainsString('RefactorPatchReporter', $e->getMessage());
            $this->assertStringContainsString('C-h2', $e->getMessage());
        }
        $this->assertDirectoryDoesNotExist($out);
    }

    public function testForBlockMessageCarriesTheServeLaw(): void
    {
        $fold = $this->foldBlocks()[0];
        $e = HtmlFragmentApplyRefusal::forBlock($fold, 'TestSurface');
        $this->assertStringContainsString('--kinds html_script', $e->getMessage());
        $this->assertStringContainsString('re-derived from raw text', $e->getMessage());
        $this->assertStringContainsString('t.phtml', $e->getMessage());
    }

    public function testUnionRosterAdmitsTheNewFamilyMember(): void
    {
        // The typed-halt render path is a catch UNION (Command::execute,
        // WatchRunner::runGuarded) — membership is the contract. Pin it at
        // source level so a refactor that rewrites the union loudly breaks
        // this test instead of silently routing the refusal to a dump.
        $command = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Cli/Command.php');
        $watch = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Watch/WatchRunner.php');
        $this->assertMatchesRegularExpression(
            '/catch \([^)]*HtmlFragmentApplyRefusal[^)]*\$e\)/',
            $command,
            'Command catch union must include the 7.2b refusal',
        );
        $this->assertMatchesRegularExpression(
            '/catch \([^)]*HtmlFragmentApplyRefusal[^)]*\$e\)/',
            $watch,
            'WatchRunner runGuarded union must include the 7.2b refusal',
        );
    }

    public function testLoaderIsTheServingSurfaceNotAFourthRefusal(): void
    {
        // Contrast pin (the design's departure from script/window/
        // fragment): an unloaded fold whose FILE STILL CARRIES IT is
        // served, and no refusal class fires. Refusal stays the rare path
        // (bytes moved) — pinned positively in BlockAstLoaderHtmlScriptTest.
        $dir = sys_get_temp_dir() . '/phpdup-hserve-' . bin2hex(random_bytes(4));
        mkdir($dir, 0o700, true);
        $path = $dir . '/f.phtml';
        file_put_contents($path, self::TEMPLATE);
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ast = $parser->parse(self::TEMPLATE) ?? [];
        $folds = array_values(array_filter(
            (new BlockExtractor(1, 800, [BlockExtractor::HTML_SCRIPT_KIND]))->extract($path, $ast),
            static fn (Block $b): bool => $b->kind === BlockExtractor::HTML_SCRIPT_KIND,
        ));
        $fold = $folds[0];
        $fold->unloadAst();
        $loader = new \Phpdup\Extraction\BlockAstLoader(new AstCache($dir . '/ast'), new AstParser(), new Config(paths: [$dir], exclude: []));
        $served = $loader->resolve($fold);
        $this->assertNotNull($served, 'the fold family SERVES re-derivation where its siblings refuse');
        $this->assertInstanceOf(\PhpParser\Node\Stmt\Block::class, $served);
        $this->assertSame($fold->fragmentLineage, BlockExtractor::lineageClasses($served->stmts));
        @unlink($path);
        @rmdir($dir);
    }
}
