<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Refactor;

use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Extraction\BlockAstLoader;
use Phpdup\Extraction\BlockExtractor;
use Phpdup\Parsing\AstCache;
use Phpdup\Parsing\AstParser;
use Phpdup\Refactor\ApplyExtractor;
use Phpdup\Refactor\FragmentApplyRefusal;

/**
 * Item 2.1 — fragment clusters are detection-only v1 (the doc's same
 * contract 1.3 gave 'script' and 1.2 gave 'window'): every extraction/
 * apply surface REFUSES a fragment member loudly. The runs are synthetic
 * cuts across declaration boundaries — there is no relocation half, so
 * the loader must refuse them too (backstop posture; the pipeline never
 * unloads fragment ASTs).
 */
final class FragmentApplyRefusalTest extends TestCase
{
    /** @return list<Block> */
    private function fragmentBlocks(): array
    {
        $src = "<?php\nfunction a() {\n    \$r = q(\$v);\n    \$s = w(\$r);\n    e(\$s);\n    return \$s;\n}\n"
             . "function b() {\n    \$r = q(\$v);\n    \$s = w(\$r);\n    e(\$s);\n    return \$s;\n}\n";
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ast = $parser->parse($src) ?? [];
        $blocks = (new BlockExtractor(
            1,
            800,
            ['function', 'method', BlockExtractor::FRAGMENT_KIND],
            false,
        ))->extract('frag.php', $ast);
        $frags = array_values(array_filter($blocks, static fn (Block $b): bool => $b->kind === BlockExtractor::FRAGMENT_KIND));
        $this->assertNotSame([], $frags, 'fixture must mint fragments');
        return $frags;
    }

    public function testApplyExtractorRefusesFragmentCluster(): void
    {
        $frags = $this->fragmentBlocks();
        $cluster = new Cluster('C-f1', [$frags[0], $frags[1]], 1.0, false);
        $tmp = sys_get_temp_dir() . '/phpdup-fref-' . bin2hex(random_bytes(4));
        try {
            (new ApplyExtractor())->apply($cluster, $tmp . '/out');
            $this->fail('fragment clusters must be refused, not silently skipped');
        } catch (FragmentApplyRefusal $e) {
            $this->assertStringContainsString('ApplyExtractor', $e->getMessage());
            $this->assertStringContainsString('fragment', $e->getMessage());
        }
        $this->assertDirectoryDoesNotExist($tmp . '/out', 'refusal happens before any write');
    }

    public function testLoaderResolveRefusesUnloadedFragment(): void
    {
        // resolve() only loads blocks whose AST was dropped; a loaded
        // fragment passes through by design. The refusal is the backstop
        // for an UNLOADED fragment — whose synthetic wrapper cannot be
        // relocated (the pipeline never unloads fragments; the loader
        // never silently guesses).
        $frag = $this->fragmentBlocks()[0];
        $frag->unloadAst();
        $loader = new BlockAstLoader(
            new AstCache(sys_get_temp_dir() . '/phpdup-fref-' . bin2hex(random_bytes(4))),
            new AstParser(),
            new Config(paths: ['/x'], exclude: []),
        );
        $this->expectException(FragmentApplyRefusal::class);
        $this->expectExceptionMessage('resolve');
        $loader->resolve($frag);
    }

    public function testLoaderRebaseRefusesUnloadedFragment(): void
    {
        $frag = $this->fragmentBlocks()[0];
        $frag->structuralHash = 'whatever-was-cached';
        $frag->unloadAst();
        $cluster = new Cluster('C-f2', [$frag], 1.0, false);
        $loader = new BlockAstLoader(
            new AstCache(sys_get_temp_dir() . '/phpdup-fref-' . bin2hex(random_bytes(4))),
            new AstParser(),
            new Config(paths: ['/x'], exclude: []),
        );
        $this->expectException(FragmentApplyRefusal::class);
        $this->expectExceptionMessage('rebaseClusters');
        $loader->rebaseClusters([$cluster], []);
    }

    public function testForClusterCountsOnlyFragmentMembers(): void
    {
        $frags = $this->fragmentBlocks();
        $ordinary = new Block('frag.php', $frags[0]->range, 'function', null, null, 'a', $frags[0]->ast);
        $e = FragmentApplyRefusal::forCluster(new Cluster('C-f3', [$frags[0], $ordinary], 1.0, false), 'TestSurface');
        $this->assertStringContainsString('fragment', $e->getMessage());
        $this->assertStringContainsString('TestSurface', $e->getMessage());
    }
}
