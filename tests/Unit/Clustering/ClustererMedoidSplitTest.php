<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Clustering;

use PHPUnit\Framework\TestCase;
use Phpdup\Clustering\Cluster;
use Phpdup\Clustering\Clusterer;
use Phpdup\Clustering\MatchTier;
use Phpdup\Extraction\Block;
use Phpdup\Index\BlockIndex;
use Phpdup\Similarity\JaccardSimilarity;
use Phpdup\Similarity\TreeEditDistance;
use Phpdup\Util\LineRange;

/**
 * Item 1.4 (ruling §5b): the post-union-find greedy medoid-ball split.
 *
 * The hermetic stand-in for the pinned-corpus 167-member guard storm is
 * the synthetic chain fixture of filing 06 (§167-chain demo, reused with
 * the pinned-corpus erratum of the plan doc): N guard blocks whose ngram
 * windows overlap only within distance D, injected through the documented
 * `cluster(index, edges)` integration seam — the exact shape the parallel
 * PairScoreWorker stream feeds the master. J(i,j) = (36-d)/(36+d); the
 * weakest accepted edge sits exactly at the 0.8000 tier floor.
 *
 * Acceptance legs pinned here:
 *   - the 167-storm breaks into <= 25 groups (and > 1 group);
 *   - no member scores below the tier floor against its medoid;
 *   - every group is connected through accepted (>= floor) edges;
 *   - members partition exactly (nothing lost, nothing duplicated);
 *   - containment-bridged families are NOT false-split (§5b basis law);
 *   - residue semantics: >= 2 isolated members re-form under pre-split
 *     (legacy) similarity; a lone residue straggler attaches to its
 *     strongest group;
 *   - ids derive from sorted member content, identical under shuffled
 *     edge arrival order.
 */
final class ClustererMedoidSplitTest extends TestCase
{
    private const STORM_N = 167;
    private const STORM_WINDOW = 36;
    private const STORM_DIST = 4;
    private const FLOOR = 0.80;

    private function block(string $id, string $hash): Block
    {
        $b = new Block(
            file: 'synthetic.php',
            range: new LineRange(1, 1),
            kind: 'function',
            namespace: null,
            class: null,
            name: null,
            ast: new \PhpParser\Node\Stmt\Return_(new \PhpParser\Node\Scalar\String_($id)),
        );
        $b->id = $id;
        $b->structuralHash = $hash;
        // Null bag: the fixture injects edges explicitly (parallel path
        // seam), so no n-gram candidate enumeration runs.
        $b->ngramBag = null;
        return $b;
    }

    /** @param list<Block> $blocks */
    private function index(array $blocks): BlockIndex
    {
        $index = new BlockIndex();
        foreach ($blocks as $b) {
            $index->add($b);
        }
        return $index;
    }

    private function clusterer(bool $split = true, int $minSplitSize = 2): Clusterer
    {
        return new Clusterer(
            similarity: new JaccardSimilarity(),
            tree: new TreeEditDistance(),
            similarityThreshold: self::FLOOR,
            treeThreshold: 0.85,
            splitClusters: $split,
            minSplitSize: $minSplitSize,
        );
    }

    /**
     * @return array{0: list<Block>, 1: list<array{0:string,1:string,2:float,3:string}>}
     */
    private function stormFixture(): array
    {
        $blocks = [];
        $edges = [];
        for ($i = 0; $i < self::STORM_N; $i++) {
            $blocks[] = $this->block(sprintf('g%03d', $i), sprintf('h%03d', $i));
            for ($j = $i + 1; $j <= $i + self::STORM_DIST && $j < self::STORM_N; $j++) {
                $d = $j - $i;
                $edges[] = [
                    sprintf('g%03d', $i),
                    sprintf('g%03d', $j),
                    (self::STORM_WINDOW - $d) / (self::STORM_WINDOW + $d),
                    MatchTier::Jaccard->value,
                ];
            }
        }
        return [$blocks, $edges];
    }

    /**
     * Edge lookup over a cluster's accepted pairs: "blockA|blockB" in
     * either order -> matchScore. Pairs only ever contain gate-accepted
     * edges, so every score here is at or above the tier floor.
     *
     * @param Cluster $c
     * @return array<string, float>
     */
    private function edgeIndex(Cluster $c): array
    {
        $idx = [];
        foreach ($c->pairs as $p) {
            $idx[$p['blockA'] . '|' . $p['blockB']] = $p['matchScore'];
            $idx[$p['blockB'] . '|' . $p['blockA']] = $p['matchScore'];
        }
        return $idx;
    }

