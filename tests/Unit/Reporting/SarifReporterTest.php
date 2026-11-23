<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Reporting;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Refactor\Hole;
use Phpdup\Reporting\Report;
use Phpdup\Reporting\SarifReporter;
use Phpdup\Util\LineRange;

final class SarifReporterTest extends TestCase
{
    public function testEmptyReportProducesValidSarifSkeleton(): void
    {
        $report = new Report(0, 0, 0, [], Config::defaults([]));
        $payload = (new SarifReporter())->build($report);

        $this->assertSame(SarifReporter::VERSION, $payload['version']);
        $this->assertSame(SarifReporter::SCHEMA, $payload['$schema']);
        $this->assertCount(1, $payload['runs']);
        $this->assertSame('phpdup', $payload['runs'][0]['tool']['driver']['name']);
        $this->assertSame([], $payload['runs'][0]['results']);
    }

    /**
     * php-dup-finder-once-058: the historical raw.githubusercontent.com
     * oasis-tcs mirror URL is dead on both master and main branch shapes
     * (live-probed 404 at implementation time), so conforming consumers
     * that fetch $schema break. The constant must carry the versioned
     * docs.oasis-open.org errata01 path — the location the schema document
     * declares as its own `id`. Shape pinned here; fetchability verified
     * out-of-band at implementation time (see commit a1c58ae message).
     * No network in this test.
     */
    public function testSchemaUrlIsTheLiveOasisErrataPath(): void
    {
        $expected = 'https://docs.oasis-open.org/sarif/sarif/v2.1.0/errata01/os/schemas/sarif-schema-2.1.0.json';
        $this->assertSame($expected, SarifReporter::SCHEMA);

        // Shape pins: the exact versioned errata path on the OASIS site —
        // any drift back to a moving branch mirror goes red independent of
        // the literal above.
        $this->assertMatchesRegularExpression(
            '~^https://docs\.oasis-open\.org/sarif/sarif/v2\.1\.0/errata01/os/schemas/sarif-schema-2\.1\.0\.json$~',
            SarifReporter::SCHEMA,
        );
        $this->assertStringNotContainsString('raw.githubusercontent.com', SarifReporter::SCHEMA);

        $report  = new Report(0, 0, 0, [], Config::defaults([]));
        $payload = (new SarifReporter())->build($report);
        $this->assertSame($expected, $payload['$schema'], 'emitted $schema is the constant, not a re-inlined literal');
    }

    /**
     * output-S56b (was testFixtureReportEmitsResultPerMember): the sql
     * fixture pipeline becomes two synthetic clusters — F…081 exact with
     * three members (ranges start 11/21/31), F…082 near with two (41/51).
     * Five member slots total: the count is pinned hard instead of
     * re-derived from the report, and the clusterId/startLine projections
     * are now exact values rather than key-existence probes.
     */
    public function testSyntheticReportEmitsResultPerMember(): void
    {
        $payload = (new SarifReporter())->build(
            new Report(5, 5, 0, $this->syntheticClusters(), Config::defaults([])),
        );

        $results = $payload['runs'][0]['results'];
        $this->assertCount(5, $results);

        $first = $results[0];
        $this->assertSame('phpdup/duplicate-logic', $first['ruleId']);
        $this->assertArrayHasKey('partialFingerprints', $first);
        $this->assertSame('F0000000000000081', $first['partialFingerprints']['clusterId']);
        $this->assertArrayHasKey('startLine', $first['locations'][0]['physicalLocation']['region']);
        $this->assertSame(11, $first['locations'][0]['physicalLocation']['region']['startLine']);
        $this->assertSame(
            ['warning', 'warning', 'warning', 'note', 'note'],
            array_map(static fn(array $r): string => $r['level'], $results),
            'exact cluster members keep warning, near-dup members keep note (per member, not per cluster)',
        );
    }

    public function testWriteToProducesParseableJson(): void
    {
        $tmp = sys_get_temp_dir() . '/phpdup-sarif-' . uniqid() . '.json';
        try {
            (new SarifReporter())->writeTo(
                new Report(5, 5, 0, $this->syntheticClusters(), Config::defaults([])),
                $tmp,
            );
            $this->assertFileExists($tmp);
            $decoded = json_decode((string)file_get_contents($tmp), true);
            $this->assertIsArray($decoded);
            $this->assertSame('2.1.0', $decoded['version']);
            // Strengthened (output-S56b): the round-trip carries all five
            // member results — parseable AND complete.
            $this->assertCount(5, $decoded['runs'][0]['results']);
        } finally {
            @unlink($tmp);
        }
    }

    public function testOptionalBlockClusterSurfacesOptionalSegmentMetadata(): void
    {
        // output-S56b: hand-built carrier (was an optional-fixture scan):
        // a tagged cluster with two optional_block holes and one literal
        // hole, so optionalSegmentCount is exact, not "at least one".
        $cluster = new Cluster('F0000000000000083', [
            $this->member('S83a', 11), $this->member('S83b', 21),
        ], 0.7, false);
        $cluster->patternTags = ['optional-segments'];
        $cluster->holes = [
            new Hole('__O0', 'optional_block', ["'s();'", Hole::MISSING], false),
            new Hole('__O1', 'optional_block', [Hole::MISSING, "'t();'"], false),
            new Hole('__L0', 'literal', ["'a';", "'b';"], false),
        ];
        $payload = (new SarifReporter())->build(
            new Report(2, 2, 0, [$cluster], Config::defaults([])),
        );

        $this->assertNotEmpty($payload['runs'][0]['results']);

        // Find the result coming from the optional-segments cluster.
        $found = null;
        foreach ($payload['runs'][0]['results'] as $r) {
            $tags = $r['properties']['patternTags'] ?? [];
            if (in_array('optional-segments', $tags, true)) {
                $found = $r;
                break;
            }
        }
        $this->assertNotNull($found, 'expected at least one result tagged optional-segments');

        $props = $found['properties'];
        $this->assertSame(2, $props['optionalSegmentCount'], 'exactly the two optional_block holes count');
        $this->assertTrue($props['hasOptionalSegments']);
    }

