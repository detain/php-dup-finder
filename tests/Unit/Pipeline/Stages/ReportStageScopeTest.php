<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Pipeline\Stages;

use PhpParser\Node;
use PHPUnit\Framework\TestCase;
use Phpdup\Clustering\Cluster;
use Phpdup\Cli\Config;
use Phpdup\Extraction\Block;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\Stages\ReportStage;
use Phpdup\Reporting\ClusterScope;
use Phpdup\Reporting\JsonReporter;
use Phpdup\Util\LineRange;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Item 5.1 — ReportStage is the enforcement point for both halves of
 * the label contract: every cluster member is RE-STAMPED from the
 * current run's roots (so cluster-cache replay cannot report the
 * saving run's roots), and --scope filters between rank and report
 * (so gates and reporters see the same slice).
 */
final class ReportStageScopeTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/phpdup-51-stage-' . uniqid();
        mkdir($this->tmp . '/cache', 0o775, true);
        mkdir($this->tmp . '/repoA/src', 0o775, true);
        mkdir($this->tmp . '/repoB/src', 0o775, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmp)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($it as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($this->tmp);
        }
    }

    public function testClusterMembersAreRestampedFromThisRunsRoots(): void
    {
        // Members carry the labels a cluster-cache SAVING run would
        // have left behind. ReportStage must rewrite them from the
        // CURRENT config paths before anything reads them.
        $stale = $this->cluster('REPLAYED', [
            $this->member($this->tmp . '/repoA/src/A.php', 'Ns\\A', 'OLD-A', 'OLD-A'),
            $this->member($this->tmp . '/repoB/src/B.php', 'Ns\\B', 'OLD-B', 'OLD-B'),
        ]);

        $report = $this->runStage([$stale], scope: 'all', scoreMode: 'global');
        $payload = (new JsonReporter())->build($report);
        $cluster = $payload['clusters'][0];

        $this->assertSame('repoA', $cluster['members'][0]['repo'],
            'the saving-run label must not survive into this run');
        $this->assertSame('repoB', $cluster['members'][1]['repo']);
        $this->assertSame(ClusterScope::MULTI, $cluster['scope']);
    }

    public function testScopeFilterRunsBetweenRankAndReport(): void
    {
        $single = $this->cluster('ONE', [
            $this->member($this->tmp . '/repoA/src/A.php', 'Ns\\A', '', ''),
            $this->member($this->tmp . '/repoA/src/B.php', 'Ns\\B', '', ''),
        ]);
        $multi = $this->cluster('MANY', [
            $this->member($this->tmp . '/repoA/src/C.php', 'Ns\\C', '', ''),
            $this->member($this->tmp . '/repoB/src/D.php', 'Ns\\D', '', ''),
        ]);

        $all = $this->ids($this->runStage([$single, $multi], 'all', 'global'));
        $this->assertSameCanonical(['ONE', 'MANY'], $all, 'default scope reports everything');

        $this->assertSameCanonical(['ONE'], $this->ids($this->runStage([$single, $multi], 'single', 'global')));
        $this->assertSameCanonical(['MANY'], $this->ids($this->runStage([$single, $multi], 'multi', 'global')));
        $this->assertSameCanonical(['ONE', 'MANY'], $this->ids($this->runStage([$single, $multi], 'repoA', 'global')));
        $this->assertSameCanonical(['MANY'], $this->ids($this->runStage([$single, $multi], 'repoB', 'global')));
        $this->assertSameCanonical([], $this->ids($this->runStage([$single, $multi], 'ghost', 'global')));
    }

    /**
     * Ranker sorting is not total on impact ties (synthetic clusters
     * all have size 0 here), so membership is what matters.
     *
     * @param list<string> $expected
     * @param list<string> $actual
     */
    private function assertSameCanonical(array $expected, array $actual, string $message = ''): void
    {
        sort($expected);
        sort($actual);
        $this->assertSame($expected, $actual, $message);
    }

    public function testScoreModeFlowsThroughReportStageRanker(): void
    {
        // Same cluster, two stances: the ns spread (4) crosses the >3
        // threshold under global but not per-repo; typed pays the
        // cross-repo bonus instead. Values are asserted against the
        // numbers computed by the pure scorer unit — here we only pin
        // the WIRING (Config->ReportStage->Ranker).
        $mirror = fn(): Cluster => $this->cluster('M', [
            $this->member($this->tmp . '/repoA/src/A.php', 'Shop1\\A', '', ''),
            $this->member($this->tmp . '/repoA/src/B.php', 'Shop1\\B', '', ''),
            $this->member($this->tmp . '/repoA/src/C.php', 'Shop1\\C', '', ''),
            $this->member($this->tmp . '/repoA/src/D.php', 'Shop1\\D', '', ''),
            $this->member($this->tmp . '/repoB/src/E.php', 'Shop2\\A', '', ''),
            $this->member($this->tmp . '/repoB/src/F.php', 'Shop2\\B', '', ''),
            $this->member($this->tmp . '/repoB/src/G.php', 'Shop2\\C', '', ''),
            $this->member($this->tmp . '/repoB/src/H.php', 'Shop2\\D', '', ''),
        ], similarity: 0.9);

        $g = $this->firstCluster($this->runStage([$mirror()], 'all', 'global'));
        $p = $this->firstCluster($this->runStage([$mirror()], 'all', 'per-repo'));
        $t = $this->firstCluster($this->runStage([$mirror()], 'all', 'typed'));

        // global: 0.9 − 0.05 (raw 8 ns > 3) + 0.05 (single class null)
        $this->assertEqualsWithDelta(0.90, $g['confidence'], 1e-9);
        $this->assertEqualsWithDelta(0.83, $g['safety'], 1e-9);  // −0.10 + 0.03 (8-member nudge)
        // per-repo: spread 4 per repo still >3 → same numbers here
        // (fixture keeps both repos above the bar — the ungrouping win
        // is proven in ScoreModeTest; wiring equality is what this pin
        // shows)
        $this->assertEqualsWithDelta(0.90, $p['confidence'], 1e-9);
        $this->assertEqualsWithDelta(0.83, $p['safety'], 1e-9);
        // typed: cross-repo → +0.05 confidence, +0.10 safety
        $this->assertEqualsWithDelta(1.00, $t['confidence'], 1e-9);
        $this->assertEqualsWithDelta(1.00, $t['safety'], 1e-9);
    }

    private function configFor(array $paths, string $scope, string $scoreMode): Config
    {
        return new Config(
            paths:         $paths,
            exclude:       [],
            cacheDir:      $this->tmp . '/cache',
            minClusterImpact: 0,
            scope:         $scope,
            scoreMode:     $scoreMode,
        );
    }

    /** @param list<Cluster> $clusters */
    private function runStage(array $clusters, string $scope, string $scoreMode): \Phpdup\Reporting\Report
    {
        // Fresh objects per run — rank mutates in place.
        $state = new PipelineState($this->configFor(
            [$this->tmp . '/repoA', $this->tmp . '/repoB'],
            $scope,
            $scoreMode,
        ));
        $state->clusters = $clusters;
        $state->files    = ['x.php'];
        $state->blocks   = $clusters[0]->members;

        (new ReportStage(limit: 50, showStats: false))->run($state, new NullOutput());

        $this->assertNotNull($state->report, 'two roots -> scope filter must have run');
        return $state->report;
    }

    /** @return list<string> */
    private function ids(\Phpdup\Reporting\Report $report): array
    {
        return array_map(static fn(Cluster $c) => $c->id, $report->clusters);
    }

    /** @return array<string, mixed> */
    private function firstCluster(\Phpdup\Reporting\Report $report): array
    {
        return (new JsonReporter())->build($report)['clusters'][0];
    }

    /** @param list<Block> $members */
    private function cluster(string $id, array $members, float $similarity = 1.0): Cluster
    {
        return new Cluster($id, $members, $similarity, false);
    }

    private function member(string $file, string $namespace, string $repo, string $scope): Block
    {
        $b = new Block(
            file:      $file,
            range:     new LineRange(1, 12),
            kind:      'method',
            namespace: $namespace,
            class:     null,
            name:      'm',
            ast:       new Node\Stmt\Nop(),
        );
        $b->repo  = $repo;
        $b->scope = $scope;
        return $b;
    }
}
