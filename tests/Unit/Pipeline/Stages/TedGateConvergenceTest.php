<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Pipeline\Stages;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Clustering\Cluster;
use Phpdup\Clustering\Clusterer;
use Phpdup\Parallel\WorkerPool;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\Stages\ClusterStage;
use Phpdup\Pipeline\Stages\PreprocessStage;
use Phpdup\Pipeline\Stages\ScanningStage;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Item 1.2 end-to-end — fork-zone parity through the real stage.
 *
 * ClusterStageConvergenceTest pinned the three-driver equivalence for the
 * SPLIT knobs (1.4); this replays the exact same law for the TED-size
 * gate: the cap must bind identically whether pairs are scored in the
 * serial Clusterer, inside the in-process PairScoreWorker (workers=1), or
 * in forked workers (workers=3). A cap that only bound on one side would
 * make cluster membership fork with worker count — the heisenbug class.
 *
 * Family members alternate an extra guard statement (variant % 2) so
 * consecutive members are near-duplicates in the JACARD lane rather than
 * byte-equal clones in the exact bucket (after aggressive normalization
 * pure literal differences vanish — the structural difference is what
 * survives). Big-corpus blocks (~280-300 nodes) sit under the 800
 * extraction ceiling but above the armed cap of 100, so an armed run
 * must not execute a single APTED call; the small corpus (~60-70 nodes)
 * pinned against the DEFAULT cap proves real TED still runs and nothing
 * carries the new tier there.
 */
final class TedGateConvergenceTest extends TestCase
{
    /** Nine near-identical ~300-node functions per family: arms the fork path (>= 64 pairs). */
    private string $corpusBig;
    /** Four near-identical ~65-node functions per family: cheap real TED for the inertness pin. */
    private string $corpusSmall;

    protected function setUp(): void
    {
        $this->corpusBig = sys_get_temp_dir() . '/phpdup-tedgate-big-' . uniqid();
        $this->corpusSmall = sys_get_temp_dir() . '/phpdup-tedgate-sm-' . uniqid();
        mkdir($this->corpusBig, 0o775, true);
        mkdir($this->corpusSmall, 0o775, true);
        for ($i = 0; $i < 9; $i++) {
            $this->writeFamily($this->corpusBig, 'a', $i, guards: 18);
            $this->writeFamily($this->corpusBig, 'b', $i, guards: 18);
        }
        for ($i = 0; $i < 4; $i++) {
            $this->writeFamily($this->corpusSmall, 'a', $i, guards: 8);
            $this->writeFamily($this->corpusSmall, 'b', $i, guards: 8);
        }
    }

