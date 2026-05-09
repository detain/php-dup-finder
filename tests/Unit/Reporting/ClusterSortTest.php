<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Reporting;

use PHPUnit\Framework\TestCase;
use PhpParser\Node;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Reporting\ClusterSort;
use Phpdup\Util\LineRange;

final class ClusterSortTest extends TestCase
{
    public function testParseDefaultsToImpactDesc(): void
    {
        $sort = ClusterSort::parse('impact');
        $this->assertSame('impact', $sort->key);
        $this->assertSame('desc', $sort->direction);
    }

    public function testParseAcceptsExplicitDirection(): void
    {
        $sort = ClusterSort::parse('members:asc');
        $this->assertSame('members', $sort->key);
        $this->assertSame('asc', $sort->direction);
    }

    public function testParseAcceptsLeadingMinusForDescAndPlusForAsc(): void
    {
        $this->assertSame('desc', ClusterSort::parse('-impact')->direction);
        $this->assertSame('asc',  ClusterSort::parse('+lines')->direction);
    }

    public function testParseRecognisesSizeAndCountAsAliasesForMembers(): void
    {
        $this->assertSame('members', ClusterSort::parse('size')->key);
        $this->assertSame('members', ClusterSort::parse('count')->key);
        $this->assertSame('members', ClusterSort::parse('SIZE:asc')->key);
    }

    public function testParseAcceptsBlockSizeUnderscoreAlias(): void
    {
        $this->assertSame('block-size', ClusterSort::parse('block_size')->key);
    }

