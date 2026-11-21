<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Clustering;

use PHPUnit\Framework\TestCase;
use Phpdup\Clustering\Clusterer;
use Phpdup\Clustering\MatchTier;
use Phpdup\Clustering\Cluster;
use Phpdup\Clustering\UnionFind;
use Phpdup\Extraction\Block;
use Phpdup\Extraction\BlockExtractor;
use Phpdup\Fingerprint\NgramFingerprint;
use Phpdup\Fingerprint\SubtreeHasher;
use Phpdup\Index\BlockIndex;
use Phpdup\Normalization\Normalizer;
use Phpdup\Parsing\AstParser;
use Phpdup\Similarity\JaccardSimilarity;
use Phpdup\Similarity\TreeEditDistance;
use Symfony\Component\Console\Output\NullOutput;

final class ClustererTest extends TestCase
{
    private function parseFunction(string $code): \PhpParser\Node\Stmt\Function_
    {
        $stmts = (new AstParser())->parseCode($code);
        return $stmts[0];
    }

    private function makeBlock(
        string $id,
        string $structuralHash,
        array $ngrams,
        \PhpParser\Node\Stmt\Function_ $canonical,
    ): Block {
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
        $block->structuralHash = $structuralHash;
        $block->ngramBag = $ngrams;
        $block->canonical = $canonical;

        return $block;
    }

    public function testDeterminismStableClusterIdsAcrossMultipleRuns(): void
    {
        $funcA = $this->parseFunction('<?php function a() { $x = 1; return $x; }');
        $funcB = $this->parseFunction('<?php function b() { $x = 1; return $x; }');
        $funcC = $this->parseFunction('<?php function c() { $y = 2; return $y; }');
        $funcD = $this->parseFunction('<?php function d() { $y = 2; return $y; }');

        $blocks = [
            $this->makeBlock('A', 'hash_1', ['gram_a' => 1, 'gram_b' => 1], $funcA),
            $this->makeBlock('B', 'hash_1', ['gram_a' => 1, 'gram_b' => 1], $funcB),
            $this->makeBlock('C', 'hash_2', ['gram_b' => 1, 'gram_c' => 1], $funcC),
            $this->makeBlock('D', 'hash_2', ['gram_b' => 1, 'gram_c' => 1], $funcD),
        ];

        $run1 = $this->runClustering($blocks);
        $run2 = $this->runClustering($blocks);

        $this->assertEquals(
            count($run1),
            count($run2),
            'Both runs must produce the same number of clusters'
        );

        foreach ($run1 as $i => $cluster1) {
            $cluster2 = $run2[$i];
            $ids1 = array_column($cluster1->members, 'id');
            $ids2 = array_column($cluster2->members, 'id');
            sort($ids1);
            sort($ids2);
            $this->assertEquals(
                $ids1,
                $ids2,
                sprintf('Cluster %d member IDs must be identical across runs', $i + 1)
            );
            $this->assertEquals(
                $cluster1->id,
                $cluster2->id,
                sprintf('Cluster %d ID must be identical across runs', $i + 1)
            );
        }
    }

    /**
     * @param list<Block> $blocks
     * @return list<Cluster>
     */
    private function runClustering(array $blocks): array
    {
        $index = new BlockIndex();
        foreach ($blocks as $b) {
            $index->add($b);
        }

        $clusterer = new Clusterer(
            similarity: new JaccardSimilarity(),
            tree: new TreeEditDistance(),
            similarityThreshold: 0.0,
            treeThreshold: 0.0,
            maxDocumentFrequency: 1.0,
            exactOnly: false,
            optionalBlocksEnabled: false,
        );

        return $clusterer->cluster($index);
    }

