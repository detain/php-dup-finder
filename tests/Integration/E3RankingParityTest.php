<?php
declare(strict_types=1);

namespace Phpdup\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Clustering\Cluster;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\Stages\ClusterStage;
use Phpdup\Pipeline\Stages\PreprocessStage;
use Phpdup\Pipeline\Stages\RefactorStage;
use Phpdup\Pipeline\Stages\ScanningStage;
use Phpdup\Reporting\ClusterSort;
use Phpdup\Reporting\Ranker;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Item 6.2 done-when belt (E3 lane).
 *
 * Two claims pinned on tests/Fixtures/dto — the synthetic stand-in for
 * improve_logic.md §3.5's phlix pathology (DTO serializers and route
 * tables buying page-one slots on AST-node mass):
 *
 *  1. SERIAL/PARALLEL PARITY. The lines-impact and composite score are
 *     pure member-range arithmetic plus canonical-tree shape reads.
 *     Every standard path carries Block::$canonical to the master
 *     (workers drop $ast but serialize canonical; IndexStore and
 *     ClusterCache round-trip it), so workers=1 and workers=2 MUST
 *     produce identical (id → impact|score) rows. A demotion leg that
 *     read $ast, or fired per-mode, would drift here.
 *
 *  2. DECLARATIVE-SHAPE ORDERING. The coroutine-predicate triplicate
 *     (real duplicated logic) out-scores the DTO-serializer, route-
 *     table and constructor-promotion families — and leads the corpus
 *     under --sort=score. Raw line impact stays comparable across all
 *     four (the lines quantity is honest); the composite is what buries
 *     the walls, exactly as the doc prescribes.
 */
final class E3RankingParityTest extends TestCase
{
    private const CORPUS = __DIR__ . '/../Fixtures/dto';

    public function testImpactAndScoreAreIdenticalSerialVsParallel(): void
    {
        $serial   = $this->runCorpus(1);
        $parallel = $this->runCorpus(2);

        $this->assertNotEmpty($serial, 'corpus produced no clusters (vacuous pin)');
        $this->assertSame(
            $this->keyed($serial),
            $this->keyed($parallel),
            '6.2 impact/score drifted between serial and parallel runs',
        );
    }

    public function testPredicateTriplicateOutscoresDtoSerializerRouteTableAndPromotion(): void
    {
        $clusters = $this->runCorpus(1);

        $pred  = $this->clusterByName($clusters, 'isSuspendable');
        $dto   = $this->clusterByName($clusters, 'toArray');
        $route = $this->clusterByName($clusters, 'routes');
        $promo = $this->clusterByName($clusters, '__construct');

        $this->assertSame(3, $pred->size(), 'predicate triplicate must have three members');
        $this->assertSame(4, $dto->size());
        $this->assertSame(2, $route->size());
        $this->assertSame(4, $promo->size());

        // Raw lines keep the honest magnitude on all four families —
        // demotion never touches the reported/gated impact.
        foreach ([$pred, $dto, $route, $promo] as $c) {
            $this->assertGreaterThanOrEqual(15, $c->impact, $c->id . ' impact should be line-scaled');
        }

        // The demotion leg itself (public seams): every member of the
        // three declarative clusters is hard-shape; the predicate body
        // (mixed statement kinds + two distinct callees) is untouched.
        $ranker = new Ranker(minImpact: 0);
        $this->assertSame(Ranker::DEMOTION_HARD, $ranker->declarativeDemotion($dto));
        $this->assertSame(Ranker::DEMOTION_HARD, $ranker->declarativeDemotion($route));
        $this->assertSame(Ranker::DEMOTION_HARD, $ranker->declarativeDemotion($promo));
        $this->assertSame(1.0, $ranker->declarativeDemotion($pred));

        // The scoring consequence: composite buries all three walls.
        $this->assertGreaterThan($dto->score, $pred->score);
        $this->assertGreaterThan($route->score, $pred->score);
        $this->assertGreaterThan($promo->score, $pred->score);

        // done-when leg: under the composite the predicate triplicate
        // leads the whole corpus (its inner if-block families are also
        // declarative-free and must not outrank the full method).
        $sorted = (new Ranker(minImpact: 0, sort: new ClusterSort('score', 'desc')))->rank($clusters);
        $this->assertSame($pred->id, $sorted[0]->id);
    }

    /**
     * @return list<Cluster>
     */
    private function runCorpus(int $workers): array
    {
        $config = new Config(
            paths: [self::CORPUS],
            exclude: [],
            minBlockSize: 5,
            normalizationMode: 'aggressive',
            similarityThreshold: 0.7,
            treeThreshold: 0.7,
            minClusterImpact: 0,
            workers: $workers,
            incremental: false,
        );

        $state = new PipelineState($config);
        $out   = new NullOutput();
        (new ScanningStage())->run($state, $out);
        (new PreprocessStage(useCache: false))->run($state, $out);
        (new ClusterStage(exactOnly: false, useClusterCache: false))->run($state, $out);
        (new RefactorStage(useCache: false))->run($state, $out);

        return (new Ranker($config->minClusterImpact))->rank($state->clusters);
    }

    /**
     * @param list<Cluster> $clusters
     * @return array<string,string>
     */
    private function keyed(array $clusters): array
    {
        $rows = [];
        foreach ($clusters as $c) {
            $rows[$c->id] = sprintf('%d|%.9F', $c->impact, $c->score);
        }
        ksort($rows);
        return $rows;
    }

    /**
     * @param list<Cluster> $clusters
     */
    private function clusterByName(array $clusters, string $memberName): Cluster
    {
        foreach ($clusters as $c) {
            foreach ($c->members as $m) {
                if ($m->name === $memberName) {
                    return $c;
                }
            }
        }
        $this->fail("no cluster contains a member named $memberName");
    }
}
