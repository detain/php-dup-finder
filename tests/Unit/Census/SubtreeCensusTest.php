<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Census;

use PHPUnit\Framework\TestCase;
use Phpdup\Census\SubtreeCensus;
use Phpdup\Census\SubtreeMiner;

/**
 * Item 3.1 substrate — cross-file census: the doc's two-pass location
 * policy ("count hashes first; keep locations only for hashes seen at
 * least twice"), deterministic ranking, and the §5c suppression gate
 * riding on the miner's parent edges.
 */
final class SubtreeCensusTest extends TestCase
{
    /** @param list<array{0:string,1:string,2:int,3:string,4:int,5:list<int>}> $sites */
    private static function payload(array $sites, array $edges = []): array
    {
        return ['sites' => $sites, 'edges' => $edges];
    }

    public function testSingleSiteHashStoresNoLocations(): void
    {
        $census = new SubtreeCensus();
        $census->recordFile('a.php', self::payload([['k1', 'a1', 12, 'Expr_x', 1, [40]]]));

        $rows = $census->rows();
        self::assertCount(1, $rows);
        self::assertSame(1, $rows[0]->sites);
        self::assertSame([], $rows[0]->locations, 'pass-1 posture: one site stores nothing');
        self::assertSame(1, $census->hashCount());
        self::assertSame(1, $census->sitesTotal());
    }

    public function testTwoSiteHashPairsBothLocations(): void
    {
        $census = new SubtreeCensus();
        $census->recordFile('a.php', self::payload([['k1', 'a1', 12, 'Expr_x', 1, [40]]]));
        $census->recordFile('b.php', self::payload([['k1', 'a1', 12, 'Expr_x', 1, [7]]]));

        $row = $census->rows()[0];
        self::assertSame(2, $row->sites);
        self::assertSame(2, $row->files);
        self::assertSame(['a.php:40', 'b.php:7'], $row->locations);
    }

    public function testIntraFileRepetitionCountsAndSamples(): void
    {
        $census = new SubtreeCensus();
        $census->recordFile('a.php', self::payload([['k1', 'a1', 8, 'Stmt_y', 3, [5, 9]]]));

        $row = $census->rows()[0];
        self::assertSame(3, $row->sites, 'count is sites, not sample lines');
        self::assertSame(1, $row->files);
        self::assertSame(['a.php:5', 'a.php:9'], $row->locations, 'multi-site single sample-line pair materialises');
    }

    public function testMultiSiteWithOneSampleLineStillShowsItsLocation(): void
    {
        $census = new SubtreeCensus();
        // sites=2 but only one line survived the miner cap (line<=0 belt).
        $census->recordFile('a.php', self::payload([['k1', 'a1', 8, 'Stmt_y', 2, [5]]]));
        self::assertSame(['a.php:5'], $census->rows()[0]->locations);
    }

    public function testSampleCapBoundsLocations(): void
    {
        $census = new SubtreeCensus(sampleCap: 2);
        for ($f = 0; $f < 5; $f++) {
            $census->recordFile('f' . $f . '.php', self::payload([['k1', 'a1', 8, 'Stmt_y', 1, [$f + 1]]]));
        }
        $row = $census->rows()[0];
        self::assertSame(5, $row->sites);
        self::assertCount(2, $row->locations, 'locations are bounded per hash no matter the repetition');
        self::assertSame(
            ['f0.php:1', 'f1.php:2'],
            $row->locations,
            'cap pressure retains the LOWEST keys (canonical survivors)',
        );
    }

    public function testSampleLocationsAreCanonicalAcrossIngestOrders(): void
    {
        // r1-M1: rows()/maximalRows() must render byte-identically over
        // permuted file order AND permuted per-row line order — the old
        // append-until-cap list leaked ingest interleaving into both the
        // survivors and the rendering under sampleCap pressure.
        $rowsFor = static function (array $files): array {
            $census = new SubtreeCensus(sampleCap: 3);
            foreach ($files as [$file, $lines]) {
                $census->recordFile($file, self::payload([['k', 'a', 8, 'Stmt_x', 1, $lines]]));
            }
            return array_map(
                static fn($r): array => [
                    $r->exactHash, $r->abstractHash, $r->nodes, $r->rootType,
                    $r->sites, $r->files, $r->locations,
                ],
                $census->maximalRows(),
            );
        };

        // As strings: 'p.php:40' < 'p.php:5' < 'q.php:1' < 'r.php:9'.
        $forward  = $rowsFor([['q.php', [1]], ['p.php', [40, 5]], ['r.php', [9]]]);
        $reverse  = $rowsFor([['r.php', [9]], ['p.php', [40, 5]], ['q.php', [1]]]);
        $shuffled = $rowsFor([['p.php', [5, 40]], ['r.php', [9]], ['q.php', [1]]]);

        self::assertSame($forward, $reverse, 'permuted file order renders byte-identical rows');
        self::assertSame($forward, $shuffled, 'permuted in-row line order renders byte-identical rows');
        self::assertSame(
            ['p.php:40', 'p.php:5', 'q.php:1'],
            $forward[0][6],
            'survivors are exactly the lowest sampleCap keys, canonically ordered',
        );
    }

