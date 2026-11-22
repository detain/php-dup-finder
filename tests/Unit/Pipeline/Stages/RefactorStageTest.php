<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Pipeline\Stages;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\BlockExtractor;
use Phpdup\Parallel\WorkerPool;
use Phpdup\Parsing\AstParser;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\Stage;
use Phpdup\Pipeline\Stages\ClusterStage;
use Phpdup\Pipeline\Stages\PreprocessStage;
use Phpdup\Pipeline\Stages\RefactorStage;
use Phpdup\Pipeline\Stages\ScanningStage;
use Phpdup\Refactor\HoleCoverage;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\NullOutput;

final class RefactorStageTest extends TestCase
{
    public function testEmptyClustersIsNoOp(): void
    {
        $state = new PipelineState(Config::defaults([__DIR__]));

        (new RefactorStage(useCache: false))->run($state, new NullOutput());

        $this->assertSame([], $state->clusters);
        $this->assertSame(0.0, $state->timings['refactor']);
    }

    public function testEnrichesClustersWithSignatureAndPatterns(): void
    {
        $config = new Config(
            paths: [__DIR__ . '/../../../Fixtures/sql'],
            exclude: Config::defaults([])->exclude,
            lazyAst: false,
        );
        $state = new PipelineState($config);

        (new ScanningStage())->run($state, new NullOutput());
        (new PreprocessStage(useCache: false))->run($state, new NullOutput());
        (new ClusterStage(exactOnly: true))->run($state, new NullOutput());
        (new RefactorStage(useCache: false))->run($state, new NullOutput());

        $this->assertNotEmpty($state->clusters);
        $this->assertGreaterThan(0.0, $state->timings['refactor']);

        $cluster = $state->clusters[0];
        $this->assertNotNull($cluster->signature, 'SignatureBuilder should populate signature');
    }

    public function testReportsName(): void
    {
        $this->assertSame(
            Stage::Refactoring,
            (new RefactorStage(useCache: false))->name(),
        );
    }

    // ── output-S57: member-cap narration — the counted tail is announced ──

    public function testCappedClusterNarratesRepresentativesAndTail(): void
    {
        $state = new PipelineState($this->handConfig());
        $state->clusters = [$this->clusterOf(40, 'TEST')];
        $buffer = new BufferedOutput();

        (new RefactorStage(useCache: false))->run($state, $buffer);

        $this->assertStringContainsString(
            '  refactor: cluster TEST: 40 members, 32 used as representatives (8 counted tail — unobserved members carry gap rows; evidence degraded, --apply refuses)',
            $buffer->fetch(),
            'the plan-mandated disclosure phrase must appear verbatim',
        );
    }

    public function testCapAggregateSurfacesRoutingCounts(): void
    {
        $state = new PipelineState($this->handConfig());
        $state->clusters = [$this->clusterOf(40, 'TEST')];
        $buffer = new BufferedOutput();

        (new RefactorStage(useCache: false))->run($state, $buffer);

        $this->assertStringContainsString(
            '  refactor cap: 32 members/cluster — 1 cluster(s) routed through representatives (8 counted-tail members)',
            $buffer->fetch(),
        );
    }

    public function testUnroutedRunsEmitNoCapNarrationAtAll(): void
    {
        // Zero-move proof on the real corpus: every fixture cluster is far
        // below the cap, so the stage must print NOTHING — the golden
        // surfaces stay byte-identical by construction.
        $config = new Config(
            paths: [__DIR__ . '/../../../Fixtures/sql'],
            exclude: Config::defaults([])->exclude,
            lazyAst: false,
        );
        $state = new PipelineState($config);
        (new ScanningStage())->run($state, new NullOutput());
        (new PreprocessStage(useCache: false))->run($state, new NullOutput());
        (new ClusterStage(exactOnly: true))->run($state, new NullOutput());

        $buffer = new BufferedOutput();
        (new RefactorStage(useCache: false))->run($state, $buffer);

        $this->assertSame('', $buffer->fetch(), 'a run where the cap never bites stays silent');
    }

    public function testCappedClusterSurvivesAllStagePasses(): void
    {
        $state = new PipelineState($this->handConfig());
        $state->clusters = [$this->clusterOf(40, 'TEST')];

        (new RefactorStage(useCache: false))->run($state, new NullOutput());

        $cluster = $state->clusters[0];
        $this->assertNotNull($cluster->signature, 'routed clusters still get a signature');
        $this->assertNotEmpty($cluster->holes);
        foreach ($cluster->holes as $hole) {
            $this->assertCount(40, $hole->observedValues, 'one row per member survives the whole stage');
            $this->assertTrue($hole->isDegraded());
        }
        $this->assertTrue(
            HoleCoverage::isDegraded($cluster),
            'the --apply refusal chain sees routed evidence as degraded',
        );
    }

    public function testForkAndSerialNarrateCappedClustersIdentically(): void
    {
        if (!WorkerPool::isAvailable()) {
            $this->markTestSkipped('pcntl fork pool unavailable on this host');
        }

        $serial = $this->runTenCappedClusters(workers: 1);
        $forked = $this->runTenCappedClusters(workers: 2);

        $this->assertStringContainsString(
            '  refactor cap: 32 members/cluster — 10 cluster(s) routed through representatives (10 counted-tail members)',
            $forked,
        );
        $this->assertSame(10, substr_count($forked, 'members, 32 used as representatives'), 'one per-cluster note each');
        $this->assertSame($serial, $forked, 'fork output is byte-identical to serial narration');
    }

    private function runTenCappedClusters(int $workers): string
    {
        $state = new PipelineState($this->handConfig($workers));
        for ($c = 0; $c < 10; $c++) {
            $state->clusters[] = $this->clusterOf(33, sprintf('C%d', $c), $c);
        }
        $buffer = new BufferedOutput();
        (new RefactorStage(useCache: false))->run($state, $buffer);
        return $buffer->fetch();
    }

    private function handConfig(int $workers = 0): Config
    {
        return new Config(
            paths: [__DIR__],
            exclude: [],
            lazyAst: false,
            workers: $workers,
        );
    }

    /**
     * Hand-built cluster of $n equal-shape members whose int literals
     * differ, so anti-unification forms one hole and routing is visible in
     * the observed-value rows. $salt keeps clusters mutually distinct.
     */
    private function clusterOf(int $n, string $id, int $salt = 0): Cluster
    {
        $parser = new AstParser();
        $extractor = new BlockExtractor(minSize: 1);
        $blocks = [];
        for ($i = 0; $i < $n; $i++) {
            $code = sprintf(
                '<?php function g%d_%02d($x) { notify($x, %d); }',
                $salt,
                $i,
                $i,
            );
            $stmts = $parser->parseCode($code);
            $extracted = $extractor->extract(sprintf('g%d_%02d.php', $salt, $i), $stmts);
            $this->assertNotEmpty($extracted);
            $blocks[] = $extracted[0];
        }
        return new Cluster($id, $blocks, 1.0, false);
    }
}
