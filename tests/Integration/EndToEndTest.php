<?php
declare(strict_types=1);

namespace Phpdup\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\Stages\ClusterStage;
use Phpdup\Pipeline\Stages\PreprocessStage;
use Phpdup\Pipeline\Stages\RefactorStage;
use Phpdup\Pipeline\Stages\ScanningStage;
use Phpdup\Reporting\Ranker;
use Phpdup\Reporting\Report;
use Symfony\Component\Console\Output\NullOutput;

/**
 * once-S98 (php-dup-finder-once-123, TEST-010): this file used to
 * hand-roll scanner→parser→extractor→normalizer→hasher→fingerprint→
 * index→Clusterer→unify→synthesize→signature→tag→Ranker as a private
 * duplicate of the pipeline — including its own positional block-id
 * mint (`substr(hash,0,8).'_'.count($blocks)`), an already-superseded
 * copy of production's canonical minter. A test that reimplements the
 * product tests the reimplementation, not the product: it drifted from
 * every stage-level cure (cache posture, canonical id ordering,
 * split/memo steps) while staying green.
 *
 * The rewrite drives the REAL stages — the same four-line spine
 * tests/Golden/GoldenTest uses — and asserts on what the shipped
 * pipeline produces. The one behavioural knob pinned here is workers:1:
 * this suite runs under the fleet's parallel-shard harness, and the
 * serial spine keeps the cluster census deterministic.
 */
final class EndToEndTest extends TestCase
{
    public function testFinderProducesExpectedClustersOnFixtureCorpus(): void
    {
        $fixtureRoot = __DIR__ . '/../Fixtures';

        $config = new Config(
            paths: [$fixtureRoot],
            exclude: [],
            minBlockSize: 5,
            normalizationMode: 'aggressive',
            similarityThreshold: 0.7,
            treeThreshold: 0.7,
            minClusterImpact: 5,
            workers: 1,
        );

        $report = $this->runPipeline($config);

        $this->assertGreaterThan(0, count($report->clusters), 'expected at least one cluster');

        // The ids on the wire are production-minted canonically: each
        // block's id rides its OWN structural-hash prefix (the old test
        // minted ids from append order, which could — and silently
        // would — diverge from the canonical-sort mint without any
        // assertion noticing).
        //
        // Uniqueness is pinned PER TIER, not globally, because engine-054
        // (engine-S15) deliberately BREAKS global uniqueness: a byte-identical
        // bucket that rides a near-dup component is emitted BOTH inside the
        // weaker (exact=false) cluster — where the ride is the headline — AND
        // as its own surviving exact=true bucket. That re-emission is the
        // shipped contract ("the exact tier survives"), so the old
        // "every id appears in exactly one cluster" pin was a latent landmine
        // that could only stay green while no corpus produced a riding bucket.
        // It went off when the item-4.1 drift corpus (P1) grew the fixture
        // scan past the candidate-ranking tipping point for the micro-fixture
        // preamble pair — a tier-legal riding emission, not a double mint.
        // What MUST stay caught: the same id twice inside ONE cluster, and an
        // id appearing in two exact clusters (real mint/replay bug classes).
        /** @var array<string, list<bool>> id → exactness of every carrier cluster */
        $seenIds = [];
        foreach ($report->clusters as $c) {
            $seenHere = [];
            foreach ($c->members as $m) {
                $this->assertStringStartsWith(
                    substr($m->structuralHash, 0, 8) . '_',
                    $m->id,
                    'block ids must ride the canonical production mint (hash prefix + rank)'
                );
                $this->assertMatchesRegularExpression('/^[0-9a-f]{8}_\d+$/', $m->id);
                $this->assertFalse(isset($seenHere[$m->id]), 'duplicate block id ' . $m->id . ' within one cluster');
                $seenHere[$m->id] = true;
                $seenIds[$m->id][] = $c->exact;
            }
        }
        foreach ($seenIds as $id => $carriers) {
            if (count($carriers) < 2) {
                continue;
            }
            $exactCount = count(array_filter($carriers, static fn (bool $e): bool => $e));
            $this->assertSame(1, $exactCount,
                'block id ' . $id . ' appears in ' . count($carriers) . ' clusters with ' . $exactCount
                . ' exact carriers — the only legal duplication is engine-054 riding (ONE exact bucket + near-dup carrier)');
        }

        // Notify cluster: 3 members, threshold + role holes
        $notifyCluster = $this->findClusterTouching($report, 'notify/Notify.php');
        $this->assertNotNull($notifyCluster, 'notify cluster missing');
        $this->assertSame(3, $notifyCluster->size());
        $this->assertNotEmpty($notifyCluster->holes, 'notify cluster should have holes');

        // SQL cluster: 3 nearly-identical findById, table name should be a hole
        $sqlCluster = $this->findClusterTouching($report, 'sql/Repos.php');
        $this->assertNotNull($sqlCluster, 'sql cluster missing');
        $this->assertGreaterThanOrEqual(2, $sqlCluster->size());

        // Unrelated/Unrelated.php should not appear in any cluster
        foreach ($report->clusters as $c) {
            foreach ($c->members as $m) {
                $this->assertStringNotContainsString('unique/Unrelated.php', $m->file,
                    'Unrelated.php must not cluster with anything');
            }
        }
    }

    private function findClusterTouching(Report $report, string $pathSubstring): ?\Phpdup\Clustering\Cluster
    {
        foreach ($report->clusters as $c) {
            foreach ($c->members as $m) {
                if (str_contains($m->file, $pathSubstring)) {
                    return $c;
                }
            }
        }
        return null;
    }

    /**
     * The real spine (mirrors GoldenTest::buildPayload): every stage is
     * the production object, caches are cold so the suite never reads a
     * warm .phpdup-cache, and the anti-unification / signature / tag
     * work happens inside RefactorStage exactly as the CLI runs it.
     */
    private function runPipeline(Config $config): Report
    {
        $state = new PipelineState($config);
        $out   = new NullOutput();

        (new ScanningStage())->run($state, $out);
        (new PreprocessStage(useCache: false))->run($state, $out);
        (new ClusterStage(exactOnly: false, useClusterCache: false))->run($state, $out);
        (new RefactorStage(useCache: false))->run($state, $out);

        return new Report(
            files: count($state->files),
            blocks: count($state->blocks),
            parseErrors: $state->parseErrors,
            clusters: (new Ranker($config->minClusterImpact))->rank($state->clusters),
            config: $config,
        );
    }
}
