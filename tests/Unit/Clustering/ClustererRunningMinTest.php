<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Clustering;

use PHPUnit\Framework\TestCase;
use Phpdup\Clustering\Clusterer;
use Phpdup\Clustering\MatchTier;
use Phpdup\Extraction\Block;
use Phpdup\Extraction\BlockExtractor;
use Phpdup\Fingerprint\NgramFingerprint;
use Phpdup\Fingerprint\SubtreeHasher;
use Phpdup\Index\BlockIndex;
use Phpdup\Normalization\Normalizer;
use Phpdup\Parsing\AstParser;
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
 *
 * Edge fixtures are 4-tuples [a, b, sim, tier] exactly as
 * {@see Clusterer::cluster()} destructures them (its docblock contract):
 * the tier token carries a {@see MatchTier} value — the synthetic sub-1.0
 * scores mirror scored (Jaccard-tier) edges. A 3-tuple here would raise
 * "Undefined array key 3" inside the union loop, and phpunit.xml pins
 * failOnWarning.
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
     * @param list<array{0:string,1:string,2:float,3:string}> $edges
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
            ['A', 'C', 0.80, MatchTier::Jaccard->value],  // weakest → running min after this union
            ['A', 'B', 0.95, MatchTier::Jaccard->value],
            ['B', 'C', 0.85, MatchTier::Jaccard->value],
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
            ['A', 'B', 0.72, MatchTier::Jaccard->value],
            ['C', 'D', 0.91, MatchTier::Jaccard->value],
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
            ['A', 'C', 0.75, MatchTier::Jaccard->value],  // min
            ['A', 'B', 0.90, MatchTier::Jaccard->value],
            ['B', 'C', 0.88, MatchTier::Jaccard->value],
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
            ['A', 'B', 0.95, MatchTier::Jaccard->value],
            ['B', 'C', 0.88, MatchTier::Jaccard->value],
            ['C', 'D', 0.82, MatchTier::Jaccard->value],
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
            ['A', 'B', 0.62, MatchTier::Jaccard->value],
            ['B', 'C', 0.58, MatchTier::Jaccard->value],
            ['A', 'C', 0.55, MatchTier::Jaccard->value],
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

    // -----------------------------------------------------------------
    // engine-007 (half B) — Tier-2 confirmation honesty pins.
    //
    // These exercise the FULL scoring path (cluster() with no supplied
    // edges → scorePairsSerially), unlike the synthetic-edge running-min
    // cases above. They pin that the tree tier never rubber-stamps a
    // statement permutation with a Jaccard-tier 1.0 mint after the
    // flatten() size-sort was removed (engine-S08a): the sibling
    // sequence is the statement sequence, so the DP now charges the
    // reorder instead of collapsing it to edit distance 0.
    // -----------------------------------------------------------------

    /**
     * Reproduce what the preprocess stage feeds the scorer: parse →
     * extract → normalize, then hash + n-gram bag from the canonical
     * tree (mirrors PreprocessWorker's field population).
     */
    private function preprocessedBlock(string $code, string $mode, string $id): Block
    {
        $stmts  = (new AstParser())->parseCode($code);
        $blocks = (new BlockExtractor(minSize: 1))->extract('engine007.php', $stmts);
        $block  = $blocks[0];

        (new Normalizer($mode))->normalize($block);
        $block->id              = $id;
        $block->structuralHash  = (new SubtreeHasher())->hash($block->canonical);
        $block->ngramBag        = (new NgramFingerprint(n: 5))->fingerprint($block->canonical);

        return $block;
    }

    /**
     * engine-007 — the reviewer's canonical repro pair: swapped
     * UNEQUAL-size siblings (`$u = 1;` before `if($u){g();}` and after
     * it). Its bag Jaccard clears a deliberately loose similarity
     * floor, so the pair genuinely reaches the tree-confirming gate —
     * and must be REJECTED there. Under the old size-sorting flatten()
     * both bodies linearised to the same label sequence, the gate saw
     * tedSim 1.0000 ≥ 0.85, and the Jaccard tier minted the permuted
     * pair as a confirmed clone. With physical child order restored the
     * permutation is a real edit and no edge survives at all.
     */
    public function testPermutedUnequalSizePairNeverEarnsJaccardTierConfirmation(): void
    {
        foreach (['default', 'aggressive'] as $mode) {
            $a = $this->preprocessedBlock('<?php function f() { $u = 1; if ($u) { g(); } }', $mode, 'p1');
            $b = $this->preprocessedBlock('<?php function f() { if ($u) { g(); } $u = 1; }', $mode, 'p2');

            // The rejection below must be the tree tier doing its job,
            // not the pair never reaching it: pin that the bag score is
            // above the similarity floor we are about to use.
            $jac = (new JaccardSimilarity())->similarity($a->ngramBag, $b->ngramBag);
            $this->assertGreaterThanOrEqual(0.25, $jac,
                "[$mode] fixture broken: pair must reach the tree gate (jac $jac)");

            $index = new BlockIndex();
            $index->add($a);
            $index->add($b);

            $clusterer = new Clusterer(
                similarity: new JaccardSimilarity(),
                tree: new TreeEditDistance(),
                similarityThreshold: 0.25,
                treeThreshold: 0.85,
                maxDocumentFrequency: 1.0,
            );

            // treeThreshold 0.85 gates the confirm: a TED score below it
            // drops the edge (scorePairsSerially continues), so the pair
            // must produce no cluster. Pre-S08a this minted a Jaccard-tier
            // edge at min(jac, 1.0) — the confirmation lie.
            $clusters = $clusterer->cluster($index);
            $this->assertSame([], $clusters,
                "[$mode] statement-permuted pair must not be confirmed by the tree tier (engine-007)");
        }
    }

    /**
     * engine-007 — the equal-size swap from the finding
     * (`$u = 1; $v = 2;` vs `$v = 2; $u = 1;`). Under today's stack
     * the first-occurrence ordinals make the two canonical trees
     * byte-identical, so the pair lands in the Phase-1 EXACT-hash
     * bucket with a similarity of 1.0 — honest: identical trees really
     * are identical. What must NOT happen (the pre-fix lie) is a pair
     * whose structuralHashes differ earning a Jaccard-tier 1.0 through
     * the tree tier. Pin both halves of that statement.
     */
    public function testEqualSizeSwapMintsThroughExactTierNotAJaccardLie(): void
    {
        foreach (['default', 'aggressive'] as $mode) {
            $a = $this->preprocessedBlock('<?php function f() { $u = 1; $v = 2; }', $mode, 'p1');
            $b = $this->preprocessedBlock('<?php function f() { $v = 2; $u = 1; }', $mode, 'p2');

            $identicalTrees = $a->structuralHash === $b->structuralHash;
            $this->assertTrue($identicalTrees,
                "[$mode] fixture: ordinal renaming collapses this pair to identical canonical trees");

            $index = new BlockIndex();
            $index->add($a);
            $index->add($b);

            $clusterer = new Clusterer(
                similarity: new JaccardSimilarity(),
                tree: new TreeEditDistance(),
                similarityThreshold: 0.80,
                treeThreshold: 0.85,
                maxDocumentFrequency: 1.0,
            );

            $clusters = $clusterer->cluster($index);
            $this->assertCount(1, $clusters, "[$mode] identical-tree pair must cluster");
            $cluster = $clusters[0];
            $this->assertTrue($cluster->exact, "[$mode] must arrive via the exact-hash tier");
            $this->assertEqualsWithDelta(1.0, $cluster->similarity, 1e-9);

            foreach ($cluster->pairs as $pair) {
                $this->assertSame(MatchTier::ExactHash->value, $pair['matchTier'],
                    "[$mode] a 1.0 on permuted-but-identical trees must be stamped exact-hash, never jaccard (engine-007)");
            }

            // The tree tier never gets to lie here: any jaccard-tier mint
            // that ever appears for this index would carry min(jac, ted)
            // with ted < 1.0 (physical order now charges the swap).
            foreach ($cluster->pairs as $pair) {
                if ($pair['matchTier'] === MatchTier::Jaccard->value) {
                    $this->assertLessThan(1.0, $pair['matchScore'],
                        "[$mode] Jaccard tier must never mint 1.0 on order-permuted code (engine-007)");
                }
            }
        }
    }

    /**
     * engine-007 boundary documentation — the finding's p6 audit pair
     * (`log_audit($id); write_row($id);` and its reversal). Measured
     * truth after both legs of the fix:
     *
     *  - default mode: the names survive in the bags (jac 0.5789 < the
     *    0.80 floor) and the pair is never even confirmed-candidate;
     *  - aggressive mode: IdentifierIds::foldStamps (engine-002, an
     *    intentional cross-vocabulary contract) folds BOTH call names to
     *    the same token, so the two label sequences become literally
     *    identical and every tier of the similarity stack — ordered TED
     *    included — correctly reports 1.0. The remaining hash difference
     *    is the name-honest exact tier's doing, not a flatten() ordering
     *    bug.
     *
     * This pins the boundary so a future change cannot silently pass
     * EITHER direction: reordering a pair that still differs in folded
     * vocabulary must not mint, and this fold-equivalent pair's score is
     * a contract, not a regression.
     */
    public function testAuditReorderBoundaryIsFoldContractNotFlattenLie(): void
    {
        $base = '<?php function f() { log_audit($id); write_row($id); }';
        $perm = '<?php function f() { write_row($id); log_audit($id); }';

        $a = $this->preprocessedBlock($base, 'default', 'p1');
        $b = $this->preprocessedBlock($perm, 'default', 'p2');
        $jac = (new JaccardSimilarity())->similarity($a->ngramBag, $b->ngramBag);
        $this->assertLessThan(0.80, $jac,
            'default mode keeps call vocabulary distinct — the pair must not clear the similarity floor');

        $index = new BlockIndex();
        $index->add($a);
        $index->add($b);
        $strict = new Clusterer(
            similarity: new JaccardSimilarity(),
            tree: new TreeEditDistance(),
            similarityThreshold: 0.80,
            treeThreshold: 0.85,
            maxDocumentFrequency: 1.0,
        );
        $this->assertSame([], $strict->cluster($index),
            'default-mode audit reorder must not be minted at all');

        // Aggressive mode: the two bodies fold to the SAME token stream,
        // so their bag Jaccard is exactly 1.0 and TED of identical label
        // sequences is legitimately 0 — the mint is vocabulary-agnostic
        // by contract (engine-002), the one case where a 1.0 reorder
        // score is NOT the engine lying about order.
        $a2 = $this->preprocessedBlock($base, 'aggressive', 'p1');
        $b2 = $this->preprocessedBlock($perm, 'aggressive', 'p2');
        $folded = (new JaccardSimilarity())->similarity($a2->ngramBag, $b2->ngramBag);
        $this->assertEqualsWithDelta(1.0, $folded, 1e-9,
            'aggressive mode must fold both audit names (foldStamps contract)');
        $this->assertEqualsWithDelta(
            1.0,
            (new TreeEditDistance())->similarity($a2->canonical, $b2->canonical),
            1e-9,
            'identical folded label sequences are distance 0 even for an order-sensitive TED'
        );
    }
}
