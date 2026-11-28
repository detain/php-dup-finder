<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Census;

use PHPUnit\Framework\TestCase;
use Phpdup\Census\CallProtocolRow;
use Phpdup\Census\CallSequenceCensus;

/**
 * Item 3.5 census (MINE-E): sliding-window support over the one 'calls'
 * row stream. Windows are contiguous, 2..4 long; support counts every
 * site (any list holding the window, cross-file through the two-pass
 * locations); maximalRows applies the substrate's NestedFamilySuppression
 * on immediate-extension edges; arg tables ride the corpus-complete
 * per-call witness bags (slot = call position in the window).
 */
final class CallSequenceCensusTest extends TestCase
{
    /** @param list<string> $names @param list<int> $lines @param list<list<string>> $args */
    private static function record(array $names, array $lines, array $args, int $first = 1, int $last = 99): string
    {
        return json_encode([[
            'names'     => $names,
            'lines'     => $lines,
            'args'      => $args,
            'firstLine' => $first,
            'lastLine'  => $last,
        ]], JSON_THROW_ON_ERROR);
    }

    public function testWindowEnumerationCounts(): void
    {
        $census = new CallSequenceCensus();
        $census->recordFileJson('a.php', self::record(['a', 'b', 'c'], [2, 3, 4], [[], [], []]));
        self::assertSame(3, $census->windowCount(), 'ab, bc and abc — contiguous only');
        self::assertSame(3, $census->sitesTotal());
        self::assertSame(1, $census->sitesOf("a\0b"));
        self::assertSame(1, $census->sitesOf("b\0c"));
        self::assertSame(0, $census->sitesOf("a\0c"), 'non-contiguous pairs are never windows');
        self::assertSame([], $census->rows(), 'single-site windows are support-invisible');
    }

    public function testCrossFileSupportRowsAndLocations(): void
    {
        $census = new CallSequenceCensus();
        $census->recordFileJson('alpha.php', self::record(['init', 'exec'], [7, 9], [['u1'], []]));
        $census->recordFileJson('beta.php', self::record(['init', 'exec'], [4, 6], [['u2'], []]));

        $rows = $census->rows();
        self::assertCount(1, $rows);
        self::assertSame(['init', 'exec'], $rows[0]->names);
        self::assertSame(2, $rows[0]->sites);
        self::assertSame(2, $rows[0]->files);
        self::assertSame(2, $rows[0]->length);
        self::assertSame(['alpha.php:7', 'beta.php:4'], $rows[0]->locations,
            'canonical lowest file:line samples, one per sighting');
    }

    public function testMaximalSuppressionOnExtensionEdges(): void
    {
        $census = new CallSequenceCensus();
        foreach (['f1.php', 'f2.php', 'f3.php'] as $f) {
            $census->recordFileJson($f, self::record(['a', 'b', 'c'], [1, 2, 3], [[], [], []]));
        }
        // ab, bc and abc all reach support 3; abc extends both smaller
        // windows at every site, so the maximal ledger is the 3-chain.
        $maximal = $census->maximalRows();
        self::assertCount(1, $maximal);
        self::assertSame(['a', 'b', 'c'], $maximal[0]->names);
        self::assertCount(3, $census->rows(), 'support-filtered rows keep all three');
    }

    public function testDifferingConstantTableIsCorpusComplete(): void
    {
        $census = new CallSequenceCensus();
        foreach (['f1.php', 'f2.php'] as $f) {
            $census->recordFileJson($f, self::record(['init', 'setopt'], [1, 2], [
                ['https://api.example.com/x'],
                ['CURLOPT_RETURNTRANSFER=>true', 'CURLOPT_SSL_VERIFYPEER=>false'],
            ]));
        }
        $census->recordFileJson('f3.php', self::record(['init', 'setopt'], [1, 2], [
            ['https://api.example.com/y'],
            ['CURLOPT_RETURNTRANSFER=>true', 'CURLOPT_SSL_VERIFYPEER=>true'],
        ]));

        $rows  = $census->rows();
        self::assertCount(1, $rows);
        $table = $rows[0]->argDiff;
        self::assertCount(2, $table, 'both slots differ: the URLs 2:1 and the option bag with a 2/1 split');

        [$slot0, $slot1] = $table;
        self::assertSame(0, $slot0['slot']);
        self::assertSame(['https://api.example.com/x', 'https://api.example.com/y'], array_column($slot0['values'], 'token'));
        self::assertSame([2, 1], array_column($slot0['values'], 'sites'));

        self::assertSame(1, $slot1['slot']);
        self::assertSame('setopt', $slot1['call']);
        self::assertSame(0, $slot1['none']);
        self::assertSame(
            ['CURLOPT_RETURNTRANSFER=>true', 'CURLOPT_SSL_VERIFYPEER=>false', 'CURLOPT_SSL_VERIFYPEER=>true'],
            array_column($slot1['values'], 'token'),
            'count desc, then token asc — the universal RETURNTRANSFERTRUE rides with the split (bag truth)',
        );
        self::assertSame([3, 2, 1], array_column($slot1['values'], 'sites'),
            'corpus-complete counters, not sample-windowed: 2 false, 1 true in full');
    }