    public function testParseRejectsUnknownKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid sort key "complexity"');
        ClusterSort::parse('complexity');
    }

    public function testParseRejectsUnknownDirection(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid sort direction 'sideways'");
        ClusterSort::parse('impact:sideways');
    }

    public function testEmptyStringYieldsDefault(): void
    {
        $sort = ClusterSort::parse('');
        $this->assertSame('impact', $sort->key);
        $this->assertSame('desc', $sort->direction);
    }

    public function testApplyByImpactDesc(): void
    {
        $clusters = [
            $this->cluster('A', impact: 10),
            $this->cluster('B', impact: 50),
            $this->cluster('C', impact: 30),
        ];
        $sorted = ClusterSort::parse('impact:desc')->apply($clusters);
        $this->assertSame(['B', 'C', 'A'], array_map(fn(Cluster $c) => $c->id, $sorted));
    }

    public function testApplyByMembersDescTieBrokenBySimilarity(): void
    {
        $clusters = [
            $this->cluster('A', members: 2, similarity: 0.7),
            $this->cluster('B', members: 5, similarity: 0.9),
            $this->cluster('C', members: 5, similarity: 1.0), // ties on members; higher sim wins
        ];
        $sorted = ClusterSort::parse('members')->apply($clusters);
        $this->assertSame(['C', 'B', 'A'], array_map(fn(Cluster $c) => $c->id, $sorted));
    }

    public function testApplyByLinesAsc(): void
    {
        $clusters = [
            $this->cluster('A', lines: 30),
            $this->cluster('B', lines: 10),
            $this->cluster('C', lines: 20),
        ];
        $sorted = ClusterSort::parse('lines:asc')->apply($clusters);
        $this->assertSame(['B', 'C', 'A'], array_map(fn(Cluster $c) => $c->id, $sorted));
    }

    public function testApplyByBlockSize(): void
    {
        $clusters = [
            $this->cluster('A', avgBlockSize: 100),
            $this->cluster('B', avgBlockSize: 30),
            $this->cluster('C', avgBlockSize: 60),
        ];
        $sorted = ClusterSort::parse('block-size:desc')->apply($clusters);
        $this->assertSame(['A', 'C', 'B'], array_map(fn(Cluster $c) => $c->id, $sorted));
    }

    public function testApplyBySimilarityAsc(): void
    {
        $clusters = [
            $this->cluster('A', similarity: 0.95),
            $this->cluster('B', similarity: 0.70),
            $this->cluster('C', similarity: 0.85),
        ];
        $sorted = ClusterSort::parse('similarity:asc')->apply($clusters);
        $this->assertSame(['B', 'C', 'A'], array_map(fn(Cluster $c) => $c->id, $sorted));
    }

    public function testApplyByConfidence(): void
    {
        $clusters = [
            $this->cluster('A', confidence: 0.6),
            $this->cluster('B', confidence: 1.0),
            $this->cluster('C', confidence: 0.8),
        ];
        $sorted = ClusterSort::parse('confidence:desc')->apply($clusters);
        $this->assertSame(['B', 'C', 'A'], array_map(fn(Cluster $c) => $c->id, $sorted));
    }

    public function testApplyByNameAlphabetical(): void
    {
        $clusters = [
            $this->cluster('X', name: 'zebra'),
            $this->cluster('Y', name: 'apple'),
            $this->cluster('Z', name: 'mango'),
        ];
        $sorted = ClusterSort::parse('name:asc')->apply($clusters);
        $this->assertSame(['Y', 'Z', 'X'], array_map(fn(Cluster $c) => $c->id, $sorted));
    }

    public function testApplyByFile(): void
    {
        $clusters = [
            $this->cluster('A', file: 'src/Z.php'),
            $this->cluster('B', file: 'src/A.php'),
            $this->cluster('C', file: 'src/M.php'),
        ];
        $sorted = ClusterSort::parse('file:asc')->apply($clusters);
        $this->assertSame(['B', 'C', 'A'], array_map(fn(Cluster $c) => $c->id, $sorted));
    }

    public function testApplyById(): void
    {
        $clusters = [
            $this->cluster('Cab', impact: 1),
            $this->cluster('Aac', impact: 1),
            $this->cluster('Bbc', impact: 1),
        ];
        $sorted = ClusterSort::parse('id:asc')->apply($clusters);
        $this->assertSame(['Aac', 'Bbc', 'Cab'], array_map(fn(Cluster $c) => $c->id, $sorted));
    }

    public function testTieBreakOrderingIsStable(): void
    {
        // Identical primary key — tie-break order is members DESC, sim DESC,
        // id ASC. So in members=2 sim=0.5 we expect alphabetical id.
        $clusters = [
            $this->cluster('Z', impact: 50, members: 2, similarity: 0.5),
            $this->cluster('A', impact: 50, members: 2, similarity: 0.5),
            $this->cluster('M', impact: 50, members: 2, similarity: 0.5),
        ];
        $sorted = ClusterSort::parse('impact:desc')->apply($clusters);
        $this->assertSame(['A', 'M', 'Z'], array_map(fn(Cluster $c) => $c->id, $sorted));
    }

    public function testDescribeRoundTripsThroughParse(): void
    {
        $orig = new ClusterSort('lines', 'asc');
        $reparsed = ClusterSort::parse($orig->describe());
        $this->assertSame($orig->key, $reparsed->key);
        $this->assertSame($orig->direction, $reparsed->direction);
    }

    /**
     * Build a Cluster with controllable per-key projections. Since Cluster's
     * size/totalLines/avgBlockSize derive from member Block instances, we
     * stuff in synthetic Blocks whose $size and line range match the
     * test's expectations.
     */
    private function cluster(
        string $id,
        int $impact = 0,
        int $members = 1,
        float $similarity = 1.0,
        float $confidence = 0.0,
        int $lines = 1,
        int $avgBlockSize = 1,
        string $name = 'someMethod',
        string $file = 'test.php',
    ): Cluster {
        $blocks = [];
        // Distribute lines/avgBlockSize across the requested member count
        // so totalLines() / avgBlockSize() return the right values.
        $lineSpan = (int)max(1, floor($lines / $members));
        for ($i = 0; $i < $members; $i++) {
            $start = 1 + $i * ($lineSpan + 1);
            $end   = $start + $lineSpan - 1;
            $b = new Block(
                file:      $file,
                range:     new LineRange($start, $end),
                kind:      'method',
                namespace: null,
                class:     null,
                name:      $name,
                ast:       new Node\Stmt\Nop(),
            );
            $b->size = $avgBlockSize;
            $blocks[] = $b;
        }
        $c = new Cluster($id, $blocks, $similarity, false);
        $c->impact     = $impact;
        $c->confidence = $confidence;
        return $c;
    }
}
