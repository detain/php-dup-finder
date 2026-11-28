<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Reporting;

use PHPUnit\Framework\TestCase;
use Phpdup\Census\CallSequenceCensus;
use Phpdup\Census\LiteralCensus;
use Phpdup\Cli\Config;
use Phpdup\Reporting\JsonReporter;
use Phpdup\Reporting\JsonSchemaSpec;
use Phpdup\Reporting\Report;

/**
 * Items 3.5/3.3 report surface (MINE-E): the two census blocks are
 * CONDITIONAL trailing summary keys — carried render, absent-carriers
 * emit NOTHING (frozen goldens cannot move; no SCHEMA_VERSION event,
 * per the MINE-D r1 conditional-key ruling). The belts spell the shapes
 * (summaryShape/callBlockShape/literalBlockShape); these tests close the
 * loop on real bytes, including the differing-constant table that is the
 * item's acceptance ("cURL bootstrap with CURLOPT_SSL_VERIFYPEER off").
 */
final class JsonReporterCallLiteralTest extends TestCase
{
    /** @param array<string,list<array{names:list<string>,lines:list<int>}> > $perFile */
    private static function callsWith(array $perFile): CallSequenceCensus
    {
        $census = new CallSequenceCensus();
        foreach ($perFile as $file => $records) {
            $payload = array_map(static fn (array $r): array => [
                'names' => $r['names'], 'lines' => $r['lines'],
                'args'  => array_fill(0, count($r['names']), []),
                'firstLine' => min($r['lines']), 'lastLine' => max($r['lines']),
            ], $records);
            $census->recordFileJson($file, json_encode($payload));
        }
        return $census;
    }

    /** @param array<string,list<array{kind:string,value:string,count?:int,lines?:list<int>}>> $perFile */
    private static function literalsWith(array $perFile): LiteralCensus
    {
        $census = new LiteralCensus();
        foreach ($perFile as $file => $rows) {
            $payload = array_map(static fn (array $r): array => [
                $r['kind'], sha1($r['kind'] . "\1" . $r['value']), $r['value'], '', $r['count'] ?? 1,
                $r['lines'] ?? [3],
            ], $rows);
            $census->recordFileJson($file, json_encode($payload));
        }
        return $census;
    }

    /** @param array{0:?CallSequenceCensus,1:?LiteralCensus} $carriers */
    private static function report(array $carriers): Report
    {
        return new Report(
            files: 2, blocks: 9, parseErrors: 0, clusters: [],
            config: Config::defaults(['/nowhere']),
            callSequences: $carriers[0],
            literalCensus: $carriers[1],
        );
    }

    public function testNullCarriersEmitNeitherKey(): void
    {
        $summary = (new JsonReporter())->build(self::report([null, null]))['summary'];
        $this->assertArrayNotHasKey('call_sequences', $summary);
        $this->assertArrayNotHasKey('literal_census', $summary);
        $this->assertSame(array_keys(JsonSchemaSpec::summaryShape(false)), array_keys($summary));
        $this->assertArrayNotHasKey('call_sequences', JsonSchemaSpec::summaryShape());
        $this->assertArrayNotHasKey('literal_census', JsonSchemaSpec::summaryShape());
    }

    public function testEachCarrierTrailsItsOwnConditionalKeyInSpecOrder(): void
    {
        $calls   = self::callsWith(['x.php' => [['names' => ['a', 'b'], 'lines' => [1, 2]]]]);
        $literal = self::literalsWith(['x.php' => [['kind' => 'string', 'value' => 'a_shared_literal_value']]]);

        $onlyCalls = (new JsonReporter())->build(self::report([$calls, null]))['summary'];
        $this->assertSame(
            array_merge(array_keys(JsonSchemaSpec::summaryShape(false)), ['call_sequences']),
            array_keys($onlyCalls),
        );
        $this->assertSame(array_keys(JsonSchemaSpec::callBlockShape()), array_keys($onlyCalls['call_sequences']));

        $onlyLit = (new JsonReporter())->build(self::report([null, $literal]))['summary'];
        $this->assertSame(
            array_merge(array_keys(JsonSchemaSpec::summaryShape(false)), ['literal_census']),
            array_keys($onlyLit),
        );
        $this->assertSame(array_keys(JsonSchemaSpec::literalBlockShape()), array_keys($onlyLit['literal_census']));

        $both = (new JsonReporter())->build(self::report([$calls, $literal]))['summary'];
        $this->assertSame(
            array_merge(array_keys(JsonSchemaSpec::summaryShape(false)), ['call_sequences', 'literal_census']),
            array_keys($both),
        );
    }