    /**
     * BFS connectivity of a group over its accepted (>= floor) edges.
     *
     * @param list<string>  $ids
     * @param array<string, float> $edges
     */
    private function groupIsConnected(array $ids, array $edges): bool
    {
        if ($ids === []) {
            return true;
        }
        $adj = [];
        foreach ($ids as $a) {
            foreach ($ids as $b) {
                if ($a !== $b && isset($edges["$a|$b"])) {
                    $adj[$a][] = $b;
                }
            }
        }
        $seen = [$ids[0] => true];
        $queue = [$ids[0]];
        while ($queue !== []) {
            foreach ($adj[array_shift($queue)] ?? [] as $next) {
                if (!isset($seen[$next])) {
                    $seen[$next] = true;
                    $queue[] = $next;
                }
            }
        }
        return count($seen) === count($ids);
    }

    /**
     * @param list<Cluster> $clusters
     * @return list<string>
     */
    private function signatures(array $clusters): array
    {
        $sigs = array_map(static function (Cluster $c): string {
            $ids = array_column($c->members, 'id');
            sort($ids);
            return $c->id . ':' . implode(',', $ids) . ':' . round($c->similarity, 9);
        }, $clusters);
        sort($sigs);
        return $sigs;
    }

    public function testStormWithoutSplitIsTheSingleLinkagePathology(): void
    {
        [$blocks, $edges] = $this->stormFixture();

        $clusters = $this->clusterer(split: false)->cluster($this->index($blocks), $edges);

        $this->assertCount(1, $clusters, 'pre-split union-find collapses the storm into one component');
        $this->assertCount(self::STORM_N, $clusters[0]->members);
        $this->assertEqualsWithDelta(self::FLOOR, $clusters[0]->similarity, 1e-9,
            'the 0.8000 label is the weakest edge on the connecting path — the facade 1.4 removes');
        $this->assertNull($clusters[0]->medoidId, 'no medoid semantics while the split is off');
    }

    public function testStormSplitsIntoAtMostTwentyFiveCoherentGroups(): void
    {
        [$blocks, $edges] = $this->stormFixture();

        $clusters = $this->clusterer()->cluster($this->index($blocks), $edges);

        $this->assertGreaterThan(1, count($clusters), 'the chain must break apart');
        $this->assertLessThanOrEqual(25, count($clusters), 'acceptance: <= 25 coherent groups');

        $coverage = [];
        foreach ($clusters as $c) {
            $ids = array_column($c->members, 'id');
            foreach ($ids as $id) {
                $this->assertArrayNotHasKey($id, $coverage, "block $id must live in exactly one group");
                $coverage[$id] = true;
            }
            $this->assertGreaterThanOrEqual(2, $c->size(), 'no single-member cluster is emitted');

            $edgesOfGroup = $this->edgeIndex($c);
            $this->assertTrue($this->groupIsConnected($ids, $edgesOfGroup),
                'every group must be connected through accepted (>= floor) edges');

            $this->assertNotNull($c->medoidId, 'split ball groups carry a medoid');
            $this->assertContains($c->medoidId, $ids, 'the medoid is a member of its group');
            foreach ($ids as $id) {
                if ($id === $c->medoidId) {
                    continue;
                }
                $score = $edgesOfGroup["$id|{$c->medoidId}"] ?? null;
                $this->assertNotNull($score, "member $id must be edge-adjacent to its medoid");
                $this->assertGreaterThanOrEqual(self::FLOOR - 1e-9, $score,
                    "acceptance: no member below the tier floor vs its medoid ($id)");
            }
        }
        $this->assertCount(self::STORM_N, $coverage, 'every storm member must be retained exactly once');
    }