    public function testThresholdEdgesSimilarityAboveThresholdIncluded(): void
    {
        $funcHigh = $this->parseFunction('<?php function high() { $x = 1; $y = 2; $z = 3; return $x + $y + $z; }');
        $funcShared = $this->parseFunction('<?php function shared() { $x = 1; $y = 2; $z = 3; return $x + $y + $z; }');

        $sharedNgrams = ['gram_a' => 1, 'gram_b' => 1, 'gram_c' => 1, 'gram_d' => 1, 'gram_e' => 1];
        $uniqueNgrams = ['gram_unique' => 1];

        $blockHigh = $this->makeBlock('high', 'hash_high', array_merge($sharedNgrams, $uniqueNgrams), $funcHigh);
        $blockShared = $this->makeBlock('shared', 'hash_shared', $sharedNgrams, $funcShared);

        $index = new BlockIndex();
        $index->add($blockHigh);
        $index->add($blockShared);

        $clusterer = new Clusterer(
            similarity: new JaccardSimilarity(),
            tree: new TreeEditDistance(),
            similarityThreshold: 0.80,
            treeThreshold: 0.0,
            maxDocumentFrequency: 1.0,
            exactOnly: false,
            optionalBlocksEnabled: false,
        );

        $edges = [
            ['high', 'shared', 0.85, MatchTier::Jaccard->value],
        ];

        $clusters = $clusterer->cluster($index, $edges);

        $memberIds = [];
        foreach ($clusters as $cluster) {
            foreach ($cluster->members as $member) {
                $memberIds[] = $member->id;
            }
        }

        $this->assertContains('high', $memberIds, 'Block pair at 0.85 (> threshold) must be in a cluster');
        $this->assertContains('shared', $memberIds);
    }

    public function testThresholdEdgesSimilarityBelowThresholdCreatesClusterWhenEdgesPassedDirectly(): void
    {
        $funcLow = $this->parseFunction('<?php function low() { $a = 1; $b = 2; $c = 3; $d = 4; $e = 5; $f = 6; $g = 7; $h = 8; return $a + $b + $c + $d + $e + $f + $g + $h; }');
        $funcFar = $this->parseFunction('<?php function far() { $x = 1; return $x; }');

        $lowNgrams = ['rare_1' => 1, 'rare_2' => 1, 'rare_3' => 1, 'rare_4' => 1];
        $farNgrams = ['different_1' => 1, 'different_2' => 1, 'different_3' => 1, 'different_4' => 1];

        $blockLow = $this->makeBlock('low', 'hash_low', $lowNgrams, $funcLow);
        $blockFar = $this->makeBlock('far', 'hash_far', $farNgrams, $funcFar);

        $index = new BlockIndex();
        $index->add($blockLow);
        $index->add($blockFar);

        $clusterer = new Clusterer(
            similarity: new JaccardSimilarity(),
            tree: new TreeEditDistance(),
            similarityThreshold: 0.80,
            treeThreshold: 0.0,
            maxDocumentFrequency: 1.0,
            exactOnly: false,
            optionalBlocksEnabled: false,
        );

        $edges = [
            ['low', 'far', 0.50, MatchTier::Jaccard->value],
        ];

        $clusters = $clusterer->cluster($index, $edges);

        $this->assertCount(1, $clusters, 'Edge at 0.50 creates a cluster when passed directly (threshold filtering only applies during serial scoring with edges=null)');

        $memberIds = array_column($clusters[0]->members, 'id');
        $this->assertContains('low', $memberIds);
        $this->assertContains('far', $memberIds);
    }

    public function testThresholdEdgesExactBoundaryTreeThreshold(): void
    {
        $funcA = $this->parseFunction('<?php function f($x) { if ($x > 10) { return "a"; } return "b"; }');
        $funcB = $this->parseFunction('<?php function g($y) { if ($y > 20) { return "x"; } return "y"; }');
        $funcC = $this->parseFunction('<?php function h($z) { if ($z > 5) { return "p"; } return "q"; }');
        $funcD = $this->parseFunction('<?php function i($w) { if ($w > 100) { return "m"; } return "n"; }');

        $blockA = $this->makeBlock('A', 'hash_a', ['gram_x' => 1], $funcA);
        $blockB = $this->makeBlock('B', 'hash_b', ['gram_x' => 1], $funcB);
        $blockC = $this->makeBlock('C', 'hash_c', ['gram_x' => 1], $funcC);
        $blockD = $this->makeBlock('D', 'hash_d', ['gram_x' => 1], $funcD);

        $index = new BlockIndex();
        $index->add($blockA);
        $index->add($blockB);
        $index->add($blockC);
        $index->add($blockD);

        $treeThreshold = 0.85;
        $clusterer = new Clusterer(
            similarity: new JaccardSimilarity(),
            tree: new TreeEditDistance(),
            similarityThreshold: 0.0,
            treeThreshold: $treeThreshold,
            maxDocumentFrequency: 1.0,
            exactOnly: false,
            optionalBlocksEnabled: false,
        );

        $edges = [
            ['A', 'B', 0.86, MatchTier::Jaccard->value],
            ['C', 'D', 0.84, MatchTier::Jaccard->value],
        ];

        $clusters = $clusterer->cluster($index, $edges);

        $memberIds = [];
        foreach ($clusters as $cluster) {
            foreach ($cluster->members as $member) {
                $memberIds[] = $member->id;
            }
        }

        $this->assertContains('A', $memberIds, 'Block pair at 0.86 must be in a cluster');
        $this->assertContains('B', $memberIds, 'Block pair at 0.86 must be in a cluster');
        $this->assertContains('C', $memberIds, 'Block pair at 0.84 must also be in a cluster (edges are used as-is when passed directly)');
        $this->assertContains('D', $memberIds, 'Block pair at 0.84 must also be in a cluster (edges are used as-is when passed directly)');
    }

