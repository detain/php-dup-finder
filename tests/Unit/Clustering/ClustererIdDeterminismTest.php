<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Clustering;

use PHPUnit\Framework\TestCase;
use PhpParser\Node\Stmt\Nop;
use Phpdup\Clustering\Clusterer;
use Phpdup\Clustering\MatchTier;
use Phpdup\Extraction\Block;
use Phpdup\Index\BlockIndex;
use Phpdup\Reporting\FindingId;
use Phpdup\Similarity\JaccardSimilarity;
use Phpdup\Similarity\TreeEditDistance;
use Phpdup\Util\LineRange;

/**
 * Item 4.4 — hazard-4 killed at the source: cluster identity must be a
 * pure function of the members' content. These legs drive the real
 * Clusterer::cluster() under every order the pipeline can produce —
 * edge-arrival permutations (parallel scoring seam), index insertion
 * order (serial scoring path), and block-id renumbering (a re-scan) —
 * and demand byte-identical ids every time.
 */
final class ClustererIdDeterminismTest extends TestCase
{
    private const FLOOR = 0.8;

    /** @param array<string,int> $bag */
    private function block(string $id, string $file, string $hash, array $bag = []): Block
    {
        $b = new Block(
            file: $file,
            range: new LineRange(1, 1),
            kind: 'function',
            namespace: null,
            class: null,
            name: null,
            ast: new Nop(),
        );
        $b->id = $id;
        $b->structuralHash = $hash;
        // The serial scoring path reaches for ->canonical (TED); the
        // Normalizer sets it in production, fixtures must mirror.
        $b->canonical = $b->ast;
        $b->ngramBag = $bag === [] ? null : $bag;
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

    private function clusterer(bool $split, float $maxDf = 0.01): Clusterer
    {
        return new Clusterer(
            similarity: new JaccardSimilarity(),
            tree: new TreeEditDistance(),
            similarityThreshold: self::FLOOR,
            treeThreshold: 0.85,
            maxDocumentFrequency: $maxDf,
            splitClusters: $split,
            minSplitSize: 2,
        );
    }

    /**
     * Six distinct-content blocks in one clique, edges injected explicitly
     * (the parallel PairScoreWorker seam).
     *
     * @return array{0: list<Block>, 1: list<array{0:string,1:string,2:float,3:string}>}
     */
    private function clique(): array
    {
        $blocks = [];
        for ($i = 0; $i < 6; $i++) {
            $blocks[] = $this->block(sprintf('b%02d', $i), sprintf('f%d.php', $i), sprintf('hash-%d', $i));
        }
        $edges = [];
        foreach ($blocks as $i => $a) {
            foreach ($blocks as $j => $b) {
                if ($j <= $i) {
                    continue;
                }
                $edges[] = [$a->id, $b->id, 0.9, MatchTier::Jaccard->value];
            }
        }
        return [$blocks, $edges];
    }

    /** @param list<\Phpdup\Clustering\Cluster> $clusters @return list<string> */
    private function sortedIds(array $clusters): array
    {
        $ids = array_map(static fn ($c): string => $c->id, $clusters);
        sort($ids);
        return $ids;
    }

    /**
     * A deliberately redundant battery of reorderings: identity, every
     * rotation, the reversal and an end-swap — enough that a positional
     * ingredient anywhere in the id path cannot survive it.
     *
     * @template T @param array<T> $items @return list<list<T>>
     */
    private function permutations(array $items): array
    {
        $n = count($items);
        $result = [];
        for ($k = 0; $k < $n; $k++) {
            $result[] = array_merge(array_slice($items, $k), array_slice($items, 0, $k));
        }
        $result[] = array_reverse($items);
        $swapped = $items;
        [$swapped[0], $swapped[$n - 1]] = [$swapped[$n - 1], $swapped[0]];
        $result[] = $swapped;
        return $result;
    }

    public function testEdgeArrivalOrderNeverMovesClusterIds(): void
    {
        [$blocks, $edges] = $this->clique();
        $clusterer = $this->clusterer(split: false);

        $baseline = null;
        foreach ($this->permutations($edges) as $permutation) {
            $ids = $this->sortedIds($clusterer->cluster($this->index($blocks), $permutation));
            $this->assertNotEmpty($ids);
            if ($baseline === null) {
                $baseline = $ids;
                $this->assertMatchesRegularExpression('/^F[0-9a-f]{16}$/', $ids[0]);
                continue;
            }
            $this->assertSame($baseline, $ids, 'identical member content must mint identical ids under any edge arrival order');
        }
    }

    public function testSerialScoringWithShuffledIndexInsertionOrderIsIdStable(): void
    {
        // The serial path (edges=null): candidate enumeration runs over the
        // index in insertion order, so shuffling insertion reorders every
        // downstream step. Bags: ten grams shared corpus-wide plus one
        // unique gram each -> Jaccard 10/11 >= floor, TED 1.0 (identical
        // Nop canonicals). maxDocumentFrequency is lifted so the tiny
        // fixture survives the rare-gram filter.
        $core = array_fill_keys(['c1', 'c2', 'c3', 'c4', 'c5', 'c6', 'c7', 'c8', 'c9', 'c10'], 1);
        $blocks = [];
        for ($i = 0; $i < 6; $i++) {
            $bag = $core + ['u' . $i => 1];
            $blocks[] = $this->block(sprintf('b%02d', $i), sprintf('f%d.php', $i), sprintf('hash-%d', $i), $bag);
        }

        $clusterer = $this->clusterer(split: true, maxDf: 1.0);
        $baseline = null;
        foreach ($this->permutations($blocks) as $order) {
            $clusters = $clusterer->cluster($this->index($order), null);
            $this->assertCount(1, $clusters, 'the clique must form exactly one cluster');
            $ids = $this->sortedIds($clusters);
            if ($baseline === null) {
                $baseline = $ids;
                continue;
            }
            $this->assertSame($baseline, $ids, 'scan/insertion order must not move ids');
        }
    }

    public function testBlockIdRenumberingKeepsClusterIds(): void
    {
        // A re-scan that hands the same content different scan-index block
        // ids (edge endpoints follow the rename) must not move identity.
        [$blocks, $edges] = $this->clique();

        $renamed = [];
        foreach ($blocks as $b) {
            $b2 = $this->block('z' . $b->id, $b->file, $b->structuralHash);
            $renamed[] = $b2;
        }
        $renamedEdges = array_map(
            static fn (array $e): array => ['z' . $e[0], 'z' . $e[1], $e[2], $e[3]],
            $edges,
        );

        $clusterer = $this->clusterer(split: false);
        $this->assertSame(
            $this->sortedIds($clusterer->cluster($this->index($blocks), $edges)),
            $this->sortedIds($clusterer->cluster($this->index($renamed), $renamedEdges)),
            'ids key on content, never on block ids',
        );
    }

    public function testExactBucketMintsCanonicalId(): void
    {
        // Phase 1: identical structuralHash across two files -> one exact
        // cluster whose id is the FindingId of the member multiset (the
        // legacy 'X' + bucket-prefix stream is gone).
        $blocks = [
            $this->block('b1', 'one.php', 'same-hash'),
            $this->block('b2', 'two.php', 'same-hash'),
        ];
        $reversed = [$blocks[1], $blocks[0]];

        $first = $this->clusterer(split: false)->cluster($this->index($blocks), null);
        $second = $this->clusterer(split: false)->cluster($this->index($reversed), null);

        $this->assertCount(1, $first);
        $this->assertTrue($first[0]->exact);
        $this->assertSame(FindingId::forMembers([$blocks[0], $blocks[1]]), $first[0]->id);
        $this->assertSame($first[0]->id, $second[0]->id);
    }

    public function testContentEditMovesOnlyTheEditedClusterId(): void
    {
        // Two independent cliques; touching one member's content resurfaces
        // that cluster (fail-open) while the untouched cluster's id stands.
        $groupA = [];
        $groupB = [];
        for ($i = 0; $i < 3; $i++) {
            $groupA[] = $this->block('a' . $i, 'ga' . $i . '.php', 'ga-hash-' . $i);
            $groupB[] = $this->block('b' . $i, 'gb' . $i . '.php', 'gb-hash-' . $i);
        }
        $edges = [];
        foreach ([$groupA, $groupB] as $group) {
            for ($i = 0; $i < 3; $i++) {
                for ($j = $i + 1; $j < 3; $j++) {
                    $edges[] = [$group[$i]->id, $group[$j]->id, 0.9, MatchTier::Jaccard->value];
                }
            }
        }

        $clusterer = $this->clusterer(split: false);
        $before = $clusterer->cluster($this->index(array_merge($groupA, $groupB)), $edges);
        $this->assertCount(2, $before);
        $idsBefore = $this->sortedIds($before);

        $groupA[1]->structuralHash = 'ga-hash-1-EDITED';
        $after = $clusterer->cluster($this->index(array_merge($groupA, $groupB)), $edges);
        $idsAfter = $this->sortedIds($after);

        $this->assertCount(2, $after);
        $this->assertNotSame($idsBefore, $idsAfter);
        // Exactly one id moved: the untouched group's identity stands.
        $this->assertSame(1, count(array_diff($idsBefore, $idsAfter)));
        $this->assertSame(1, count(array_diff($idsAfter, $idsBefore)));
    }

    public function testIdsSurviveAComposeRoundTripOfMemberContent(): void
    {
        // The same content arriving through different object instances
        // (what a serialize/unserialize cache round-trip produces).
        [$blocks, $edges] = $this->clique();
        $clusterer = $this->clusterer(split: false);

        $freshBlocks = [];
        foreach ($blocks as $b) {
            $freshBlocks[] = $this->block('other-' . $b->id, $b->file, $b->structuralHash);
        }
        $freshEdges = array_map(
            static fn (array $e): array => ['other-' . $e[0], 'other-' . $e[1], $e[2], $e[3]],
            $edges,
        );

        $this->assertSame(
            $this->sortedIds($clusterer->cluster($this->index($blocks), $edges)),
            $this->sortedIds($clusterer->cluster($this->index($freshBlocks), $freshEdges)),
        );
    }

    /**
     * @param list<\Phpdup\Clustering\Cluster> $clusters
     * @return list<array{id: string, members: list<string>, medoid: string|null, pairs: mixed}>
     */
    private function fullSignatures(array $clusters): array
    {
        return array_map(static fn ($c): array => [
            'id'      => $c->id,
            'members' => array_column($c->members, 'id'),
            'medoid'  => $c->medoidId,
            'pairs'   => $c->pairs,
        ], $clusters);
    }

    /**
     * engine-029's companion pin: identity of cluster() output is one
     * thing, the ORDER of Cluster::$pairs is another. The new
     * bucket-driven emission files edges by arrival under their final
     * root and must still emit in pure member-position order — no
     * bucket-order leak. This battery demands the full pairs array
     * (byte-for-byte, blockA/blockB/tier/score in one) plus ids, member
     * order and medoid assignment stay identical across every edge
     * arrival rotation/reversal and flipped orientations, with the
     * splitter ON and OFF.
     */
    public function testPairArraysAreByteIdenticalUnderEdgePermutations(): void
    {
        [$blocks, $edges] = $this->clique();

        $variants = [];
        foreach ($this->permutations($edges) as $i => $perm) {
            $variants['rot' . $i] = $perm;
            // Same arrival order, flipped orientation on every edge:
            // the strcmp-canonical key hides it from $edgeMap, the
            // bucket list sees it — both must land on one emission.
            $variants['rot' . $i . '-flip'] = array_map(
                static fn (array $e): array => [$e[1], $e[0], $e[2], $e[3]],
                $perm,
            );
        }

        $offBase = $this->fullSignatures($this->clusterer(split: false)->cluster($this->index($blocks), $edges));
        $onBase  = $this->fullSignatures($this->clusterer(split: true)->cluster($this->index($blocks), $edges));

        foreach ($variants as $label => $variantEdges) {
            $this->assertSame(
                $offBase,
                $this->fullSignatures($this->clusterer(split: false)->cluster($this->index($blocks), $variantEdges)),
                $label . ': split-off pairs/ids/members moved under edge permutation',
            );
            $this->assertSame(
                $onBase,
                $this->fullSignatures($this->clusterer(split: true)->cluster($this->index($blocks), $variantEdges)),
                $label . ': split-on pairs/ids/members/medoids moved under edge permutation',
            );
        }

        // Belt: the pinned array really carries order-sensitive content.
        $this->assertNotEmpty($offBase[0]['pairs']);
    }
}
