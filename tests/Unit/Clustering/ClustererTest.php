<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Clustering;

use PHPUnit\Framework\TestCase;
use Phpdup\Clustering\Clusterer;
use Phpdup\Clustering\Cluster;
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
            ['high', 'shared', 0.85],
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
            ['low', 'far', 0.50],
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
            ['A', 'B', 0.86],
            ['C', 'D', 0.84],
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
            ['A', 'B', 0.95],
            ['B', 'C', 0.90],
            ['C', 'D', 0.85],
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
            ['A', 'B', 0.95],
            ['B', 'C', 0.90],
            ['C', 'D', 0.85],
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
            ['A', 'B', 0.95],
            ['C', 'D', 0.91],
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
}