    public function testUnionFindChainAllFourBlocksInSameCluster(): void
    {
        $funcA = $this->parseFunction('<?php function a() { return 1; }');
        $funcB = $this->parseFunction('<?php function b() { return 2; }');
        $funcC = $this->parseFunction('<?php function c() { return 3; }');
        $funcD = $this->parseFunction('<?php function d() { return 4; }');

        $blockA = $this->makeBlock('A', 'hash_a', ['gram_1' => 1], $funcA);
        $blockB = $this->makeBlock('B', 'hash_b', ['gram_1' => 1, 'gram_2' => 1], $funcB);
        $blockC = $this->makeBlock('C', 'hash_c', ['gram_2' => 1, 'gram_3' => 1], $funcC);
        $blockD = $this->makeBlock('D', 'hash_d', ['gram_3' => 1], $funcD);

        $index = new BlockIndex();
        $index->add($blockA);
        $index->add($blockB);
        $index->add($blockC);
        $index->add($blockD);

        $clusterer = new Clusterer(
            similarity: new JaccardSimilarity(),
            tree: new TreeEditDistance(),
            similarityThreshold: 0.0,
            treeThreshold: 0.0,
            maxDocumentFrequency: 1.0,
            exactOnly: false,
            optionalBlocksEnabled: false,
        );

        $edges = [
            ['A', 'B', 0.95, MatchTier::Jaccard->value],
            ['B', 'C', 0.90, MatchTier::Jaccard->value],
            ['C', 'D', 0.85, MatchTier::Jaccard->value],
        ];

        $clusters = $clusterer->cluster($index, $edges);

        $this->assertCount(1, $clusters, 'A-B, B-C, C-D chain must produce exactly one cluster');

        $memberIds = array_column($clusters[0]->members, 'id');
        $this->assertContains('A', $memberIds);
        $this->assertContains('B', $memberIds);
        $this->assertContains('C', $memberIds);
        $this->assertContains('D', $memberIds);
        $this->assertCount(4, $memberIds, 'All four blocks must be in the same cluster');
    }

