<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Clustering;

use PHPUnit\Framework\TestCase;
use Phpdup\Clustering\Clusterer;
use Phpdup\Extraction\Block;
use Phpdup\Index\BlockIndex;
use Phpdup\Similarity\JaccardSimilarity;
use Phpdup\Similarity\TreeEditDistance;

/**
 * Verifies that the O(edges) running-min accumulation inside
 * {@see Clusterer::cluster()} produces the same cluster similarity
 * as a brute-force O(k²) scan of all member pairs.
 *
 * The fix replaced a nested for-loop over all k·(k-1)/2 member pairs
 * with a single pass over the edge list during union(), updating a
 * per-root running minimum.  This test asserts equality for synthetic
 * small components where the ground-truth minimum is known.
 */
final class ClustererRunningMinTest extends TestCase
{
    /**
     * Build a minimal Block that contributes to the index but is
     * invisible to hash-bucket (exact) clustering because every block
     * gets a unique structural hash.
     */
    private function makeSyntheticBlock(string $id, string $uniqueHash): Block
    {
        // Minimal "canonical" node — any valid PhpParser node works.
        $node = new \PhpParser\Node\Stmt\Return_(
            new \PhpParser\Node\Scalar\String_($id)
        );

        $block = new Block(
            file: 'synthetic.php',
            range: new \Phpdup\Util\LineRange(1, 1),
            kind: 'function',
            namespace: null,
            class: null,
            name: null,
            ast: $node,
        );
        $block->id = $id;
        // Unique hash per block → no exact clustering.
        $block->structuralHash = $uniqueHash;
        // Empty ngram bag → no n-gram candidates → edges must be
        // supplied explicitly via the $edges parameter.
        $block->ngramBag = [];
        $block->ngramBag = null;

        return $block;
    }