    public function testValuesPerSlotCap(): void
    {
        $tokens = [];
        for ($i = 0; $i < 11; $i++) {
            $tokens[] = "OPT_$i=>v";
        }
        $census = new CallSequenceCensus();
        foreach (['f1.php', 'f2.php'] as $f) {
            $census->recordFileJson($f, self::record(['wide', 'tail'], [1, 2], [$tokens, []]));
        }
        $rows = $census->rows();
        self::assertCount(1, $rows);
        $values = $rows[0]->argDiff[0]['values'];
        self::assertCount(CallProtocolRow::ARG_VALUES_PER_SLOT, $values, 'top-8 per slot (disclosed)');
        self::assertSame('OPT_0=>v', $values[0]['token'], 'all counts tie at 2: token-asc decides');
    }

    public function testPureHoleAndUniversalSlotsNeverTabled(): void
    {
        $census = new CallSequenceCensus();
        foreach (['f1.php', 'f2.php'] as $f) {
            $census->recordFileJson($f, self::record(['open', 'write'], [1, 2], [[], ['MODE_RW']]));
        }
        $rows = $census->rows();
        self::assertSame([], $rows[0]->argDiff,
            'a pure-hole slot is uniform-by-absence and a single universal token is no difference');
    }

    public function testSitesForMethodReceiverBlind(): void
    {
        $census  = new CallSequenceCensus();
        $census->recordFileJson('a.php', self::record(['getenv'], [5], [[]]));
        $census->recordFileJson('b.php', self::record(['Checkout::status'], [2], [[]]));

        self::assertSame(['callers' => 1, 'locations' => ['a.php:5']], $census->sitesForMethod('getenv'));
        $status = $census->sitesForMethod('status');
        self::assertSame(1, $status['callers'], 'the class part is not consulted (receiver-blind, disclosed)');
        self::assertSame(['b.php:2'], $status['locations']);
        self::assertSame(1, $census->sitesForMethod('STATUS')['callers'], 'method names match case-insensitively');
    }

    public function testSitesForMethodExcludesHelperOwnBody(): void
    {
        $census = new CallSequenceCensus();
        $census->recordFileJson('Env.php', self::record(['getenv', 'getenv', 'getenv'], [12, 17, 18], [[], [], []]));
        $census->recordFileJson('in.php', self::record(['getenv'], [3], [[]]));

        self::assertSame(4, $census->sitesForMethod('getenv')['callers'], 'unguarded: every sighting');
        $guarded = $census->sitesForMethod('getenv', 'Env.php', 10, 13);
        self::assertSame(3, $guarded['callers'], 'the definition span is not the helper calling itself');
        self::assertSame(['Env.php:17', 'Env.php:18', 'in.php:3'], $guarded['locations']);
    }

    public function testNameIndexOverflowCountsAsExternal(): void
    {
        $census = new CallSequenceCensus();
        $census->recordFileJson('busy.php', self::record(
            array_fill(0, 20, 'tick'),
            array_fill(0, 20, 4),
            array_fill(0, 20, []),
            4,
            4,
        ));
        $result = $census->sitesForMethod('tick', 'nowhere.php');
        self::assertSame(20, $result['callers'], 'capped stored lines still count as external occurrences');
        self::assertLessThanOrEqual(CallSequenceCensus::DEFAULT_SAMPLE_CAP, count($result['locations']));
    }

    public function testSampleCapEvictionKeepsLowestKey(): void
    {
        $census = new CallSequenceCensus(sampleCap: 2);
        foreach (['e1', 'e2', 'e3', 'e4'] as $stem) {
            $census->recordFileJson($stem . '.php', self::record(['dup', 'dup'], [1, 2], [[], []], 1, 2));
        }
        $rows = $census->rows();
        self::assertCount(1, $rows);
        self::assertSame(4, $rows[0]->sites, 'support is complete even where samples are capped');
        self::assertSame(['e1.php:1', 'e2.php:1'], $rows[0]->locations,
            'lowest file:line key survives eviction (SubtreeCensus law)');
    }

    public function testDeterminismUnderPermutedIngest(): void
    {
        $recs = [
            'f1.php' => self::record(['a', 'b', 'c', 'd'], [1, 2, 3, 4], [['x'], [], ['K=>v'], ['tail']]),
            'f2.php' => self::record(['a', 'b'], [7, 8], [['y'], ['K=>v']]),
            'f3.php' => self::record(['b', 'c', 'd'], [3, 4, 5], [[], ['K=>w'], ['tail']]),
        ];
        $build = static function (array $order) use ($recs): string {
            $census = new CallSequenceCensus();
            foreach ($order as $file) {
                $census->recordFileJson($file, $recs[$file]);
            }
            return json_encode(array_map(
                static fn(CallProtocolRow $r): array => [$r->names, $r->sites, $r->files, $r->locations, $r->argDiff],
                $census->maximalRows(),
            ), JSON_THROW_ON_ERROR);
        };
        self::assertSame($build(['f1.php', 'f2.php', 'f3.php']), $build(['f3.php', 'f1.php', 'f2.php']),
            'ingest order cannot move the ledger — sites are canonical-keyed');
    }

    public function testMalformedRecordsRefuseLoudly(): void
    {
        $census = new CallSequenceCensus();
        $this->expectException(\InvalidArgumentException::class);
        $census->recordFileJson('x.php', json_encode([['names' => ['a'], 'lines' => [1, 2], 'args' => [[]]]]));
    }
}