    public function testUnionFindBlocksBelowThresholdNeverJoinCluster(): void
    {
        $funcX = $this->parseFunction('<?php function x() { return 1; }');
        $funcY = $this->parseFunction('<?php function y() { return 2; }');
        $funcA = $this->parseFunction('<?php function a() { return 10; }');
        $funcB = $this->parseFunction('<?php function b() { return 20; }');
        $funcC = $this->parseFunction('<?php function c() { return 30; }');
        $funcD = $this->parseFunction('<?php function d() { return 40; }');

        $blockX = $this->makeBlock('X', 'hash_x', ['gram_x' => 1], $funcX);
        $blockY = $this->makeBlock('Y', 'hash_y', ['gram_x' => 1], $funcY);

        $blockA = $this->makeBlock('A', 'hash_a', ['gram_1' => 1], $funcA);
        $blockB = $this->makeBlock('B', 'hash_b', ['gram_1' => 1, 'gram_2' => 1], $funcB);
        $blockC = $this->makeBlock('C', 'hash_c', ['gram_2' => 1, 'gram_3' => 1], $funcC);
        $blockD = $this->makeBlock('D', 'hash_d', ['gram_3' => 1], $funcD);

        $index = new BlockIndex();
        $index->add($blockX);
        $index->add($blockY);
        $index->add($blockA);
        $index->add($blockB);
        $index->add($blockC);
        $index->add($blockD);

        $clusterer = new Clusterer(
            similarity: new JaccardSimilarity(),
            tree: new TreeEditDistance(),
            similarityThreshold: 0.0,
            treeThreshold: 0.0,
            maxDocumentFrequency: 1.0,
            exactOnly: false,
            optionalBlocksEnabled: false,
        );

        $edges = [
            ['A', 'B', 0.95, MatchTier::Jaccard->value],
            ['B', 'C', 0.90, MatchTier::Jaccard->value],
            ['C', 'D', 0.85, MatchTier::Jaccard->value],
        ];

        $clusters = $clusterer->cluster($index, $edges);

        $chainMemberIds = ['A', 'B', 'C', 'D'];
        $nonChainMemberIds = ['X', 'Y'];

        $allClusterMemberIds = [];
        foreach ($clusters as $cluster) {
            foreach ($cluster->members as $member) {
                $allClusterMemberIds[] = $member->id;
            }
        }

        foreach ($chainMemberIds as $id) {
            $this->assertContains($id, $allClusterMemberIds, "Chain member $id must be in a cluster");
        }

        foreach ($nonChainMemberIds as $id) {
            $this->assertNotContains($id, $allClusterMemberIds, "Non-chain member $id must NOT be in any cluster");
        }

        $this->assertCount(1, $clusters, 'Only the A-B-C-D chain should form a cluster');
    }

    public function testMultipleDisconnectedPairsFormSeparateClusters(): void
    {
        $funcA = $this->parseFunction('<?php function a() { return 1; }');
        $funcB = $this->parseFunction('<?php function b() { return 2; }');
        $funcC = $this->parseFunction('<?php function c() { return 3; }');
        $funcD = $this->parseFunction('<?php function d() { return 4; }');

        $blockA = $this->makeBlock('A', 'hash_a', ['gram_a' => 1], $funcA);
        $blockB = $this->makeBlock('B', 'hash_b', ['gram_a' => 1], $funcB);

        $blockC = $this->makeBlock('C', 'hash_c', ['gram_c' => 1], $funcC);
        $blockD = $this->makeBlock('D', 'hash_d', ['gram_c' => 1], $funcD);

        $index = new BlockIndex();
        $index->add($blockA);
        $index->add($blockB);
        $index->add($blockC);
        $index->add($blockD);

        $clusterer = new Clusterer(
            similarity: new JaccardSimilarity(),
            tree: new TreeEditDistance(),
            similarityThreshold: 0.0,
            treeThreshold: 0.0,
            maxDocumentFrequency: 1.0,
            exactOnly: false,
            optionalBlocksEnabled: false,
        );

        $edges = [
            ['A', 'B', 0.95, MatchTier::Jaccard->value],
            ['C', 'D', 0.91, MatchTier::Jaccard->value],
        ];

        $clusters = $clusterer->cluster($index, $edges);

        $this->assertCount(2, $clusters, 'Two disconnected pairs must produce two separate clusters');

        $abCluster = null;
        $cdCluster = null;
        foreach ($clusters as $cluster) {
            $ids = array_column($cluster->members, 'id');
            if (in_array('A', $ids) && in_array('B', $ids)) {
                $abCluster = $cluster;
            }
            if (in_array('C', $ids) && in_array('D', $ids)) {
                $cdCluster = $cluster;
            }
        }

        $this->assertNotNull($abCluster, 'AB cluster must exist');
        $this->assertNotNull($cdCluster, 'CD cluster must exist');
        $this->assertNotSame($abCluster, $cdCluster, 'AB and CD must be separate cluster objects');
    }