    public function testReportedSimilarityIsWeakestMemberVsMedoidEdge(): void
    {
        // Triangle {A,B,C} at .90/.90/.85 (the .85 edge hides between two
        // non-medoid members) plus a .95 tail on medoid A. Old reporting:
        // component minimum .85. §5b correction: min of medoid edges .90.
        $blocks = [
            $this->block('A', 'hash_A'), $this->block('B', 'hash_B'),
            $this->block('C', 'hash_C'), $this->block('D', 'hash_D'),
        ];
        $edges = [
            ['A', 'B', 0.90, MatchTier::Jaccard->value],
            ['A', 'C', 0.90, MatchTier::Jaccard->value],
            ['B', 'C', 0.85, MatchTier::Jaccard->value],
            ['A', 'D', 0.95, MatchTier::Jaccard->value],
        ];

        $clusters = $this->clusterer()->cluster($this->index($blocks), $edges);

        $this->assertCount(1, $clusters, 'a clique-plus-tail ball covers everything');
        $this->assertSame('A', $clusters[0]->medoidId, 'hub member wins the degree tie-break');
        $this->assertEqualsWithDelta(0.90, $clusters[0]->similarity, 1e-9,
            'similarity corrected to weakest member-vs-medoid edge, not the hidden .85');
    }

    public function testLoneResidueAttachesToItsStrongestGroup(): void
    {
        // Chain A-B(.95) C(.88) D(.82): medoid B balls {A,B,C}; D is left
        // with no remaining neighbour -> lone residue -> attaches to the
        // group via its strongest edge (C,D)=.82. One cluster, legacy-
        // equivalent .82 (the pinned RunningMin shape).
        $blocks = [
            $this->block('A', 'hash_A'), $this->block('B', 'hash_B'),
            $this->block('C', 'hash_C'), $this->block('D', 'hash_D'),
        ];
        $edges = [
            ['A', 'B', 0.95, MatchTier::Jaccard->value],
            ['B', 'C', 0.88, MatchTier::Jaccard->value],
            ['C', 'D', 0.82, MatchTier::Jaccard->value],
        ];

        $clusters = $this->clusterer()->cluster($this->index($blocks), $edges);

        $this->assertCount(1, $clusters, 'a single residue member must never emit its own cluster');
        $ids = array_column($clusters[0]->members, 'id');
        sort($ids);
        $this->assertSame(['A', 'B', 'C', 'D'], $ids);
        $this->assertEqualsWithDelta(0.82, $clusters[0]->similarity, 1e-9);
        $this->assertSame('B', $clusters[0]->medoidId);
    }

    public function testResiduePairKeepsLegacyPreSplitSemantics(): void
    {
        // v1-L1 and v2-L2 leaves whose hubs get balled first: L1,L2 end as
        // a >=2 residue pool -> one residue cluster reporting the OLD
        // weakest-edge-on-connecting-path similarity (.88), medoid null.
        $blocks = [
            $this->block('M', 'hash_M'), $this->block('v1', 'hash_v1'),
            $this->block('v2', 'hash_v2'), $this->block('L1', 'hash_L1'),
            $this->block('L2', 'hash_L2'),
        ];
        $edges = [
            ['M', 'v1', 0.96, MatchTier::Jaccard->value],
            ['M', 'v2', 0.95, MatchTier::Jaccard->value],
            ['v1', 'L1', 0.90, MatchTier::Jaccard->value],
            ['v2', 'L2', 0.88, MatchTier::Jaccard->value],
        ];

        $clusters = $this->clusterer()->cluster($this->index($blocks), $edges);

        $this->assertCount(2, $clusters);
        $byMembers = [];
        foreach ($clusters as $c) {
            $ids = array_column($c->members, 'id');
            sort($ids);
            $byMembers[implode(',', $ids)] = $c;
        }
        $this->assertArrayHasKey('L1,L2', $byMembers, 'the residue pair re-forms as its own cluster');
        $residue = $byMembers['L1,L2'];
        $this->assertEqualsWithDelta(0.88, $residue->similarity, 1e-9,
            'residue cluster preserves the pre-split component-min similarity');
        $this->assertNull($residue->medoidId);
        $this->assertSame([], $residue->pairs, 'the residue pair shares no accepted edge');

        $this->assertArrayHasKey('M,v1,v2', $byMembers);
        $this->assertEqualsWithDelta(0.95, $byMembers['M,v1,v2']->similarity, 1e-9,
            'the ball reports weakest member-vs-medoid (min of .96/.95)');
    }