    protected function tearDown(): void
    {
        foreach ([$this->corpusBig, $this->corpusSmall] as $dir) {
            foreach (glob($dir . '/*.php') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
    }

    private function writeFamily(string $dir, string $family, int $variant, int $guards): void
    {
        $name = "gate_{$family}{$variant}";
        $guard = $family === 'a' ? 'empty' : '!array_key_exists';
        $lines = [];
        $lines[] = '<?php';
        $lines[] = "function {$name}(\$request) {";
        $lines[] = "    \$acc = [];";
        $count = $guards + ($variant % 2);
        for ($i = 0; $i < $count; $i++) {
            $lines[] = "    if ({$guard}(\$request['f{$i}'])) { \$acc['e{$i}'] = {$i}; }";
        }
        $lines[] = "    \$rows = repo_{$family}->fetch(\$request['id'], {$variant});";
        $lines[] = "    return \$acc;";
        $lines[] = '}';
        file_put_contents(
            $dir . "/{$name}.php",
            implode("\n", $lines) . "\n",
        );
    }

    /** @return array{clusters: list<Cluster>, candidatePairs: int, index: \Phpdup\Index\BlockIndex} */
    private function runStage(int $workers, string $corpus, int $cap): array
    {
        $config = new Config(
            paths: [$corpus],
            exclude: [],
            maxDocumentFrequency: 0.5,
            workers: $workers,
            incremental: false,
            maxTedSize: $cap,
        );
        $state = new PipelineState($config);
        (new ScanningStage())->run($state, new NullOutput());
        (new PreprocessStage(useCache: false))->run($state, new NullOutput());
        (new ClusterStage(exactOnly: false, useClusterCache: false))->run($state, new NullOutput());

        /** @var list<Cluster> $clusters */
        return ['clusters' => $state->clusters, 'candidatePairs' => $state->candidatePairs, 'index' => $state->index];
    }

    /**
     * @param list<Cluster> $clusters
     * @return list<string> id:members:similarity fingerprints, order-normalised
     *
     * Cluster ids are content-derived (FindingId over members + scan
     * roots), so id equality IS membership equality — the member file
     * lists would explode the assertion diffs on the 36-bucket corpus and
     * carry no extra proof.
     */
    private function fingerprints(array $clusters): array
    {
        $out = [];
        foreach ($clusters as $c) {
            $out[] = $c->id . ':' . count($c->members) . ':' . round($c->similarity, 6);
        }
        sort($out);
        return $out;
    }

    /**
     * @param list<Cluster> $clusters
     * @return array<string, int> tier token → pair count (summary shape:
     *                            never an array of thousands of pair ids)
     */
    private function tierSummary(array $clusters): array
    {
        $tiers = [];
        foreach ($clusters as $c) {
            foreach ($c->pairs as $p) {
                $tiers[$p['matchTier']] = ($tiers[$p['matchTier']] ?? 0) + 1;
            }
        }
        ksort($tiers);
        return $tiers;
    }

    public function testForkedWorkersHonourTheArmedCap(): void
    {
        if (!WorkerPool::isAvailable()) {
            $this->markTestSkipped('pcntl not available — fork arm cannot be exercised');
        }
        $parallel = $this->runStage(3, $this->corpusBig, 100);
        $this->assertGreaterThanOrEqual(64, $parallel['candidatePairs'], 'fork path must be armed');

        $summary = $this->tierSummary($parallel['clusters']);
        $this->assertArrayHasKey('jaccard-unverified', $summary,
            'forked PairScoreWorker must honour max_ted_size');
        $this->assertArrayNotHasKey('jaccard', $summary,
            'a cap of 100 over ~300-node blocks must gate every jaccard-lane pair — none may have paid TED');
    }

    public function testSerialInProcessAndForkStreamsConvergeUnderTheCap(): void
    {
        $serial = $this->runStage(1, $this->corpusBig, 100);
        $this->assertNotEmpty($serial['clusters'], 'the corpus must produce clusters');
        $this->assertGreaterThanOrEqual(64, $serial['candidatePairs']);

        if (WorkerPool::isAvailable()) {
            $parallel = $this->runStage(3, $this->corpusBig, 100);
            $this->assertSame(
                $this->fingerprints($serial['clusters']),
                $this->fingerprints($parallel['clusters']),
                'the cap must bind identically across the fork boundary',
            );
            $this->assertSame($this->tierSummary($serial['clusters']), $this->tierSummary($parallel['clusters']));
        }

        // The third driver: the fully serial Clusterer scorer over the
        // same index (same cap, same scan roots — the convergence triangle
        // of ClusterStageConvergenceTest replayed for item 1.2).
        $direct = new Clusterer(
            similarity: new \Phpdup\Similarity\JaccardSimilarity(),
            tree: new \Phpdup\Similarity\TreeEditDistance(),
            similarityThreshold: 0.80,
            treeThreshold: 0.85,
            maxDocumentFrequency: 0.5,
            scanRoots: [$this->corpusBig],
            maxTedSize: 100,
        );
        $directClusters = $direct->cluster($serial['index'], null);
        $this->assertSame(
            $this->fingerprints($serial['clusters']),
            $this->fingerprints($directClusters),
            'Clusterer::scorePairsSerially must match the PairScoreWorker gate (dual-vocabulary drift pin)',
        );
        $this->assertSame($this->tierSummary($serial['clusters']), $this->tierSummary($directClusters));
        $this->assertArrayNotHasKey('jaccard', $this->tierSummary($directClusters));
    }

    public function testDefaultCapRunsRealTedOnTheSameShape(): void
    {
        // Positive inertness proof at cheap sizes: small-corpus blocks
        // (~65 nodes) sit under the 800 default gate, so near-duplicate
        // pairs are still judged by real APTED and carry the 'jaccard'
        // tier — the new token must be absent entirely.
        $run = $this->runStage(1, $this->corpusSmall, 800);
        $this->assertNotEmpty($run['clusters']);
        $summary = $this->tierSummary($run['clusters']);
        $this->assertArrayHasKey('jaccard', $summary,
            'at the default cap the near-dup lane must still pay real TED');
        $this->assertArrayNotHasKey('jaccard-unverified', $summary,
            'nothing may be gated at the default cap');
    }
}