    public function testWireRejectsMoreThanFourSampleLines(): void
    {
        // r1-m1: the CONTRACT FOR CONSUMERS wire shape is lines[≤4];
        // a longer row must halt at the boundary, not ingest silently.
        $census = new SubtreeCensus();
        $census->recordFile('a.php', self::payload([['k', 'ab', 6, 'Expr_a', 5, [1, 2, 3, 4]]]));
        self::assertSame(5, $census->sitesOf('k'), 'the 4-line boundary row is legal');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('wire cap is 4');
        $census->recordFile('b.php', self::payload([['k', 'ab', 6, 'Expr_a', 6, [1, 2, 3, 4, 5]]]));
    }

    public function testWireCapMatchesMinerRowCap(): void
    {
        self::assertSame(
            SubtreeMiner::DEFAULT_ROW_CAP,
            SubtreeCensus::MAX_WIRE_LINES,
            'miner sealing cap and census wire cap are one contract',
        );
    }

    public function testRankingOrderIsSitesFilesHash(): void
    {
        $census = new SubtreeCensus();
        $census->recordFile('a.php', self::payload([
            ['zz', 'az', 6, 'Expr_a', 5, [1, 2, 3, 4]],
            ['mm', 'am', 6, 'Expr_a', 5, [1, 2, 3, 4]],
            ['aa', 'ax', 6, 'Expr_a', 9, [1, 2, 3, 4]],
        ]));
        $order = array_map(static fn($r) => $r->exactHash, $census->rows());
        self::assertSame(['aa', 'mm', 'zz'], $order, 'sites desc, exact-hash asc tie-break');

        // files as the second key: same sites, different file spread.
        $two = new SubtreeCensus();
        $two->recordFile('a.php', self::payload([['k2', 'ak', 6, 'Expr_a', 4, [1]]]));
        $two->recordFile('b.php', self::payload([['k2', 'ak', 6, 'Expr_a', 1, [2]]]));
        $two->recordFile('c.php', self::payload([['k1', 'ak', 6, 'Expr_a', 5, [1]]]));
        $order = array_map(static fn($r) => $r->exactHash, $two->rows());
        self::assertSame(['k2', 'k1'], $order, 'more files outranks fewer at equal sites');
    }

    public function testRowsIndependentOfIngestOrder(): void
    {
        $rowsFor = function (array $files): array {
            $census = new SubtreeCensus();
            foreach ($files as [$file, $count]) {
                $census->recordFile($file, self::payload([['k' . $count, 'a' . $count, 6, 'Expr_a', $count, [1]]]));
            }
            return $census->rows();
        };
        $a = $rowsFor([['x.php', 2], ['y.php', 7]]);
        $b = $rowsFor([['y.php', 7], ['x.php', 2]]);
        self::assertSame(
            array_map(static fn($r) => $r->exactHash, $a),
            array_map(static fn($r) => $r->exactHash, $b),
        );
    }

    public function testRootTypeModeAndTieBreak(): void
    {
        $census = new SubtreeCensus();
        $census->recordFile('a.php', self::payload([['k', 'ak', 6, 'Expr_Alpha', 3, [1, 2, 3]]]));
        $census->recordFile('b.php', self::payload([['k', 'ak', 6, 'Expr_Beta', 5, [1, 2, 3]]]));
        $census->recordFile('c.php', self::payload([['k', 'ak', 6, 'Expr_Beta', 1, [1]]]));
        self::assertSame('Expr_Beta', $census->rows()[0]->rootType);

        $tie = new SubtreeCensus();
        $tie->recordFile('a.php', self::payload([['k', 'ak', 6, 'Expr_B', 1, [1]], ['k', 'ak', 6, 'Expr_A', 1, [2]]]));
        self::assertSame('Expr_A', $tie->rows()[0]->rootType, 'tie breaks alphabetically');
    }

