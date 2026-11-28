<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Reporting;

use Phpdup\Cli\Config;
use Phpdup\Git\ClusterHistory;
use Phpdup\Reporting\JsonReporter;
use Phpdup\Reporting\JsonSchemaSpec;
use Phpdup\Reporting\Report;
use PHPUnit\Framework\TestCase;

/**
 * Item 4.2 — the conditional summary key contract (MINE-D §4 posture):
 * `summary.cluster_history` exists ONLY on armed payloads, trails the
 * idiom block when both arm, and its emitted shape matches the spelled
 * armed spec — while the unarmed key set stays pinned to
 * summaryShape(false) and the frozen goldens' bytes behind it.
 */
final class JsonReporterHistoryTest extends TestCase
{
    /** @return array{cluster: string, verdict: string, reason: string|null, members: list<array<string,mixed>>, co_change: list<array{a:string,b:string,times:int}>} */
    private static function row(string $id, string $verdict): array
    {
        return [
            'cluster'   => $id,
            'verdict'   => $verdict,
            'reason'    => $verdict === 'unmeasurable' ? 'no_repository' : null,
            'members'   => [
                [
                    'file'      => 'src/A.php',
                    'last_sha'  => 'abcdef123456',
                    'last_date' => '2026-03-01',
                    'lone'      => [
                        'sha'     => 'abcdef123456',
                        'date'    => '2026-03-01',
                        'subject' => 'Fix missing CSRF guard',
                        'fix'     => true,
                    ],
                ],
                [
                    'file'      => 'src/B.php',
                    'last_sha'  => '123456abcdef',
                    'last_date' => '2024-01-15',
                    'lone'      => null,
                ],
            ],
            'co_change' => [['a' => 'src/A.php', 'b' => 'src/B.php', 'times' => 2]],
        ];
    }

    private static function report(?ClusterHistory $history, bool $withCensus = false): Report
    {
        $census = null;
        if ($withCensus) {
            $census = new \Phpdup\Census\SubtreeCensus(10);
            $census->recordFile('/p/x.php', ['sites' => [['h' . str_repeat('1', 39), 'i' . str_repeat('1', 39), 6, 'If_', 1, [4]]], 'edges' => []]);
        }
        return new Report(
            files: 2, blocks: 9, parseErrors: 0, clusters: [],
            config: Config::defaults(['/nowhere']),
            idiomCensus: $census,
            clusterHistory: $history,
        );
    }

    public function testUnarmedPayloadCarriesNoHistoryKey(): void
    {
        $summary = (new JsonReporter())->build(self::report(null))['summary'];
        self::assertArrayNotHasKey('cluster_history', $summary);
        self::assertSame(array_keys(JsonSchemaSpec::summaryShape(false)), array_keys($summary));
        self::assertArrayNotHasKey('cluster_history', JsonSchemaSpec::summaryShape());
    }

    public function testArmedKeyTrailsTheSpecOrder(): void
    {
        $payload = (new JsonReporter())->build(self::report(new ClusterHistory(
            rows: [self::row('C0001aa', 'recent_lone_fix')],
            windowMonths: 12,
            omitted: 0,
            notes: ['history: 0 files outside a repo'],
        )));
        $summary = $payload['summary'];
        self::assertSame(
            array_merge(array_keys(JsonSchemaSpec::summaryShape(false)), ['cluster_history']),
            array_keys($summary),
            'history-armed only = default set + trailing cluster_history',
        );
        self::assertSame(array_keys(JsonSchemaSpec::summaryShape(false, true)), array_keys($summary));
        self::assertSame(array_keys(JsonSchemaSpec::historyBlockShape()), array_keys($summary['cluster_history']));
    }

    public function testBothArmsKeepIdiomFirstHistoryLast(): void
    {
        $summary = (new JsonReporter())->build(self::report(
            new ClusterHistory(rows: [], windowMonths: 6, omitted: 40, notes: []),
            withCensus: true,
        ))['summary'];
        self::assertSame(
            array_keys(JsonSchemaSpec::summaryShape(true, true)),
            array_keys($summary),
        );
        self::assertSame(array_search('idiom_census', array_keys($summary), true) + 1, array_search('cluster_history', array_keys($summary), true));
    }

    public function testBlockContentIsPassThroughWithCounters(): void
    {
        $history = new ClusterHistory(
            rows: [self::row('C0001aa', 'recent_lone_fix'), self::row('C0002bb', 'unmeasurable')],
            windowMonths: 12,
            omitted: 0,
            notes: [],
        );
        $block = (new JsonReporter())->build(self::report($history))['summary']['cluster_history'];
        self::assertSame(12, $block['window_months']);
        self::assertSame(2, $block['clusters']);
        self::assertSame(0, $block['omitted']);
        self::assertSame($history->rows, $block['rows'], 'the reporter never mutates or re-runs the analyzer answer');
        self::assertSame(
            ['cluster', 'verdict', 'reason', 'members', 'co_change'],
            array_keys($block['rows'][0]),
        );
        self::assertSame(
            ['file', 'last_sha', 'last_date', 'lone'],
            array_keys($block['rows'][0]['members'][0]),
        );
        self::assertNull($block['rows'][0]['members'][1]['lone'], 'a twin with no lone commit answers null, never an empty object');
        self::assertSame('no_repository', $block['rows'][1]['reason'], 'unmeasurable rows carry their fail-open reason');
    }
}