    /**
     * engine-023 pin: a Block whose canonical tree was never stamped (it
     * never went through Normalizer) must be SKIPPED by the §5a R-4 guard
     * projection inside generateCandidatePairs(), not explode. The old
     * check read `$b->canonical === null` — on a non-nullable typed
     * property without default that access itself throws before the
     * comparison can happen, turning the intended skip into a fatal.
     */
    public function testUnnumberedCandidateGuardSkipsUnnormalizedBlockWithoutFatal(): void
    {
        $funcA = $this->parseFunction('<?php function a() { $v = 1; return $v; }');
        $funcB = $this->parseFunction('<?php function b() { $v = 1; return $v; }');

        $blockA = $this->makeBlock('A', 'hash_a', ['gram_1' => 1, 'gram_2' => 1], $funcA);
        $blockB = $this->makeBlock('B', 'hash_b', ['gram_1' => 1, 'gram_2' => 1], $funcB);

        // Block C: built exactly like makeBlock() does, but deliberately
        // WITHOUT assigning ->canonical — an unnormalized block.
        $blockC = new Block(
            file: 'synthetic.php',
            range: new \Phpdup\Util\LineRange(1, 1),
            kind: 'function',
            namespace: null,
            class: null,
            name: null,
            ast: new \PhpParser\Node\Stmt\Return_(new \PhpParser\Node\Scalar\String_('C')),
        );
        $blockC->id = 'C';
        $blockC->structuralHash = 'hash_c';
        $blockC->ngramBag = ['gram_9' => 1];

        $this->assertFalse(isset($blockC->canonical), 'fixture C must be unnormalized');

        $index = new BlockIndex();
        $index->add($blockA);
        $index->add($blockB);
        $index->add($blockC);

        $clusterer = new Clusterer(
            similarity: new JaccardSimilarity(),
            tree: new TreeEditDistance(),
            similarityThreshold: 0.0,
            treeThreshold: 0.0,
            maxDocumentFrequency: 1.0,
            exactOnly: false,
            optionalBlocksEnabled: false,
            unnumberedCandidateGuard: true,
        );

        $pairs = [];
        foreach ($clusterer->generateCandidatePairs($index, new NullOutput()) as [$aId, $bId]) {
            $pairs[] = [$aId, $bId];
        }

        $this->assertSame([['A', 'B']], $pairs, 'The normalized pair is still proposed; the unnormalized block is skipped, no Error fatal');
    }

    /**
     * engine-023: this is what the OLD `$b->canonical === null` guard hit
     * on an unnormalized block — a hard Error before any comparison. The
     * isset() guard in generateCandidatePairs() is the fix; this pin
     * documents the failure mode it prevents.
     */
    public function testCanonicalAccessOnUnnormalizedBlockRaisesTypedPropertyError(): void
    {
        $block = new Block(
            file: 'synthetic.php',
            range: new \Phpdup\Util\LineRange(1, 1),
            kind: 'function',
            namespace: null,
            class: null,
            name: null,
            ast: new \PhpParser\Node\Stmt\Return_(new \PhpParser\Node\Scalar\String_('X')),
        );

        try {
            $access = $block->canonical;
            $this->fail(sprintf(
                'Expected typed-property Error, got %s instead',
                is_object($access) ? get_class($access) : gettype($access),
            ));
        } catch (\Error $e) {
            $this->assertStringContainsString('must not be accessed before initialization', $e->getMessage());
        }
    }

    /**
     * Clusterer configured so any well-formed edge clusters (thresholds
     * off) — paired with makeGuardIndex(), which carries ids 'A' and 'B'.
     */
    private function makeGuardClusterer(): Clusterer
    {
        return new Clusterer(
            similarity: new JaccardSimilarity(),
            tree: new TreeEditDistance(),
            similarityThreshold: 0.0,
            treeThreshold: 0.0,
            maxDocumentFrequency: 1.0,
            exactOnly: false,
            optionalBlocksEnabled: false,
        );
    }

    private function makeGuardIndex(): BlockIndex
    {
        $index = new BlockIndex();
        $funcA = $this->parseFunction('<?php function a() { return 1; }');
        $funcB = $this->parseFunction('<?php function b() { return 2; }');
        $index->add($this->makeBlock('A', 'hash_a', ['gram_x' => 1], $funcA));
        $index->add($this->makeBlock('B', 'hash_b', ['gram_x' => 1], $funcB));

        return $index;
    }

