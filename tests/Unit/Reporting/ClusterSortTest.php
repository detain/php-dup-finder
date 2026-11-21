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

    // ---------------------------------------------------------------
    // output-S14: Schwartzian decorate-sort-undecorate guarantees
    // ---------------------------------------------------------------

    /**
     * Orders captured from the PRE-S14 inline-projection comparator on
     * the fixture below, for every supported key in both directions.
     * The Schwartzian rewrite must reproduce every row byte-identically.
     */
    public function testSchwartzianOrderMatchesPinnedFixture(): void
    {
        $pins = [
            'impact:desc'       => 'K4,K11,K8,K7,K10,K2,K1,K9,K3,K5,K6',
            'impact:asc'        => 'K5,K6,K2,K1,K9,K3,K8,K7,K10,K11,K4',
            'members:desc'      => 'K8,K5,K6,K2,K7,K1,K9,K3,K10,K4,K11',
            'members:asc'       => 'K11,K4,K7,K1,K9,K3,K10,K2,K5,K6,K8',
            'block-size:desc'   => 'K9,K4,K8,K7,K2,K1,K3,K10,K5,K6,K11',
            'block-size:asc'    => 'K11,K5,K6,K3,K10,K2,K1,K8,K7,K9,K4',
            'lines:desc'        => 'K5,K6,K10,K3,K2,K1,K9,K7,K8,K4,K11',
            'lines:asc'         => 'K11,K8,K4,K7,K2,K1,K9,K3,K10,K5,K6',
            'similarity:desc'   => 'K7,K4,K2,K1,K9,K11,K3,K8,K10,K5,K6',
            'similarity:asc'    => 'K5,K6,K8,K10,K3,K11,K2,K1,K9,K7,K4',
            'confidence:desc'   => 'K3,K10,K8,K7,K2,K1,K9,K5,K6,K11,K4',
            'confidence:asc'    => 'K4,K11,K5,K6,K2,K1,K9,K8,K7,K3,K10',
            'name:desc'         => 'K4,K5,K6,K8,K7,K10,K3,K2,K1,K9,K11',
            'name:asc'          => 'K11,K2,K1,K9,K3,K7,K10,K8,K5,K6,K4',
            'file:desc'         => 'K8,K7,K10,K4,K5,K2,K6,K1,K3,K9,K11',
            'file:asc'          => 'K11,K9,K6,K1,K3,K5,K2,K4,K7,K10,K8',
            'id:desc'           => 'K9,K8,K7,K6,K5,K4,K3,K2,K11,K10,K1',
            'id:asc'            => 'K1,K10,K11,K2,K3,K4,K5,K6,K7,K8,K9',
        ];

        foreach ($pins as $spec => $pinnedIds) {
            $sorted = ClusterSort::parse($spec)->apply($this->fixtureClusters());
            $this->assertSame(
                explode(',', $pinnedIds),
                array_map(fn(Cluster $c) => $c->id, $sorted),
                "order drifted for --sort={$spec}",
            );
        }
    }

    /**
     * Cross-check: an independent copy of the old algorithm (project()
     * called inline from the comparator) must agree with apply() on the
     * fixture, under several input permutations — proving both the
     * semantic equality of the transform and that the total order no
     * longer depends on usort's (unstable since PHP 8.0) internals.
     */
    public function testSchwartzianMatchesReferenceComparatorUnderPermutations(): void
    {
        foreach (self::permutations($this->fixtureClusters()) as $label => $input) {
            foreach (ClusterSort::ALL_KEYS as $key) {
                foreach ([ClusterSort::DIRECTION_DESC, ClusterSort::DIRECTION_ASC] as $direction) {
                    $sort = new ClusterSort($key, $direction);
                    $this->assertSame(
                        self::referenceApplyOldStyle($sort, $input),
                        $sort->apply($input),
                        "apply() drifted from the old comparator ({$label}, {$key}:{$direction})",
                    );
                }
            }
        }
    }

    /**
     * The core perf claim: apply() projects every cluster exactly once —
     * N calls, not the O(N log N) of the old inline-projection form.
     */
    public function testProjectIsCalledExactlyOncePerCluster(): void
    {
        $fixture = $this->fixtureClusters();
        $n = count($fixture);

        foreach (ClusterSort::ALL_KEYS as $key) {
            ClusterSort::resetProjectCallCount();
            (new ClusterSort($key, ClusterSort::DIRECTION_DESC))->apply($fixture);
            $this->assertSame($n, ClusterSort::projectCallCount(), "projection count for key '{$key}'");
        }

        // No memoisation across calls: a second apply() projects again.
        ClusterSort::resetProjectCallCount();
        $sort = new ClusterSort('impact');
        $sort->apply($fixture);
        $sort->apply($fixture);
        $this->assertSame(2 * $n, ClusterSort::projectCallCount());
    }

    /** The counter seam itself must not be dead weight. */
    public function testProjectCallCountTracksDirectProjections(): void
    {
        ClusterSort::resetProjectCallCount();
        $this->assertSame(0, ClusterSort::projectCallCount());

        $sort = new ClusterSort('impact');
        $c = $this->cluster('S1', impact: 3);
        $this->assertSame(3, $sort->project($c));
        $this->assertSame(1, ClusterSort::projectCallCount());
    }

    /**
     * Contract pin: fully-equal clusters (same id, same every tie-break
     * field) keep their input order. The explicit position tie-break makes
     * the order total; usort instability is not observable on PHP 8.3, so
     * this test stays green without the arm by design — it documents the
     * contract rather than discriminating the arm. The old code left this
     * case to usort internals (unstable since PHP 8.0); pinning it to
     * input order is the determinism improvement disclosed in S14.
     */
    public function testFullyTiedClustersKeepInputOrder(): void
    {
        $p = $this->cluster('DUP', impact: 7, members: 2, similarity: 0.5);
        $q = $this->cluster('DUP', impact: 7, members: 2, similarity: 0.5);
        $r = $this->cluster('DUP', impact: 7, members: 2, similarity: 0.5);

        foreach (ClusterSort::ALL_KEYS as $key) {
            foreach ([ClusterSort::DIRECTION_DESC, ClusterSort::DIRECTION_ASC] as $direction) {
                $sorted = (new ClusterSort($key, $direction))->apply([$p, $q, $r]);
                $this->assertSame([$p, $q, $r], $sorted, "input order lost for {$key}:{$direction}");
            }
        }
    }

    protected function tearDown(): void
    {
        ClusterSort::resetProjectCallCount();
    }

    /**
     * Independent copy of the PRE-S14 algorithm: the comparator calls
     * project() inline on every comparison, exactly as apply() used to.
     * Lives here (not in src/) so the shipped class stays the only
     * optimised implementation.
     *
     * @param list<Cluster> $clusters
     * @return list<Cluster>
     */
    private static function referenceApplyOldStyle(ClusterSort $sort, array $clusters): array
    {
        $sign = $sort->direction === ClusterSort::DIRECTION_DESC ? -1 : 1;

        usort($clusters, static function (Cluster $a, Cluster $b) use ($sort, $sign): int {
            $av = $sort->project($a);
            $bv = $sort->project($b);
            $primary = is_string($av)
                ? strcmp($av, (string) $bv)
                : ($av <=> $bv);
            if ($primary !== 0) {
                return $sign * $primary;
            }
            $tie = $b->size() <=> $a->size();
            if ($tie !== 0) return $tie;
            $tie = $b->similarity <=> $a->similarity;
            if ($tie !== 0) return $tie;
            return strcmp($a->id, $b->id);
        });

        return $clusters;
    }

    /**
     * A tie-rich fixture: repeated impacts, member counts, similarities,
     * lowercase-equal names, case-split file paths and one empty cluster
     * — enough to walk every arm of the tie-break chain per key.
     *
     * @return list<Cluster>
     */
    private function fixtureClusters(): array
    {
        return [
            $this->cluster('K1',  impact: 10, members: 2, similarity: 0.9,  confidence: 0.5,  lines: 12, avgBlockSize: 30,  name: 'Alpha',   file: 'src/a.php'),
            $this->cluster('K2',  impact: 10, members: 3, similarity: 0.9,  confidence: 0.5,  lines: 12, avgBlockSize: 30,  name: 'alpha',   file: 'src/b.php'),
            $this->cluster('K3',  impact: 10, members: 2, similarity: 0.75, confidence: 1.0,  lines: 20, avgBlockSize: 15,  name: 'Beta',    file: 'src/a.php'),
            $this->cluster('K4',  impact: 50, members: 1, similarity: 1.0,  confidence: 0.0,  lines: 5,  avgBlockSize: 100, name: 'zeta',    file: 'src/c.php'),
            $this->cluster('K5',  impact: 0,  members: 4, similarity: 0.33, confidence: 0.33, lines: 40, avgBlockSize: 8,   name: 'Gamma',   file: 'src/b.php'),
            $this->cluster('K6',  impact: 0,  members: 4, similarity: 0.33, confidence: 0.33, lines: 40, avgBlockSize: 8,   name: 'gamma',   file: 'src/a.php'),
            $this->cluster('K7',  impact: 25, members: 2, similarity: 1.0,  confidence: 0.75, lines: 7,  avgBlockSize: 45,  name: 'Delta',   file: 'src/d.php'),
            $this->cluster('K8',  impact: 25, members: 5, similarity: 0.5,  confidence: 0.75, lines: 7,  avgBlockSize: 45,  name: 'Epsilon', file: 'src/e.php'),
            $this->cluster('K9',  impact: 10, members: 2, similarity: 0.9,  confidence: 0.5,  lines: 12, avgBlockSize: 100, name: 'alpha',   file: 'src/A.php'),
            $this->cluster('K10', impact: 25, members: 2, similarity: 0.5,  confidence: 1.0,  lines: 30, avgBlockSize: 15,  name: 'delta',   file: 'src/d.php'),
            $this->cluster('K11', impact: 33, members: 0, similarity: 0.85, confidence: 0.1,  name: 'Zulu',    file: 'src/z.php'),
        ];
    }

    /**
     * Deterministic input permutations (no RNG): original, reversed,
     * two rotations, and an evens-before-odds pick.
     *
     * @param list<Cluster> $clusters
     * @return array<string, list<Cluster>>
     */
    private static function permutations(array $clusters): array
    {
        $n = count($clusters);
        $evensFirst = [];
        for ($i = 0; $i < $n; $i += 2) {
            $evensFirst[] = $clusters[$i];
        }
        for ($i = 1; $i < $n; $i += 2) {
            $evensFirst[] = $clusters[$i];
        }

        return [
            'original'   => $clusters,
            'reversed'   => array_reverse($clusters),
            'rot-3'      => array_merge(array_slice($clusters, 3), array_slice($clusters, 0, 3)),
            'rot-7'      => array_merge(array_slice($clusters, 7), array_slice($clusters, 0, 7)),
            'evens-first' => $evensFirst,
        ];
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
        $lineSpan = $members > 0 ? (int)max(1, floor($lines / $members)) : 0;
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
