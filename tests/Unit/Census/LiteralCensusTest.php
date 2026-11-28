<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Census;

use PHPUnit\Framework\TestCase;
use Phpdup\Census\LiteralCensus;

/**
 * Item 3.3 census (MINE-E): cross-file aggregation of the per-file
 * literal rows. Support gates (entries need >=2 sites; numbers need >=2
 * files — the doc's "86400 on 112 lines" wants breadth, not one hot
 * file); roles only matter for the const bucket; families run the
 * bullet-3 near-duplicate pass with a hard distinct-string cap.
 */
final class LiteralCensusTest extends TestCase
{
    /** @param list<array{0:string,1:string,2:string,3:string,4:int,5:list<int>}> $rows */
    private static function record(LiteralCensus $census, string $file, array $rows): void
    {
        $census->recordFileJson($file, json_encode($rows, JSON_THROW_ON_ERROR));
    }

    private static function row(string $kind, string $sample, string $role = '', int $count = 1, array $lines = [2]): array
    {
        $hash = $kind === 'const' ? sha1("NAME\1" . $sample) : sha1($kind . "\1" . $sample);
        return [$kind, $hash, $sample, $role, $count, $lines];
    }

    public function testSupportGateAndNumberFileRule(): void
    {
        $census = new LiteralCensus();
        self::record($census, 'a.php', [
            self::row('string', 'lonely_copy', '', 1, [3]),
            self::row('string', 'twice_same_file', '', 2, [4, 9]),
            self::row('number', '86400 @Arg', '', 2, [5, 6]),
        ]);
        self::record($census, 'b.php', [self::row('number', '86400 @Arg', '', 1, [7])]);

        $rows = $census->rows();
        $bySample = [];
        foreach ($rows as $r) {
            $bySample[$r->sample] = $r;
        }
        self::assertArrayNotHasKey('lonely_copy', $bySample, 'one site is not a repetition');
        self::assertArrayHasKey('twice_same_file', $bySample, 'two sites in one file DO count for strings');
        self::assertSame(2, $bySample['twice_same_file']->sites);
        self::assertSame(1, $bySample['twice_same_file']->files);
        self::assertArrayHasKey('86400 @Arg', $bySample, 'numbers need two files — a and b supply them');
        self::assertSame(3, $bySample['86400 @Arg']->sites);
        self::assertSame(2, $bySample['86400 @Arg']->files);
    }

    public function testNumberSingleFileNeverRows(): void
    {
        $census = new LiteralCensus();
        self::record($census, 'hot.php', [self::row('number', '4096 @Arg', '', 12, range(2, 5))]);
        self::assertSame([], $census->rows(), 'one hot file is not the "112 lines" story');
        self::assertSame(12, $census->bucketTotals()['number']['sites'], 'the gauge still counts all support levels');
    }

    public function testConstRolesMergeUnderOneNameKey(): void
    {
        $census = new LiteralCensus();
        self::record($census, 'a.php', [self::row('const', 'STATUS_MAP', 'decl', 1, [3])]);
        self::record($census, 'b.php', [
            self::row('const', 'STATUS_MAP', 'decl', 1, [3]),
            self::row('const', 'STATUS_MAP', 'use', 2, [9, 10]),
        ]);
        $rows = $census->rows();
        self::assertCount(1, $rows);
        self::assertSame(['decl' => 2, 'use' => 2], $rows[0]->roles, 'role counters aggregate per key');
        self::assertSame(4, $rows[0]->sites);
        self::assertSame(2, $rows[0]->files);
        // Two-pass materialisation is SubtreeCensus law VERBATIM — the
        // list is ascending in the string key 'file:line', which orders
        // 'b.php:10' before 'b.php:3' lexicographically. The substrate's
        // bounded-sample law governs; readers must not assume numeric
        // line order within one file (disclosed in the lane report).
        self::assertSame(['a.php:3', 'b.php:10', 'b.php:3', 'b.php:9'], $rows[0]->locations);
    }

    public function testSampleConflictUnderSameKeyRefusesLoudly(): void
    {
        $census = new LiteralCensus();
        self::record($census, 'a.php', [['string', sha1('shared-key'), 'first-reading', '', 1, [2]]]);
        self::expectException(\LogicException::class);
        self::record($census, 'b.php', [['string', sha1('shared-key'), 'other-reading', '', 1, [3]]]);
    }

    public function testTwoPassLocationsAndCapEviction(): void
    {
        $census = new LiteralCensus(sampleCap: 2);
        foreach (['e1', 'e2', 'e3'] as $stem) {
            self::record($census, $stem . '.php', [self::row('string', 'a_shared_literal_string', '', 1, [4])]);
        }
        $rows = $census->rows();
        self::assertCount(1, $rows);
        self::assertSame(3, $rows[0]->sites, 'support is complete even when samples are capped');
        self::assertSame(['e1.php:4', 'e2.php:4'], $rows[0]->locations, 'lowest keys survive eviction');
    }