    /**
     * engine-049 pin (edges-loop seam): an externally supplied edge whose
     * SECOND endpoint is absent from the index must fail loud with a
     * RuntimeException naming the id — never the old shape: the unguarded
     * `$this->parent[$x]` read in UnionFind::find() emitted an
     * undefined-array-key warning (fatal here: phpunit failOnWarning=true)
     * and/or a TypeError through the strict `: string` return, poisoning
     * the forest with ""-keyed state. Catching RuntimeException cannot
     * swallow an Error/TypeError — they are siblings of Exception, so a
     * regression propagates and fails this test loudly.
     */
    public function testEdgeWithUnknownSecondEndpointThrowsRuntimeExceptionNamingId(): void
    {
        $clusterer = $this->makeGuardClusterer();
        $index = $this->makeGuardIndex();

        try {
            $clusterer->cluster($index, [['A', 'GHOST', 0.95, MatchTier::Jaccard->value]]);
            $this->fail('Expected RuntimeException for edge referencing unknown id GHOST');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('GHOST', $e->getMessage(), 'the exception must name the offending id');
        }
    }

    /**
     * engine-049 pin: symmetric endpoint — the unknown id in FIRST position
     * is named just the same.
     */
    public function testEdgeWithUnknownFirstEndpointThrowsRuntimeExceptionNamingId(): void
    {
        $clusterer = $this->makeGuardClusterer();
        $index = $this->makeGuardIndex();

        try {
            $clusterer->cluster($index, [['GHOST', 'B', 0.95, MatchTier::Jaccard->value]]);
            $this->fail('Expected RuntimeException for edge referencing unknown id GHOST');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('GHOST', $e->getMessage(), 'the exception must name the offending id');
        }
    }

    /**
     * engine-049: the clean-throw layer — find() on an id that was never
     * added throws LogicException naming it (no warning, no TypeError, no
     * ""-keyed garbage path). Guards the primitive itself for any future
     * caller that skips the cluster() seam.
     */
    public function testUnionFindFindOnUnknownIdThrowsLogicExceptionNamingId(): void
    {
        $uf = new UnionFind();
        $uf->add('A');

        $this->assertTrue($uf->contains('A'));
        $this->assertFalse($uf->contains('GHOST'));

        try {
            $uf->find('GHOST');
            $this->fail('Expected LogicException for find() on an id never added');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('GHOST', $e->getMessage(), 'the exception must name the offending id');
        }
    }

    /**
     * engine-049: union() inherits the guard through find() and leaves the
     * forest untouched — the impossible id was never inserted and the
     * legitimate member keeps its own root.
     */
    public function testUnionFindUnionOnUnknownIdThrowsAndLeavesForestClean(): void
    {
        $uf = new UnionFind();
        $uf->add('A');

        try {
            $uf->union('A', 'GHOST');
            $this->fail('Expected LogicException for union() on an id never added');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('GHOST', $e->getMessage());
        }

        $this->assertFalse($uf->contains('GHOST'), 'the failed union must not insert the impossible id');
        $this->assertSame('A', $uf->find('A'), 'the existing component must be unchanged');
    }

    /**
     * engine-049 positive control: the seam is inert for well-formed edge
     * lists — ids resident in the index cluster exactly as before (this is
     * the zero-golden-move argument: production edge stores are
     * same-run-index resident, so no observable clustering path changes).
     */
    public function testSeamGuardInertForIndexResidentEdgeEndpoints(): void
    {
        $clusterer = $this->makeGuardClusterer();
        $index = $this->makeGuardIndex();

        $clusters = $clusterer->cluster($index, [['A', 'B', 0.95, MatchTier::Jaccard->value]]);

        $this->assertCount(1, $clusters, 'the well-formed edge must cluster A and B, guard silent');
        $ids = array_column($clusters[0]->members, 'id');
        sort($ids);
        $this->assertSame(['A', 'B'], $ids);
    }

    /**
     * Lightweight synthetic block for edge-injected seams: canonical and
     * ngram bag stay null because the tests pass pre-computed edges
     * straight to cluster() — no candidate enumeration, no TED.
     */
    private function edgeBlock(string $id, string $hash): Block
    {
        $block = new Block(
            file: 'synthetic.php',
            range: new \Phpdup\Util\LineRange(1, 1),
            kind: 'function',
            namespace: null,
            class: null,
            name: null,
            ast: new \PhpParser\Node\Stmt\Return_(new \PhpParser\Node\Scalar\String_($id)),
        );
        $block->id             = $id;
        $block->structuralHash = $hash;
        $block->ngramBag       = null;
        return $block;
    }

