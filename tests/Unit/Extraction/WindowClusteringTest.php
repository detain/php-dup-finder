<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Extraction;

use PhpParser\Node\Stmt;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Extraction\BlockAstLoader;
use Phpdup\Extraction\BlockExtractor;
use Phpdup\Parsing\AstCache;
use Phpdup\Parsing\AstParser;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\Stages\ClusterStage;
use Phpdup\Pipeline\Stages\PreprocessStage;
use Phpdup\Pipeline\Stages\ScanningStage;
use Phpdup\Refactor\ApplyExtractor;
use Phpdup\Refactor\WindowApplyRefusal;
use Phpdup\Reporting\RefactorPatchReporter;
use Phpdup\Reporting\Report;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Item 1.2 end-to-end (E2) — windows are not an extraction curiosity:
 * they ride the fingerprint/cluster stream, so a pair of > max_block_size
 * functions that historically vanished NOW FORMS A CLUSTER; and the
 * synthetic kind is refused at every AST-addressing surface, mirroring
 * the 1.3 script-fold precedent point for point.
 *
 * The off-posture arm pins the item 0.6 feed: the same corpus under
 * window_oversized=false yields zero edges and counts both parents in
 * PipelineState::$oversizedBlocks.
 */
final class WindowClusteringTest extends TestCase
{
    private string $tmp = '';

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/wc-' . bin2hex(random_bytes(6));
        mkdir($this->tmp, 0o775, true);
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->tmp);
    }

    /**
     * Two files carrying the SAME 400-assignment body under different
     * function names: >800 nodes each, historically both dropped at the
     * extraction ceiling — the tool could never see this clone at all.
     */
    private function plantTwinGiants(): void
    {
        $body = '';
        for ($i = 0; $i < 400; ++$i) {
            $body .= "    \$x = {$i};\n";
        }
        file_put_contents($this->tmp . '/a.php', "<?php\nfunction big_alpha() {\n{$body}}\n");
        file_put_contents($this->tmp . '/b.php', "<?php\nfunction big_beta() {\n{$body}}\n");
    }

    /** @return array{0: PipelineState, 1: list<Cluster>} the driven state */
    private function drivePipeline(bool $windowing): PipelineState
    {
        $config = new Config(
            paths:      [$this->tmp],
            exclude:    Config::defaults([])->exclude,
            windowOversized: $windowing,
        );
        $state = new PipelineState($config);
        $out   = new NullOutput();
        (new ScanningStage())->run($state, $out);
        (new PreprocessStage(useCache: false))->run($state, $out);
        (new ClusterStage(exactOnly: false))->run($state, $out);
        return $state;
    }

    // ── the headline leg: oversized clones now form edges ───────────────

    public function testTwinGiantFunctionsFormAClusterOfWindows(): void
    {
        $this->plantTwinGiants();
        $state = $this->drivePipeline(windowing: true);

        $this->assertNotEmpty($state->clusters, 'windows must make the historically-invisible clone discoverable');
        $this->assertSame(0, $state->oversizedBlocks, 'salvaged parents are not drops — the counter stays at zero');

        $windowMembers = 0;
        foreach ($state->clusters as $cluster) {
            foreach ($cluster->members as $member) {
                if ($member->kind === BlockExtractor::WINDOW_KIND) {
                    ++$windowMembers;
                }
            }
        }
        $this->assertGreaterThan(1, $windowMembers, 'the cluster carries window members from BOTH files');
    }

    public function testWindowedMembersKeepTheirAstAcrossTheLazyUnload(): void
    {
        // The unload guards (worker + ClusterStage) never strip a window:
        // a synthetic cut has no parse-tree counterpart to reload from —
        // never unload what can never be reloaded.
        $this->plantTwinGiants();
        $state = $this->drivePipeline(windowing: true);

        $this->assertNotEmpty($state->blocks);
        foreach ($state->blocks as $block) {
            if ($block->kind === BlockExtractor::WINDOW_KIND) {
                $this->assertFalse($block->isAstUnloaded(), 'window ASTs survive the lazy unload');
            }
        }
    }

    public function testOffPostureCountsTheSameCorpusAndFindsNoEdges(): void
    {
        // The byte-identity escape hatch, pinned behaviorally: with
        // windowing off the giants drop exactly as pre-1.2 — zero
        // clusters, and BOTH parents counted in blocks_oversized.
        $this->plantTwinGiants();
        $state = $this->drivePipeline(windowing: false);

        $this->assertSame([], $state->clusters);
        $this->assertSame(2, $state->oversizedBlocks);
    }

    // ── the refusal posture: windows never reach AST-addressing work ────

    /** @return list<Block> windows from one virtual giant function */
    private function windowBlocks(): array
    {
        $lines = [];
        for ($i = 0; $i < 400; ++$i) {
            $lines[] = "    \$x = {$i};";
        }
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ast    = $parser->parse("<?php\nfunction giant() {\n" . implode("\n", $lines) . "\n}\n") ?? [];
        $blocks = (new BlockExtractor(8, 800, [], true))->extract('virtual.php', $ast);
        $this->assertNotSame([], $blocks, 'fixture must yield windows');
        return $blocks;
    }

    public function testApplyExtractorRefusesWindowCluster(): void
    {
        $members = $this->windowBlocks();
        $cluster = new Cluster('C-w1', [$members[0], $members[count($members) - 1]], 1.0, false);
        try {
            (new ApplyExtractor())->apply($cluster, $this->tmp . '/out');
            $this->fail('window clusters must be refused, not silently skipped');
        } catch (WindowApplyRefusal $e) {
            $this->assertStringContainsString('ApplyExtractor', $e->getMessage());
            $this->assertStringContainsString('window', $e->getMessage());
        }
        $this->assertDirectoryDoesNotExist($this->tmp . '/out', 'refusal happens before any write');
    }

    public function testPatchReporterRefusesEverySurface(): void
    {
        $members = $this->windowBlocks();
        $cluster = new Cluster('C-w2', [$members[0], $members[1]], 1.0, false);
        $report  = new Report(files: 1, blocks: 2, parseErrors: 0, clusters: [$cluster], config: new Config(paths: [__DIR__], exclude: []));
        $reporter = new RefactorPatchReporter();

        foreach ([
            'plain .patch'   => [$report, $this->tmp . '/plain', false, true],
            'F1a apply.diff' => [$report, $this->tmp . '/dryrun', true, true],
            'F1b real apply' => [$report, $this->tmp . '/real', true, false],
        ] as $label => $args) {
            try {
                $reporter->writeTo(...$args);
                $this->fail("window clusters must be refused on the {$label} surface");
            } catch (WindowApplyRefusal $e) {
                $this->assertStringContainsString('RefactorPatchReporter', $e->getMessage(), $label);
            }
        }
        foreach (['/plain', '/dryrun', '/real'] as $dir) {
            $this->assertDirectoryDoesNotExist($this->tmp . $dir);
        }
    }

    public function testResolveRefusesUnloadedWindowBlock(): void
    {
        $block = $this->windowBlocks()[0];
        $block->unloadAst();
        $loader = new BlockAstLoader(
            new AstCache($this->tmp . '/ast'),
            new AstParser(),
            new Config(paths: [$this->tmp], exclude: []),
        );
        try {
            $loader->resolve($block);
            $this->fail('unloaded windows must be refused by type');
        } catch (WindowApplyRefusal $e) {
            $this->assertStringContainsString('window', $e->getMessage());
            $this->assertStringContainsString('synthetic statement cuts', $e->getMessage());
        }
    }

    public function testResolveServesLoadedWindowNormally(): void
    {
        $block  = $this->windowBlocks()[0];
        $loader = new BlockAstLoader(new AstCache($this->tmp . '/ast'), new AstParser(), null);
        $this->assertSame($block->ast, $loader->resolve($block));
    }

    public function testRebaseClustersRefusesReplayedWindowMember(): void
    {
        $block = $this->windowBlocks()[0];
        $block->structuralHash = 'whatever-was-cached';
        $block->unloadAst();
        $cluster = new Cluster('C-w3', [$block], 1.0, false);

        $loader = new BlockAstLoader(
            new AstCache($this->tmp . '/ast'),
            new AstParser(),
            new Config(paths: [$this->tmp], exclude: []),
        );
        $this->expectException(WindowApplyRefusal::class);
        $this->expectExceptionMessage('rebaseClusters');
        $loader->rebaseClusters([$cluster], []);
    }

    public function testMatchesKindNeverPositionallyMatchesWindow(): void
    {
        // Belt for the fail-loud law: the P4 relocation classifier must
        // not accept 'window' — a positional "match" would be exactly
        // the silent-mis-anchor class the script precedent named.
        $ref = new \ReflectionMethod(BlockAstLoader::class, 'matchesKind');
        $this->assertFalse($ref->invoke(null, new Stmt\Block([]), BlockExtractor::WINDOW_KIND));
    }

    public function testRefusalMessageNamesTheLimitationNotJustTheKind(): void
    {
        $members = $this->windowBlocks();
        $cluster = new Cluster('C-w4', [$members[0]], 1.0, false);
        try {
            (new ApplyExtractor())->apply($cluster, $this->tmp . '/out2');
            $this->fail();
        } catch (WindowApplyRefusal $e) {
            $msg = $e->getMessage();
            foreach (['relocation', 'refused in v1'] as $fragment) {
                $this->assertStringContainsString($fragment, $msg, "message must name: {$fragment}");
            }
        }
    }
}