    public function testSuppressionTruthTable(): void
    {
        // (a) child suppressed by an equal-support parent; (b) parent
        // kept; (c) the max-parent rule: C(5) nests under P(3) AND
        // G(10) — its LARGEST parent still outranks it => suppressed;
        // (d) edge to an UNLISTED parent is inert.
        $census = new SubtreeCensus();
        $census->recordFile('a.php', self::payload(
            [
                ['G', 'g', 20, 'Stmt_If', 10, [1]],
                ['P', 'p', 14, 'Stmt_For', 5, [2]],
                ['C', 'c', 9,  'Expr_x',  5, [3]],
                ['U', 'u', 8,  'Expr_y',  3, [4]],
            ],
            [
                ['P', 'G', 5],   // P(5) nests in G(10): 10 >= 5 => suppressed? equal-… no, strictly larger
                ['C', 'P', 3],
                ['C', 'G', 2],
                ['U', 'X', 1],   // X never listed => edge inert
            ]
        ));
        $maximal = array_map(static fn($r) => $r->exactHash, $census->maximalRows());
        self::assertContains('G', $maximal, 'top of the family survives');
        self::assertNotContains('P', $maximal, 'P(5) inside G(10): suppressed');
        self::assertNotContains('C', $maximal, 'C(5) under max-parent G(10): suppressed even though P(3) < C');
        self::assertContains('U', $maximal, 'unlisted parent edge suppresses nothing');

        // Equal support suppresses too: the child is pure nesting noise.
        $equal = new SubtreeCensus();
        $equal->recordFile('a.php', self::payload(
            [['P', 'p', 14, 'Stmt_For', 3, [1]], ['C', 'c', 9, 'Expr_x', 3, [2]]],
            [['C', 'P', 3]]
        ));
        self::assertNotContains('C', array_map(static fn($r) => $r->exactHash, $equal->maximalRows()), 'equal support: contained => dropped');
    }

    public function testFallingSupportChainSurvives(): void
    {
        // G(1) ⊃ P(3) ⊃ C(5): no parent reaches its child's support, so
        // the whole chain keeps — support decides, not depth.
        $census = new SubtreeCensus();
        $census->recordFile('a.php', self::payload(
            [['G', 'g', 20, 'Stmt_If', 1, [1]], ['P', 'p', 14, 'Stmt_For', 3, [2]], ['C', 'c', 9, 'Expr_x', 5, [3]]],
            [['P', 'G', 1], ['C', 'P', 3]]
        ));
        $maximal = array_map(static fn($r) => $r->exactHash, $census->maximalRows());
        self::assertSame(['C', 'P', 'G'], $maximal, 'all listed; rows()-order (sites desc) preserved');
    }

    public function testCrossFileFamilySuppressionUsesGlobalSupport(): void
    {
        $census = new SubtreeCensus();
        // Parent shape G occurs once per file x 2 files; child C sits
        // under G in file a only. Supports: G=2, C=1 -> C suppressed.
        $census->recordFile('a.php', self::payload(
            [['G', 'g', 20, 'Stmt_If', 1, [1]], ['C', 'c', 9, 'Expr_x', 1, [2]]],
            [['C', 'G', 1]]
        ));
        $census->recordFile('b.php', self::payload([['G', 'g', 20, 'Stmt_If', 1, [5]]], []));
        $maximal = array_map(static fn($r) => $r->exactHash, $census->maximalRows());
        self::assertContains('G', $maximal);
        self::assertNotContains('C', $maximal, 'edge witness in one file suppresses via corpus-global support');
    }

    public function testConflictingMetadataUnderOneHashHalts(): void
    {
        $census = new SubtreeCensus();
        $census->recordFile('a.php', self::payload([['k', 'ab', 8, 'Expr_a', 2, [1, 2]]]));
        $this->expectException(\LogicException::class);
        $census->recordFile('b.php', self::payload([['k', 'ab', 9, 'Expr_a', 1, [1]]]), );
    }

    public function testMalformedPayloadsHalt(): void
    {
        $census = new SubtreeCensus();
        $this->expectException(\InvalidArgumentException::class);
        $census->recordFile('a.php', self::payload([['k', 'ab', 8]])); // truncated row
    }

    public function testZeroCountSiteRowHalts(): void
    {
        $census = new SubtreeCensus();
        $this->expectException(\InvalidArgumentException::class);
        $census->recordFile('a.php', self::payload([['k', 'ab', 8, 'Expr_a', 0, []]]));
    }

    public function testJsonBoundaryParsesOnce(): void
    {
        $census = new SubtreeCensus();
        $json   = json_encode(self::payload([['k', 'ab', 6, 'Expr_a', 2, [3, 9]]]), JSON_THROW_ON_ERROR);
        $census->recordFileJson('a.php', $json);
        $row = $census->rows()[0];
        self::assertSame(['a.php:3', 'a.php:9'], $row->locations);
    }

    public function testRecordSiteCounters(): void
    {
        $census = new SubtreeCensus();
        $census->recordFile('a.php', self::payload([['k', 'ab', 6, 'Expr_a', 3, [1]], ['k2', 'ab2', 7, 'Expr_a', 1, [2]]]));
        self::assertSame(3, $census->sitesOf('k'));
        self::assertSame(1, $census->sitesOf('k2'));
        self::assertSame(0, $census->sitesOf('nope'));
        self::assertSame(1, $census->filesOf('k'));
        self::assertSame(4, $census->sitesTotal());
        self::assertSame(2, $census->hashCount());
    }
}