    public function testCallBlockRendersTheDifferingConstantTable(): void
    {
        // The item's archetype: two insecure cURL bootstraps + one that
        // turned CURLOPT_SSL_VERIFYPEER on — same sequence, differing
        // constant arguments must fall out as a table.
        $census = new CallSequenceCensus();
        $boot = static fn (string $verify): array => [[
            'names' => ['curl_init', 'curl_setopt_array', 'curl_exec'],
            'lines' => [2, 3, 4],
            'args'  => [[], ['CURLOPT_SSL_VERIFYPEER=>' . $verify], []],
            'firstLine' => 2, 'lastLine' => 4,
        ]];
        $census->recordFileJson('/p/insecure_a.php', json_encode($boot('false')));
        $census->recordFileJson('/p/insecure_b.php', json_encode($boot('false')));
        $census->recordFileJson('/p/secure.php', json_encode($boot('true')));

        $block = (new JsonReporter())->build(self::report([$census, null]))['summary']['call_sequences'];
        $this->assertGreaterThanOrEqual(3, $block['windows'], 'the three-window family is all counted');
        $this->assertGreaterThanOrEqual(3, $block['sites']);
        $row = $block['rows'][0];
        $this->assertSame(['names', 'length', 'sites', 'files', 'locations', 'arg_diff'], array_keys($row));
        $this->assertSame(['curl_init', 'curl_setopt_array', 'curl_exec'], $row['names']);
        $this->assertSame(3, $row['sites']);
        $this->assertSame(3, $row['files']);
        $this->assertCount(1, $row['arg_diff'], 'only the one differing slot is tabled');
        $diff = $row['arg_diff'][0];
        $this->assertSame(1, $diff['slot']);
        $this->assertSame('curl_setopt_array', $diff['call']);
        $values = array_column($diff['values'], 'token');
        $this->assertContains('CURLOPT_SSL_VERIFYPEER=>false', $values);
        $this->assertContains('CURLOPT_SSL_VERIFYPEER=>true', $values);
        $this->assertSame(2, $diff['values'][0]['sites'], 'count-desc ordering puts the two-site spelling first');
    }

    public function testLiteralBlockRendersCountersRowsAndFamilies(): void
    {
        $literal = self::literalsWith([
            '/p/a.php' => [
                ['kind' => 'string', 'value' => 'https://cdn.example.com/assets/v42/checkout.js'],
                ['kind' => 'string', 'value' => 'https://cdn.example.com/assets/v42/orders.js'],
            ],
            '/p/b.php' => [
                ['kind' => 'string', 'value' => 'https://cdn.example.com/assets/v42/checkout.js'],
                ['kind' => 'string', 'value' => 'https://cdn.example.com/assets/v42/orders.js'],
            ],
        ]);
        $block = (new JsonReporter())->build(self::report([null, $literal]))['summary']['literal_census'];
        $this->assertSame(['kinds', 'sites', 'rows', 'families', 'families_computed'], array_keys($block));
        $this->assertSame(['string', 'array', 'number', 'skeleton', 'const'], array_keys($block['kinds']),
            'every bucket of the item letter reports, even at zero');
        $this->assertSame(4, $block['sites'], 'corpus-complete count includes singleton support');
        $this->assertCount(2, $block['rows'], 'the two ×2-copy URLs surface as rows');
        $this->assertSame(['kind', 'hash', 'sample', 'roles', 'sites', 'files', 'locations'], array_keys($block['rows'][0]));
        $this->assertTrue($block['families_computed']);
        $this->assertCount(1, $block['families'], 'the two drifted spellings are ONE near-duplicate family');
        $this->assertSame(['members', 'sites', 'files'], array_keys($block['families'][0]));
        $this->assertSame(4, $block['families'][0]['sites']);
    }

    public function testTopFiftyCapTruncatesRowsNeverCounters(): void
    {
        $names = [];
        for ($i = 0; $i < 52; $i++) {
            $names[] = 'op' . $i;
        }
        $calls = self::callsWith([
            '/p/a.php' => [['names' => $names, 'lines' => range(1, 52)]],
            '/p/b.php' => [['names' => $names, 'lines' => range(1, 52)]],
        ]);
        // 150 enumerated windows over two files; lawful chain suppression
        // leaves the 49 four-name windows maximal. Sixty disjoint pairs
        // add sixty unsuppressible rows — the surface then truncates at
        // fifty while every counter stays corpus-complete.
        for ($j = 0; $j < 60; $j++) {
            $record = json_encode([[
                'names' => ['w' . $j, 'x' . $j], 'lines' => [1, 2], 'args' => [[], []],
                'firstLine' => 1, 'lastLine' => 2,
            ]]);
            $calls->recordFileJson("/p/c$j.php", $record);
            $calls->recordFileJson("/p/e$j.php", $record);
        }
        $block = (new JsonReporter())->build(self::report([$calls, null]))['summary']['call_sequences'];
        $this->assertCount(50, $block['rows'], 'presentation is capped');
        $this->assertSame(210, $block['windows'], '150 enumerated + 60 disjoint stay counted');
        $this->assertSame(420, $block['sites'], 'cap never shrinks the gauges');

        $rows = [];
        for ($i = 0; $i < 51; $i++) {
            $rows[] = ['kind' => 'string', 'value' => sprintf('literal_number_%03d_shared', $i)];
        }
        $literal = self::literalsWith(['/p/a.php' => $rows, '/p/b.php' => $rows]);
        $litBlock = (new JsonReporter())->build(self::report([null, $literal]))['summary']['literal_census'];
        $this->assertCount(50, $litBlock['rows']);
        $this->assertSame(102, $litBlock['sites'], 'truncation is presentation-only');
    }
}
