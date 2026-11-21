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
use Phpdup\Similarity\EditCostModel;
use Phpdup\Similarity\JaccardSimilarity;
use Phpdup\Similarity\TreeEditDistance;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Item 1.4 convergence acceptance (ruling §5b): the medoid-ball split
 * lands inside Clusterer::cluster() — after union-find, on the one edge
 * stream both scoring paths converge into — so a fork-parallel run and a
 * serial run of the SAME corpus must yield identical cluster sets
 * (content-derived ids, membership, §5b-corrected similarity), despite
 * nondeterministic edge arrival from the worker stream.
 *
 * Three drivers are compared:
 *   1. ClusterStage with workers=1  (PairScoreWorker in-process);
 *   2. ClusterStage with workers=3  (forked streaming — needs >= 64
 *      candidate pairs over > 8 pool items, which the 18-file corpus
 *      guarantees; preconditions asserted, skipped without pcntl);
 *   3. Clusterer::cluster(index, null) — the fully serial scorer,
 *      pinning the serial/worker dual vocabulary against drift.
 */
final class ClusterStageConvergenceTest extends TestCase
{
    private string $corpus;

    protected function setUp(): void
    {
        $this->corpus = sys_get_temp_dir() . '/phpdup-convergence-' . uniqid();
        mkdir($this->corpus, 0o775, true);
        // Two template families of nine near-identical guard-chain
        // functions. maxDocumentFrequency 0.5 keeps intra-family grams
        // (df 9/18) as candidate fuel: 2 * C(9,2) = 72 candidate pairs,
        // past the stage's 64-pair parallel threshold.
        for ($i = 0; $i < 9; $i++) {
            $this->writeFamily('a', $i);
            $this->writeFamily('b', $i);
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->corpus . '/*.php') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->corpus);
    }

    private function writeFamily(string $family, int $variant): void
    {
        $name = "handle_{$family}{$variant}";
        $guard = $family === 'a' ? 'empty' : '!array_key_exists';
        $lines = [];
        $lines[] = '<?php';
        $lines[] = "function {$name}(\$request) {";
        foreach (['id', 'op', 'role', 'locale', 'tenant', 'scope', 'version'] as $field) {
            $lines[] = "    if ({$guard}(\$request['{$field}'])) { return ['code' => 400, 'msg' => '{$field}']; }";
        }
        $lines[] = "    \$rows = repo_{$family}->fetch(\$request['id'], {$variant});";
        $lines[] = "    return ['code' => 200, 'data' => \$rows];";
        $lines[] = '}';
        file_put_contents(
            $this->corpus . "/{$name}.php",
            implode("\n", $lines) . "\n",
        );
    }

    /** @return array{clusters: list<Cluster>, candidatePairs: int, index: \Phpdup\Index\BlockIndex} */
    private function runStage(int $workers): array
    {
        $config = new Config(
            paths: [$this->corpus],
            exclude: [],
            maxDocumentFrequency: 0.5,
            workers: $workers,
            incremental: false,
        );
        $state = new PipelineState($config);
        (new ScanningStage())->run($state, new NullOutput());
        (new PreprocessStage(useCache: false))->run($state, new NullOutput());
        (new ClusterStage(exactOnly: false, useClusterCache: false))->run($state, new NullOutput());

        /** @var list<Cluster> $clusters */
        $clusters = $state->clusters;
        return ['clusters' => $clusters, 'candidatePairs' => $state->candidatePairs, 'index' => $state->index];
    }

    /**
     * @param list<Cluster> $clusters
     * @return list<string> id:members:similarity fingerprints, order-normalised
     *
     * Members are identified by file:line location, NOT by Block::$id:
     * the id embeds the corpus scan index (PreprocessStage:200 — Hazard-4,
     * owned by item 4.4) and PreprocessStage forks past 8 files, so the
     * numeric id suffixes legitimately permute between a workers=1 and a
     * workers=3 run. The cluster ids themselves are content-derived and
     * must (and do) come out identical across modes.
     */
    private function signatures(array $clusters): array
    {
        $sigs = array_map(static function (Cluster $c): string {
            $locs = array_map(static fn ($m): string => $m->file . ':' . $m->range, $c->members);
            sort($locs);
            return $c->id . ':' . implode(',', $locs) . ':' . round($c->similarity, 9);
        }, $clusters);
        sort($sigs);
        return $sigs;
    }

    public function testSerialAndForkParallelScoringConvergeIdentically(): void
    {
        $serial = $this->runStage(workers: 1);
        $this->assertNotEmpty($serial['clusters'], 'the corpus must produce clusters');
        $this->assertGreaterThanOrEqual(64, $serial['candidatePairs'],
            'fixture must clear the stage parallel threshold (else the fork arm never arms)');

        if (!WorkerPool::isAvailable()) {
            $this->markTestSkipped('pcntl unavailable — fork arm cannot be exercised');
        }

        $parallel = $this->runStage(workers: 3);

        $this->assertSame(
            $this->signatures($serial['clusters']),
            $this->signatures($parallel['clusters']),
            '§5b: split lands post-union-find so serial and forked-parallel streams yield the identical cluster set',
        );

        // And the fully-serial Clusterer scorer (the third vocabulary) over
        // the same index must agree too.
        $direct = new Clusterer(
            similarity: new JaccardSimilarity(),
            tree: new TreeEditDistance(new EditCostModel('default')),
            similarityThreshold: 0.80,
            treeThreshold: 0.85,
            maxDocumentFrequency: 0.5,
        );
        $this->assertSame(
            $this->signatures($serial['clusters']),
            $this->signatures($direct->cluster($serial['index'])),
            'Clusterer::scorePairsSerially must match the PairScoreWorker gate (dual-vocabulary drift pin)',
        );
    }

    public function testConvergedIdsAreContentDerivedNotArrivalDerived(): void
    {
        $serial = $this->runStage(workers: 1);
        foreach ($serial['clusters'] as $c) {
            $this->assertMatchesRegularExpression(
                '/^F[0-9a-f]{16}$/',
                $c->id,
                'item 4.4: every cluster id — phase-1 bucket or near-dup component — is the canonical content-derived FindingId',
            );
        }
    }
}