    public function testContainmentBridgedFamilyIsNotFalseSplit(): void
    {
        // Filing 06 Demo C (§5b basis law): a-b and b-c pass ONLY through
        // the containment tier (raw Jaccard below floor). Edge existence —
        // the tier-composite actually used to build edges — must keep the
        // family together; a raw-Jaccard splitter (CoherenceAnalyzer's
        // basis) would cut a-c apart into a false split.
        $blocks = [
            $this->block('a', 'hash_A'), $this->block('b', 'hash_B'), $this->block('c', 'hash_C'),
        ];
        $edges = [
            ['a', 'b', 0.94, MatchTier::Containment->value],
            ['b', 'c', 0.92, MatchTier::Containment->value],
        ];

        $clusters = $this->clusterer()->cluster($this->index($blocks), $edges);

        $this->assertCount(1, $clusters, 'containment-bridged chain stays one coherent group');
        $this->assertSame('b', $clusters[0]->medoidId);
        $this->assertEqualsWithDelta(0.92, $clusters[0]->similarity, 1e-9);
    }

    public function testExactHashComponentSurvivesIntact(): void
    {
        $blocks = [
            $this->block('A', 'same'), $this->block('B', 'same'), $this->block('C', 'same'),
        ];
        $edges = [
            ['A', 'B', 1.0, MatchTier::ExactHash->value],
            ['A', 'C', 1.0, MatchTier::ExactHash->value],
            ['B', 'C', 1.0, MatchTier::ExactHash->value],
        ];

        $clusters = $this->clusterer()->cluster($this->index($blocks), $edges);

        $this->assertCount(1, $clusters, 'a full-clique ball never splits');
        $this->assertTrue($clusters[0]->exact);
        $this->assertEqualsWithDelta(1.0, $clusters[0]->similarity, 1e-9);
    }

    public function testShuffledEdgeArrivalYieldsIdenticalClustersAndIds(): void
    {
        [$blocks, $edges] = $this->stormFixture();
        $index = $this->index($blocks);

        $baseline = $this->signatures($this->clusterer()->cluster($index, $edges));

        foreach ([1, 42, 1337] as $seed) {
            $shuffled = $edges;
            mt_srand($seed);
            for ($i = count($shuffled) - 1; $i > 0; $i--) {
                $j = mt_rand(0, $i);
                [$shuffled[$i], $shuffled[$j]] = [$shuffled[$j], $shuffled[$i]];
            }
            $this->assertSame(
                $baseline,
                $this->signatures($this->clusterer()->cluster($index, $shuffled)),
                "cluster set (ids, membership, similarity) must be invariant under edge arrival order ($seed)",
            );
        }
    }

    public function testClusterIdsAreContentDerivedAndStable(): void
    {
        $blocks = [$this->block('A', 'hash_A'), $this->block('B', 'hash_B')];
        $edges = [['A', 'B', 0.90, MatchTier::Jaccard->value]];

        $first = $this->clusterer()->cluster($this->index($blocks), $edges);
        $second = $this->clusterer()->cluster($this->index($blocks), array_reverse($edges));

        $this->assertMatchesRegularExpression('/^C[0-9a-f]{8}$/', $first[0]->id);
        $this->assertSame($first[0]->id, $second[0]->id, 'id = f(sorted member content), never arrival order');
    }

    public function testMinSplitSizeKnobFoldsBallsIntoResidue(): void
    {
        // Same shape as testReportedSimilarityIsWeakestMemberVsMedoidEdge,
        // but with the floor raised past the ball size: the whole component
        // lands in the residue pool and keeps the legacy .85 reporting.
        $blocks = [
            $this->block('A', 'hash_A'), $this->block('B', 'hash_B'),
            $this->block('C', 'hash_C'), $this->block('D', 'hash_D'),
        ];
        $edges = [
            ['A', 'B', 0.90, MatchTier::Jaccard->value],
            ['A', 'C', 0.90, MatchTier::Jaccard->value],
            ['B', 'C', 0.85, MatchTier::Jaccard->value],
            ['A', 'D', 0.95, MatchTier::Jaccard->value],
        ];

        $clusters = $this->clusterer(minSplitSize: 5)->cluster($this->index($blocks), $edges);

        $this->assertCount(1, $clusters);
        $this->assertNull($clusters[0]->medoidId, 'sub-floor balls dissolve to the legacy residue cluster');
        $this->assertEqualsWithDelta(0.85, $clusters[0]->similarity, 1e-9);
    }

    public function testInvalidMinSplitSizeFailsFast(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->clusterer(minSplitSize: 1);
    }
}