    public function testFamiliesLinkDriftingCopies(): void
    {
        $census = new LiteralCensus();
        // A five-syllable drift chain: each neighbour within the band.
        $values = [
            'https://cdn.example.com/assets/v42/checkout.js',
            'https://cdn.example.com/assets/v42/orders.js',
            'https://cdn.example.com/assets/v42/carts.js',
            'unrelated_string_entirely_x',
            'also_unrelated_string_pack_y',
        ];
        foreach ($values as $i => $v) {
            self::record($census, 'f' . $i . '.php', [self::row('string', $v, '', 1, [2])]);
        }
        $families = $census->families();
        self::assertNotEmpty($families);
        $top = $families[0];
        self::assertContains('https://cdn.example.com/assets/v42/checkout.js', $top['members']);
        self::assertContains('https://cdn.example.com/assets/v42/orders.js', $top['members']);
        self::assertNotContains('unrelated_string_entirely_x', $top['members']);
        self::assertSame(3, $top['sites'], 'family sites sum the members\' support');
        self::assertSame($top['members'], [...$top['members']], 'members are value-ascending');
        self::assertTrue($census->familiesComputed());
    }

    public function testSingletonsNeverFamilyAndIsolatedPairLinks(): void
    {
        $census = new LiteralCensus();
        self::record($census, 'a.php', [
            self::row('string', 'shared_family_member_01', '', 1, [2]),
            self::row('string', 'zeta_unrelated_string_9', '', 1, [3]),
        ]);
        self::record($census, 'b.php', [
            self::row('string', 'shared_family_member_02', '', 1, [2]),
        ]);
        $families = $census->families();
        self::assertCount(1, $families, 'only the 01/02 pair is within the edit budget');
        self::assertSame(['shared_family_member_01', 'shared_family_member_02'], $families[0]['members']);
        self::assertSame(2, $families[0]['files']);
    }

    public function testFamilyCapSkipsThePassAndDiscloses(): void
    {
        $census = new LiteralCensus();
        // One file, FAMILY_MAX_DISTINCT + 2 distinct strings plus a drift
        // pair — the affordability flag must flip and the pass be skipped
        // whole (a partial family ledger would lie).
        $rows = [self::row('string', 'sibling_drift_0001', '', 1, [2])];
        for ($i = 0; $i <= LiteralCensus::FAMILY_MAX_DISTINCT + 1; $i++) {
            $rows[] = self::row('string', sprintf('distinct_entry_%06d', $i), '', 1, [3]);
        }
        self::record($census, 'big.php', $rows);
        self::assertFalse($census->familiesComputed(), 'the distinct-string cap is enforced');
        self::assertSame([], $census->families(), 'and the ledger is empty, not partial');
        self::assertGreaterThan(LiteralCensus::FAMILY_MAX_DISTINCT, $census->bucketTotals()['string']['entries'],
            'the census itself still ran');
    }

    public function testMemberCapTruncatesListedValues(): void
    {
        $census = new LiteralCensus();
        $base   = 'prefix_family_member_';
        $rows   = [];
        for ($i = 0; $i < LiteralCensus::FAMILY_MEMBER_CAP + 3; $i++) {
            $rows[] = self::row('string', $base . sprintf('%02d', $i), '', 1, [2]);
        }
        self::record($census, 'a.php', $rows);
        self::record($census, 'b.php', [self::row('string', $base . '99', '', 1, [2])]);
        $families = $census->families();
        self::assertCount(1, $families);
        self::assertCount(LiteralCensus::FAMILY_MEMBER_CAP, $families[0]['members'],
            'listed members cap at 10 (sites stay corpus-complete)');
        self::assertSame(LiteralCensus::FAMILY_MEMBER_CAP + 4, $families[0]['sites'],
            'every family site counts, listed or not');
    }

    public function testDeterminismUnderPermutedIngest(): void
    {
        $recs = [
            'a.php' => [self::row('string', 'repeated_string_A', '', 1, [2]), self::row('number', '86400 @Arg', '', 1, [3])],
            'b.php' => [self::row('string', 'repeated_string_B', '', 1, [4]), self::row('number', '86400 @Arg', '', 1, [5])],
            'c.php' => [self::row('string', 'repeated_string_A', '', 1, [6]), self::row('const', 'STATUS_MAP', 'use', 1, [7])],
        ];
        $build = static function (array $order) use ($recs): string {
            $census = new LiteralCensus();
            foreach ($order as $file) {
                self::record($census, $file, $recs[$file]);
            }
            return json_encode([
                array_map(static fn($r): array => [$r->kind, $r->hash, $r->sites, $r->files, $r->locations], $census->rows()),
                $census->families(),
                $census->bucketTotals(),
            ], JSON_THROW_ON_ERROR);
        };
        self::assertSame(
            $build(['a.php', 'b.php', 'c.php']),
            $build(['c.php', 'b.php', 'a.php']),
            'ingest order cannot move the ledger',
        );
    }

    public function testMalformedRowsRefuseLoudly(): void
    {
        $census = new LiteralCensus();
        $this->expectException(\InvalidArgumentException::class);
        $census->recordFileJson('x.php', json_encode([['string', 'h', 's', '', 1]]));
    }
}