    /**
     * Deterministic seeded shuffle (no global RNG state): orders $items by
     * md5("$seed|$index") bytes, so the "scramble" is stable across PHP
     * versions and machines.
     *
     * @template T @param list<T> $items @return list<T>
     */
    private function seededShuffle(array $items, string $seed): array
    {
        $tagged = [];
        foreach ($items as $i => $item) {
            $tagged[] = [md5($seed . '|' . $i), $i, $item];
        }
        usort($tagged, static fn (array $x, array $y): int => strcmp($x[0], $y[0]) ?: ($x[1] <=> $y[1]));
        return array_map(static fn (array $row) => $row[2], $tagged);
    }

    /**
     * Reference for the PRE-engine-029 behaviour: reconstruct the global
     * strcmp-canonical edge table (last arrival wins per key) exactly as
     * the old union loop built it, then run the old O(k²/2) member-pair
     * scan over $members verbatim. Production must match it byte-for-byte.
     *
     * @param list<Block> $members
     * @param list<array{0:string,1:string,2:float,3:string}> $edges
     * @return list<array{blockA: string, blockB: string, matchTier: string, matchScore: float}>
     */
    private function legacyBuildPairs(array $members, array $edges): array
    {
        $edgeMap = [];
        foreach ($edges as [$aId, $bId, $sim, $tier]) {
            $key = strcmp($aId, $bId) < 0 ? $aId . '|' . $bId : $bId . '|' . $aId;
            $edgeMap[$key] = ['sim' => $sim, 'tier' => $tier];
        }
        $pairs  = [];
        $count  = count($members);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $aId = $members[$i]->id;
                $bId = $members[$j]->id;
                $key = strcmp($aId, $bId) < 0 ? $aId . '|' . $bId : $bId . '|' . $aId;
                if (!isset($edgeMap[$key])) continue;
                $pairs[] = [
                    'blockA'     => $aId,
                    'blockB'     => $bId,
                    'matchTier'  => $edgeMap[$key]['tier'],
                    'matchScore' => $edgeMap[$key]['sim'],
                ];
            }
        }
        return $pairs;
    }

    /**
     * engine-029, public seam: a CHAIN-shaped component (k members, k-1
     * edges — edges ≪ k², the shape the acceptance names) fed through
     * cluster() in scrambled arrival order and mixed orientation. The
     * emitted Cluster::$pairs must be byte-identical to the legacy
     * member-pair scan's output: same 39 pairs, same order, same fields.
     */
    public function testChainComponentPairsMatchLegacyScanUnderScrambledArrival(): void
    {
        $k     = 40;
        $blocks = [];
        $edges  = [];
        for ($i = 0; $i < $k; $i++) {
            $blocks[] = $this->edgeBlock(sprintf('c%02d', $i), sprintf('h%02d', $i));
            if ($i + 1 < $k) {
                $edges[] = [sprintf('c%02d', $i), sprintf('c%02d', $i + 1), 0.5 + 0.01 * $i, MatchTier::Jaccard->value];
            }
        }

        $index = new BlockIndex();
        foreach ($blocks as $b) {
            $index->add($b);
        }

        // Scramble arrival AND flip half the orientations: bucket order
        // must be made maximally different from the scan order.
        $scrambled = $this->seededShuffle($edges, 'engine-029-chain');
        foreach ($scrambled as $i => $edge) {
            if ($i % 2 === 0) {
                $scrambled[$i] = [$edge[1], $edge[0], $edge[2], $edge[3]];
            }
        }

        $clusterer = new Clusterer(
            similarity: new JaccardSimilarity(),
            tree: new TreeEditDistance(),
            splitClusters: false,
        );
        $clusters = $clusterer->cluster($index, $scrambled);

        $this->assertCount(1, $clusters, 'the chain is one component');
        $this->assertSame(array_column($blocks, 'id'), array_column($clusters[0]->members, 'id'), 'member order is index scan order');

        // Edge-count belt: a chain reports k-1 pairs, not k²/2 lookups.
        $this->assertCount($k - 1, $clusters[0]->pairs);
        $this->assertSame(
            $this->legacyBuildPairs($blocks, $scrambled),
            $clusters[0]->pairs,
            'bucket-driven emission must equal the legacy member-pair scan byte-for-byte'
        );
    }

    /**
     * engine-029, direct unit: a DENSE scrambled bucket plus a member
     * order that deliberately disagrees with strcmp order (lower-case
     * ids byte-sort AFTER uppercase ones). The old scan emitted
     * blockA/blockB in MEMBER-INDEX order (i<j), not strcmp order — the
     * counting sweeps must reproduce exactly that, including for pairs
     * whose blockA id compares byte-HIGHER than blockB.
     */
    public function testBuildPairsFromEdgesDenseScrambledBucketMatchesLegacyScan(): void
    {
        // Member (index) order — intentionally NOT strcmp order.
        $ids = ['aX', 'Bx', 'Cx', 'dW', 'eW', 'Fx', 'Gx', 'hX'];
        $members = [];
        foreach ($ids as $i => $id) {
            $members[] = $this->edgeBlock($id, sprintf('h%02d', $i));
        }

        // Every adjacent-in-list edge plus a few long jumps: mixed
        // blockA-vs-blockB byte orders across the emitted pairs.
        $edges = [];
        $count = count($ids);
        for ($i = 0; $i < $count; $i++) {
            foreach ([1, 3] as $step) {
                if ($i + $step < $count) {
                    $edges[] = [$ids[$i], $ids[$i + $step], 0.6 + 0.01 * $i, MatchTier::Jaccard->value];
                }
            }
        }

        $scrambled = $this->seededShuffle($edges, 'engine-029-dense');
        foreach ($scrambled as $i => $edge) {
            if ($i % 3 === 0) {
                $scrambled[$i] = [$edge[1], $edge[0], $edge[2], $edge[3]];
            }
        }

        // Rebuild the component's bucket the way cluster() does: distinct
        // strcmp-canonical keys, last arrival wins, endpoints carried.
        $bucket = [];
        foreach ($scrambled as [$aId, $bId, $sim, $tier]) {
            $key = strcmp($aId, $bId) < 0 ? $aId . '|' . $bId : $bId . '|' . $aId;
            $bucket[$key] = ['sim' => $sim, 'tier' => $tier, 'a' => $aId, 'b' => $bId];
        }

        $clusterer = new Clusterer(
            similarity: new JaccardSimilarity(),
            tree: new TreeEditDistance(),
        );
        $method = new \ReflectionMethod($clusterer, 'buildPairsFromEdges');
        $pairs  = $method->invoke($clusterer, $members, array_values($bucket));

        $this->assertSame($this->legacyBuildPairs($members, $scrambled), $pairs);
        $this->assertNotEmpty($pairs);
        // Belt: at least one emitted pair has blockA byte-HIGHER than
        // blockB — proof the member-index ordering (not strcmp id
        // ordering) is what survived, if any pair exists where the
        // legacy scan put a lower-positioned but higher-byte id first.
        $byteInverted = false;
        foreach ($pairs as $p) {
            if (strcmp($p['blockA'], $p['blockB']) > 0) {
                $byteInverted = true;
                break;
            }
        }
        $this->assertTrue($byteInverted, 'fixture must include a member-order-vs-byte-order inversion');
    }

    /**
     * engine-029 belt arm: a self-edge in the bucket (never producible by
     * the pair generator, but a hostile edge list is S13's trusted
     * domain once ids check out) must NOT become a blockA===blockB pair.
     * The old i<j scan could never look a self key up; the new sweep
     * skips it explicitly.
     */
    public function testSelfEdgeInBucketIsNeverEmittedAsAPair(): void
    {
        $blocks = [$this->edgeBlock('A', 'hA'), $this->edgeBlock('B', 'hB')];
        $index  = new BlockIndex();
        foreach ($blocks as $b) {
            $index->add($b);
        }

        $edges = [
            ['A', 'A', 0.95, MatchTier::Jaccard->value],
            ['A', 'B', 0.90, MatchTier::Jaccard->value],
        ];

        $clusterer = new Clusterer(
            similarity: new JaccardSimilarity(),
            tree: new TreeEditDistance(),
            splitClusters: false,
        );
        $clusters = $clusterer->cluster($index, $edges);

        $this->assertCount(1, $clusters);
        foreach ($clusters[0]->pairs as $p) {
            $this->assertNotSame($p['blockA'], $p['blockB'], 'self-edge must never materialize as a pair');
        }
        $this->assertSame($this->legacyBuildPairs($blocks, $edges), $clusters[0]->pairs, 'legacy equivalence holds even with the belt edge present');
    }
}