    /**
     * phpver-fix drift class, SARIF surface: a raw whole float in
     * `properties.similarity` reaches json_encode, whose rendering of
     * float(1) is `1` on PHP 8.3 and `1.0` on 8.4+ — SARIF files would
     * byte-drift per minor version. The emit routes through Num::json,
     * so whole values (and values whole after rounding) are int bytes on
     * every version. Pinned synthetically (no pipeline run) so a revert
     * to the raw emit goes red on every host, including this 8.3 box
     * where the 0.99999 case alone distinguishes the two code paths.
     */
    public function testWholeFloatSimilarityRendersVersionInvariantIntBytes(): void
    {
        $member = static fn(string $name): Block => new Block(
            file: 'sarif-pin.php',
            range: new LineRange(1, 5),
            kind: 'function',
            namespace: null,
            class: null,
            name: $name,
            ast: new \PhpParser\Node\Stmt\Nop(),
        );

        $whole  = new Cluster('F0000000000000001', [$member('a')], 1.0, true);
        // Rounds whole at the contract precision — must collapse to int 1
        // through Num::json, while a raw emit would print 0.99999 verbatim.
        $near   = new Cluster('F0000000000000002', [$member('c')], 0.99999, false);

        $report  = new Report(1, 2, 0, [$whole, $near], Config::defaults([]));
        $payload = (new SarifReporter())->build($report);
        $bytes   = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $this->assertIsString($bytes);
        $this->assertSame(2, substr_count((string) $bytes, '"similarity": 1'),
            'both similarity renders are int-typed 1 — never 1.0, never 0.99999');
        $this->assertStringNotContainsString('"similarity": 1.0', (string) $bytes);
        $this->assertStringNotContainsString('0.99999', (string) $bytes);

        $decoded = json_decode((string) $bytes, true);
        $renders = array_map(
            static fn(array $r): int|float => $r['properties']['similarity'],
            $decoded['runs'][0]['results'],
        );
        foreach ($renders as $render) {
            $this->assertIsInt($render, 'decoded JSON type is int on every version (assertSame-strict pin)');
        }
    }

    /**
     * php-dup-finder-output-037: result levels route through Severity::sarifLevel
     * while the emitted bytes stay identical to the pre-centralization inline
     * `$cluster->exact ? 'warning' : 'note'` — the expected literals below are
     * that old expression, kept verbatim as a byte-freeze oracle independent
     * of the center.  Also pins the rule-metadata default ('warning'), whose
     * literal likewise moved behind Severity::sarifRuleDefaultLevel().
     */
    public function testResultLevelsRouteThroughSeverityWithByteIdenticalLabels(): void
    {
        $member = static fn(string $name): Block => new Block(
            file: 'sarif-level.php',
            range: new LineRange(1, 5),
            kind: 'function',
            namespace: null,
            class: null,
            name: $name,
            ast: new \PhpParser\Node\Stmt\Nop(),
        );

        $exact = new Cluster('F0000000000000003', [$member('a')], 1.0, true);
        $near  = new Cluster('F0000000000000004', [$member('b')], 0.8, false);

        $report  = new Report(1, 2, 0, [$exact, $near], Config::defaults([]));
        $payload = (new SarifReporter())->build($report);

        $levels = array_map(
            static fn(array $r): string => $r['level'],
            $payload['runs'][0]['results'],
        );
        $this->assertSame(['warning', 'note'], $levels,
            'exact clone keeps warning, near-dup keeps note — the historical inline mapping');

        $default = $payload['runs'][0]['tool']['driver']['rules'][0]['defaultConfiguration']['level'];
        $this->assertSame('warning', $default);
    }

    private function member(string $id, int $start): Block
    {
        $b = new Block(
            file: '/phpdup-syn/' . $id . '.php',
            range: new LineRange($start, $start + 4),
            kind: 'function',
            namespace: null, class: null, name: $id,
            ast: new \PhpParser\Node\Stmt\Nop(),
        );
        $b->id = $id;
        return $b;
    }

    /**
     * Synthetic carrier for the retired sql-fixture pipeline: F…081 exact
     * (three members, startLines 11/21/31) + F…082 near (two members,
     * 41/51). Every count and value pinned in the migrated arms derives
     * from these fixed numbers.
     *
     * @return list<Cluster>
     */
    private function syntheticClusters(): array
    {
        $exact = new Cluster('F0000000000000081', [
            $this->member('S81a', 11), $this->member('S81b', 21), $this->member('S81c', 31),
        ], 1.0, true);
        $near = new Cluster('F0000000000000082', [
            $this->member('S82a', 41), $this->member('S82b', 51),
        ], 0.8, false);
        return [$exact, $near];
    }
}
