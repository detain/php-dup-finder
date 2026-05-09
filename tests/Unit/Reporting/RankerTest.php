<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Reporting;

use PHPUnit\Framework\TestCase;
use PhpParser\Node;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Reporting\ClusterSort;
use Phpdup\Reporting\Ranker;
use Phpdup\Util\LineRange;

final class RankerTest extends TestCase
{
    public function testDefaultSortIsImpactDescending(): void
    {
        $clusters = [
            $this->cluster('A', members: 2, blockSize: 4),  // impact ≈ 4
            $this->cluster('B', members: 5, blockSize: 10), // impact ≈ 40
            $this->cluster('C', members: 3, blockSize: 6),  // impact ≈ 12
        ];
        $sorted = (new Ranker(minImpact: 0))->rank($clusters);
        $this->assertSame(['B', 'C', 'A'], array_map(fn(Cluster $c) => $c->id, $sorted));
    }

    public function testRespectsExplicitSortByMembersAsc(): void
    {
        $clusters = [
            $this->cluster('A', members: 2, blockSize: 100),
            $this->cluster('B', members: 5, blockSize: 4),
            $this->cluster('C', members: 3, blockSize: 50),
        ];
        $sorted = (new Ranker(minImpact: 0, sort: new ClusterSort('members', 'asc')))->rank($clusters);
        $this->assertSame(['A', 'C', 'B'], array_map(fn(Cluster $c) => $c->id, $sorted));
    }

    public function testRespectsExplicitSortByBlockSizeDesc(): void
    {
        $clusters = [
            $this->cluster('A', members: 2, blockSize: 5),
            $this->cluster('B', members: 2, blockSize: 50),
            $this->cluster('C', members: 2, blockSize: 25),
        ];
        $sorted = (new Ranker(minImpact: 0, sort: new ClusterSort('block-size', 'desc')))->rank($clusters);
        $this->assertSame(['B', 'C', 'A'], array_map(fn(Cluster $c) => $c->id, $sorted));
    }

    public function testMinImpactStillFilters(): void
    {
        $clusters = [
            $this->cluster('Big',   members: 10, blockSize: 20), // impact ≈ 180
            $this->cluster('Small', members: 2,  blockSize: 1),  // impact ≈ 1
        ];
        $sorted = (new Ranker(minImpact: 100))->rank($clusters);
        $this->assertCount(1, $sorted);
        $this->assertSame('Big', $sorted[0]->id);
    }

    public function testImpactAndConfidenceArePopulatedBeforeSorting(): void
    {
        // Sorting by confidence requires confidence to be computed first.
        $clusters = [
            $this->cluster('A', members: 3, blockSize: 5, similarity: 0.9),
            $this->cluster('B', members: 3, blockSize: 5, similarity: 1.0),
        ];
        $sorted = (new Ranker(minImpact: 0, sort: new ClusterSort('confidence', 'desc')))->rank($clusters);
        $this->assertSame('B', $sorted[0]->id);
        $this->assertGreaterThan(0.0, $sorted[0]->confidence);
    }

    private function cluster(
        string $id,
        int $members,
        int $blockSize,
        float $similarity = 1.0,
    ): Cluster {
        $blocks = [];
        for ($i = 0; $i < $members; $i++) {
            $b = new Block(
                file:      "test.php",
                range:     new LineRange(1 + $i * 5, 5 + $i * 5),
                kind:      'method',
                namespace: null,
                class:     null,
                name:      'm',
                ast:       new Node\Stmt\Nop(),
            );
            $b->size = $blockSize;
            $blocks[] = $b;
        }
        return new Cluster($id, $blocks, $similarity, false);
    }
}
