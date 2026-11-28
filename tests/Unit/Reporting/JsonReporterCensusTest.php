<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Reporting;

use PHPUnit\Framework\TestCase;
use Phpdup\Census\SubtreeCensus;
use Phpdup\Cli\Config;
use Phpdup\Reporting\JsonReporter;
use Phpdup\Reporting\JsonSchemaSpec;
use Phpdup\Reporting\Report;

/**
 * Item 3.1 ingestion (MINE-D): the report surface for the idiom census.
 *
 * The census block is a CONDITIONAL trailing summary key — present only
 * when the producer carried a census (armed run), absent otherwise. That
 * posture is exactly what keeps the frozen goldens byte-identical (no
 * default payload grows a key, no SCHEMA_VERSION event), mirroring the
 * 2.3 `class_shape` precedent one level down. JsonSchemaSpec spells the
 * armed key set via `summaryShape(true)` and the block itself via
 * `censusBlockShape()`; the belts pin the default spelling for real
 * bytes, and the assertions below close the loop on the armed one:
 *
 *  - unarmed build: no `idiom_census` key at all (never null, never {});
 *  - armed build: block trails the 19-key default set, keys and order
 *    matching summaryShape(true);
 *  - block content: corpus-complete counters (hashes/sites) + maximal
 *    rows in the census's deterministic order, per-row key order pinned,
 *    one-site rows carrying no locations per the substrate's two-pass
 *    memory policy;
 *  - the top-50 presentation cap truncates rows, never counters.
 */
final class JsonReporterCensusTest extends TestCase
{
    private static function censusWith(array $siteRows, array $edges = [], ?int $cap = null): SubtreeCensus
    {
        $census = new SubtreeCensus($cap ?? SubtreeCensus::DEFAULT_SAMPLE_CAP);
        $byFile = [];
        foreach ($siteRows as [$exact, $abstract, $nodes, $root, $file, $lines, $count]) {
            $byFile[$file][] = [$exact, $abstract, $nodes, $root, $count, $lines];
        }
        foreach ($byFile as $file => $sites) {
            $census->recordFile($file, ['sites' => $sites, 'edges' => $edges]);
        }
        return $census;
    }

    private static function report(?SubtreeCensus $census): Report
    {
        return new Report(
            files: 2, blocks: 9, parseErrors: 0, clusters: [],
            config: Config::defaults(['/nowhere']),
            idiomCensus: $census,
        );
    }

    public function testUnarmedPayloadCarriesNoCensusKey(): void
    {
        $summary = (new JsonReporter())->build(self::report(null))['summary'];
        $this->assertArrayNotHasKey('idiom_census', $summary);
        // The belts' claim, restated here from the other side: the
        // unarmed emitted key set equals the default shape exactly.
        $this->assertSame(array_keys(JsonSchemaSpec::summaryShape(false)), array_keys($summary));
        // And the conditional arm is not smuggled into the default spec:
        $this->assertArrayNotHasKey('idiom_census', JsonSchemaSpec::summaryShape());
    }

    public function testArmedSummaryTrailsTheConditionalBlockInSpecOrder(): void
    {
        $census = self::censusWith([
            ['a' . str_repeat('1', 39), 'b' . str_repeat('1', 39), 7, 'Expression', '/p/x.php', [4], 1],
            ['a' . str_repeat('1', 39), 'b' . str_repeat('1', 39), 7, 'Expression', '/p/y.php', [9], 1],
        ]);
        $payload = (new JsonReporter())->build(self::report($census));
        $summary = $payload['summary'];
        $this->assertSame(
            array_keys(JsonSchemaSpec::summaryShape(true)),
            array_keys($summary),
            'armed summary = default key set + trailing idiom_census',
        );
        $this->assertSame(array_merge(array_keys(JsonSchemaSpec::summaryShape(false)), ['idiom_census']), array_keys($summary));
        $this->assertSame(array_keys(JsonSchemaSpec::censusBlockShape()), array_keys($summary['idiom_census']));
    }

    public function testBlockContentCountersRowsAndMemoryPolicy(): void
    {
        $shared = ['e' . str_repeat('9', 39), 'f' . str_repeat('9', 39), 8, 'If_'];
        $loner  = ['e' . str_repeat('7', 39), 'f' . str_repeat('7', 39), 6, 'Assign'];
        $census = self::censusWith([
            [...$shared, '/p/x.php', [5], 1],
            [...$shared, '/p/y.php', [12], 1],
            [...$loner, '/p/x.php', [6], 1],
        ]);
        $block = (new JsonReporter())->build(self::report($census))['summary']['idiom_census'];

        $this->assertSame(2, $block['hashes']);
        $this->assertSame(3, $block['sites']);
        $this->assertSame(
            ['hash', 'abstract', 'nodes', 'root', 'sites', 'files', 'locations'],
            array_keys($block['rows'][0]),
        );
        // Deterministic census order: the two-site cross-file idiom ranks
        // first and carries both locations; the single-site loner carries
        // NONE (two-pass memory policy, visible to consumers).
        $this->assertSame($shared[0], $block['rows'][0]['hash']);
        $this->assertSame(2, $block['rows'][0]['sites']);
        $this->assertSame(2, $block['rows'][0]['files']);
        $this->assertSame(['/p/x.php:5', '/p/y.php:12'], $block['rows'][0]['locations']);
        $this->assertSame($loner[0], $block['rows'][1]['hash']);
        $this->assertSame([], $block['rows'][1]['locations']);
    }

    public function testTopFiftyCapTruncatesRowsNeverCounters(): void
    {
        $rows = [];
        for ($i = 0; $i < 51; $i++) {
            $exact = sprintf('%02d', $i) . str_repeat('a', 38);
            $rows[] = [$exact, $exact, 6, 'Expression', '/p/x.php', [3 + $i], 1];
        }
        $block = (new JsonReporter())->build(self::report(self::censusWith($rows)))['summary']['idiom_census'];
        $this->assertCount(50, $block['rows']);
        $this->assertSame(51, $block['hashes'], 'the counter is corpus-complete; only presentation caps');
        $this->assertSame(51, $block['sites']);
    }

    public function testSuppressedNestedFamiliesStayOutOfTheSurface(): void
    {
        // The loner is nested INSIDE the shared family via the miner's
        // edge census: §5c suppression belongs to maximalRows(), and the
        // report surface reads maximalRows() — a suppressed child hash
        // still counts in the corpus totals but never stands as a row.
        $parent = ['p' . str_repeat('1', 39), 'q' . str_repeat('1', 39), 12, 'If_'];
        $child  = ['c' . str_repeat('1', 39), 'd' . str_repeat('1', 39), 6, 'Assign'];
        $census = self::censusWith(
            [
                [...$parent, '/p/x.php', [5], 1],
                [...$parent, '/p/y.php', [8], 1],
                [...$child, '/p/x.php', [6], 1],
                [...$child, '/p/y.php', [9], 1],
            ],
            edges: [[$child[0], $parent[0], 2]],
        );
        $block = (new JsonReporter())->build(self::report($census))['summary']['idiom_census'];
        $hashes = array_column($block['rows'], 'hash');
        $this->assertSame([$parent[0]], $hashes, 'only the maximal family member is surfaced');
        $this->assertSame(2, $block['hashes']);
        $this->assertSame(4, $block['sites']);
    }
}