    /**
     * Brute-force O(k²) reference: scan all pairs in the cluster
     * and return the minimum edge similarity.
     *
     * @param list<array{0:string,1:string,2:float}> $edges
     * @param list<string> $memberIds
     */
    private function bruteForceMin(array $edges, array $memberIds): float
    {
        $min = 1.0;
        $count = count($memberIds);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $aId = $memberIds[$i];
                $bId = $memberIds[$j];
                $key = $aId < $bId ? "{$aId}|{$bId}" : "{$bId}|{$aId}";
                foreach ($edges as [$ea, $eb, $sim]) {
                    $ekey = $ea < $eb ? "{$ea}|{$eb}" : "{$eb}|{$ea}";
                    if ($ekey === $key) {
                        $min = min($min, $sim);
                        break;
                    }
                }
            }
        }
        return $min;
    }

    /**
     * Scenario: three blocks A, B, C where:
     *   - edges: (A,B)=0.95, (A,C)=0.80, (B,C)=0.85
     *   The minimum edge weight is 0.80 (A,C).
     *   After union(), all three must share the same root.
     *   Running-min should equal 0.80, matching brute-force O(k²).
     */
    public function testRunningMinMatchesBruteForceForThreeMemberChain(): void
    {
        $a = $this->makeSyntheticBlock('A', 'hash_a');
        $b = $this->makeSyntheticBlock('B', 'hash_b');
        $c = $this->makeSyntheticBlock('C', 'hash_c');

        $index = new BlockIndex();
        $index->add($a);
        $index->add($b);
        $index->add($c);

        // Edge order intentionally shuffled vs. insertion order.
        $edges = [
            ['A', 'C', 0.80],  // weakest → running min after this union
            ['A', 'B', 0.95],
            ['B', 'C', 0.85],
        ];

        $clusterer = new Clusterer(
            similarity: new JaccardSimilarity(),
            tree: new TreeEditDistance(),
            similarityThreshold: 0.0,
            treeThreshold: 0.0,
            maxDocumentFrequency: 1.0,
            exactOnly: false,
        );

        $clusters = $clusterer->cluster($index, $edges);

        $this->assertCount(1, $clusters, 'three blocks linked by edges must form one cluster');
        $cluster = $clusters[0];

        $memberIds = array_column($clusters[0]->members, 'id');
        $expectedMin = $this->bruteForceMin($edges, $memberIds);

        $this->assertEqualsWithDelta($expectedMin, $cluster->similarity, 1e-9,
            'running-min similarity must equal brute-force O(k²) minimum');
    }

    /**
     * Scenario: four blocks in two separate pairs, each pair forming
     * its own cluster:
     *   - Pair 1: (A,B)=0.72  → min=0.72
     *   - Pair 2: (C,D)=0.91  → min=0.91
     * Running-min per component must not leak between clusters.
     */
    public function testRunningMinIsPerComponentNotGlobal(): void
    {
        $a = $this->makeSyntheticBlock('A', 'hash_a');
        $b = $this->makeSyntheticBlock('B', 'hash_b');
        $c = $this->makeSyntheticBlock('C', 'hash_c');
        $d = $this->makeSyntheticBlock('D', 'hash_d');

        $index = new BlockIndex();
        $index->add($a);
        $index->add($b);
        $index->add($c);
        $index->add($d);

        $edges = [
            ['A', 'B', 0.72],
            ['C', 'D', 0.91],
        ];

        $clusterer = new Clusterer(
            similarity: new JaccardSimilarity(),
            tree: new TreeEditDistance(),
            similarityThreshold: 0.0,
            treeThreshold: 0.0,
            maxDocumentFrequency: 1.0,
        );

        $clusters = $clusterer->cluster($index, $edges);

        $this->assertCount(2, $clusters, 'two independent edge pairs must produce two clusters');

        $byId = [];
        foreach ($clusters as $c) {
            foreach ($c->members as $m) {
                $byId[$m->id] = $c;
            }
        }

        // Cluster AB similarity must be 0.72, not polluted by CD's 0.91.
        $clusterAB = $byId['A'];
        $this->assertSame($byId['B'], $clusterAB);
        $this->assertEqualsWithDelta(0.72, $clusterAB->similarity, 1e-9,
            'AB cluster similarity must be the min edge weight (A,B)=0.72');

        // Cluster CD similarity must be 0.91, not polluted by AB's 0.72.
        $clusterCD = $byId['C'];
        $this->assertSame($byId['D'], $clusterCD);
        $this->assertEqualsWithDelta(0.91, $clusterCD->similarity, 1e-9,
            'CD cluster similarity must be the min edge weight (C,D)=0.91');
    }

    /**
     * Scenario: three blocks where the weakest edge appears first
     * in the edge list (worst case for running-min if processed naively).
     *   - (A,C)=0.75 first, then (A,B)=0.90, then (B,C)=0.88
     *   - Minimum across all three edges: 0.75
     */
    public function testRunningMinWithWeakestEdgeFirst(): void
    {
        $a = $this->makeSyntheticBlock('A', 'hash_a');
        $b = $this->makeSyntheticBlock('B', 'hash_b');
        $c = $this->makeSyntheticBlock('C', 'hash_c');

        $index = new BlockIndex();
        $index->add($a);
        $index->add($b);
        $index->add($c);

        // Weakest edge first — tests that running-min correctly
        // propagates through subsequent unions.
        $edges = [
            ['A', 'C', 0.75],  // min
            ['A', 'B', 0.90],
            ['B', 'C', 0.88],
        ];

        $clusterer = new Clusterer(
            similarity: new JaccardSimilarity(),
            tree: new TreeEditDistance(),
            similarityThreshold: 0.0,
            treeThreshold: 0.0,
            maxDocumentFrequency: 1.0,
        );

        $clusters = $clusterer->cluster($index, $edges);

        $this->assertCount(1, $clusters);
        $memberIds = array_column($clusters[0]->members, 'id');
        $expectedMin = $this->bruteForceMin($edges, $memberIds);

        $this->assertEqualsWithDelta($expectedMin, $clusters[0]->similarity, 1e-9,
            'running-min must equal 0.75 (the A,C edge) regardless of edge order');
    }

    /**
     * Scenario: a linear chain of 4 blocks (A-B-C-D) where:
     *   - (A,B)=0.95, (B,C)=0.88, (C,D)=0.82
     *   - The minimum edge in the chain is 0.82 (C,D)
     *   - But the brute-force O(k²) across all 6 pairs only considers
     *     the 3 edges that actually exist (others have no edge → skip).
     *   - Expected running-min: 0.82
     */
    public function testRunningMinForFourBlockLinearChain(): void
    {
        $blocks = [];
        for ($i = 0; $i < 4; $i++) {
            $id = chr(ord('A') + $i);
            $blocks[] = $this->makeSyntheticBlock($id, "hash_{$id}");
        }

        $index = new BlockIndex();
        foreach ($blocks as $b) {
            $index->add($b);
        }

        $edges = [
            ['A', 'B', 0.95],
            ['B', 'C', 0.88],
            ['C', 'D', 0.82],
        ];

        $clusterer = new Clusterer(
            similarity: new JaccardSimilarity(),
            tree: new TreeEditDistance(),
            similarityThreshold: 0.0,
            treeThreshold: 0.0,
            maxDocumentFrequency: 1.0,
        );

        $clusters = $clusterer->cluster($index, $edges);

        $this->assertCount(1, $clusters, 'linear chain of 4 blocks must form a single cluster');
        $memberIds = array_column($clusters[0]->members, 'id');
        $expectedMin = $this->bruteForceMin($edges, $memberIds);

        $this->assertEqualsWithDelta($expectedMin, $clusters[0]->similarity, 1e-9,
            'running-min for the chain must be 0.82, the weakest of the three edges');
    }

    /**
     * Verify the running-min never returns a value higher than the
     * actual minimum edge in the cluster (the original O(k²) bug).
     * With the buggy implementation that scanned only existing pairs
     * but took the max instead of min, this would over-state similarity.
     */
    public function testRunningMinDoesNotOverstateClusterSimilarity(): void
    {
        $a = $this->makeSyntheticBlock('A', 'hash_a');
        $b = $this->makeSyntheticBlock('B', 'hash_b');
        $c = $this->makeSyntheticBlock('C', 'hash_c');

        $index = new BlockIndex();
        $index->add($a);
        $index->add($b);
        $index->add($c);

        // All edges are relatively low similarity.
        $edges = [
            ['A', 'B', 0.62],
            ['B', 'C', 0.58],
            ['A', 'C', 0.55],
        ];

        $clusterer = new Clusterer(
            similarity: new JaccardSimilarity(),
            tree: new TreeEditDistance(),
            similarityThreshold: 0.0,
            treeThreshold: 0.0,
            maxDocumentFrequency: 1.0,
        );

        $clusters = $clusterer->cluster($index, $edges);

        $this->assertCount(1, $clusters);
        // The cluster similarity must be <= every edge weight in the cluster.
        foreach ($edges as [, , $sim]) {
            $this->assertGreaterThanOrEqual($clusters[0]->similarity, $sim,
                'cluster similarity must be <= each edge weight');
        }
        // And it must equal the minimum edge weight exactly.
        $this->assertEqualsWithDelta(0.55, $clusters[0]->similarity, 1e-9,
            'cluster similarity must equal the weakest edge (A,C)=0.55');
    }
}
