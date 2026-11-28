<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Census;

use PHPUnit\Framework\TestCase;
use Phpdup\Census\SubtreeCensus;

/**
 * Item 3.2 (MINE-D-2) — the additive per-file retention on the census:
 * {@see SubtreeCensus::sitesPerFile()} /
 * {@see SubtreeCensus::linesPerFile()} give helper-span membership
 * without a second miner pass. The belt here is that the 3.1 public
 * output (rows()/maximalRows()) is UNCHANGED by the retention — the
 * MINE-C/MINE-D consumer belts stay valid as written.
 */
final class SubtreeCensusFileSitesTest extends TestCase
{
    /** @param list<array{0:string,1:string,2:int,3:string,4:int,5:list<int>}> $sites */
    private static function payload(array $sites, array $edges = []): array
    {
        return ['sites' => $sites, 'edges' => $edges];
    }

    public function testSitesPerFileSumsAcrossRowsAndFiles(): void
    {
        $census = new SubtreeCensus();
        $census->recordFile('b.php', self::payload([['K', 'A', 6, 'Expr_x', 2, [7, 9]]]));
        $census->recordFile('a.php', self::payload([['K', 'A', 6, 'Expr_x', 1, [4]]]));

        self::assertSame(['a.php' => 1, 'b.php' => 2], $census->sitesPerFile('K'), 'files sorted, counts summed');
        self::assertSame([], $census->sitesPerFile('nope'));
    }

    public function testLinesPerFileKeepsWireSamples(): void
    {
        $census = new SubtreeCensus();
        $census->recordFile('a.php', self::payload([['K', 'A', 6, 'Expr_x', 5, [4, 9]]]));

        self::assertSame(['a.php' => [4, 9]], $census->linesPerFile('K'));
        self::assertSame([], $census->linesPerFile('K2'));
        self::assertSame(5, $census->sitesOf('K'), 'lines are samples, sites the truth (existing policy)');
    }

    public function testWireValidationStillHolds(): void
    {
        $census = new SubtreeCensus();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('wire cap');
        $census->recordFile('a.php', self::payload([['K', 'A', 6, 'Expr_x', 1, [1, 2, 3, 4, 5]]]));
    }

    public function testNonIntLineStillRefused(): void
    {
        $census = new SubtreeCensus();
        try {
            $census->recordFile('a.php', self::payload([['K', 'A', 6, 'Expr_x', 1, ['7']]]));
            self::fail('string line must not launder into retention');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('must be ints', $e->getMessage());
        }
    }

    public function testPublicRowOutputUnchangedByRetention(): void
    {
        $census = new SubtreeCensus();
        $census->recordFile('a.php', self::payload([
            ['K', 'A', 6, 'Expr_x', 1, [11]],
            ['J', 'B', 9, 'Stmt_y', 1, [12]],
        ], [['J', 'K', 1]]));
        $census->recordFile('b.php', self::payload([
            ['K', 'A', 6, 'Expr_x', 1, [5]],
            ['J', 'B', 9, 'Stmt_y', 1, [7]],
        ], [['J', 'K', 1]]));

        // Same expectation the pre-retention belt SubtreeCensusTest family
        // would have produced: counters, canonical locations, suppression.
        $rows = $census->rows();
        self::assertSame(['J', 'K'], array_map(static fn($r) => $r->exactHash, $rows), 'sites desc tie -> exact asc');
        foreach ($rows as $r) {
            self::assertSame(2, $r->sites);
            self::assertSame(2, $r->files);
        }
        self::assertSame(['a.php:12', 'b.php:7'], $rows[0]->locations);
        self::assertSame(['K'], array_map(static fn($r) => $r->exactHash, $census->maximalRows()), 'edge suppression semantics untouched');
    }
}
