<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Reporting;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\Stages\ClusterStage;
use Phpdup\Pipeline\Stages\PreprocessStage;
use Phpdup\Pipeline\Stages\RefactorStage;
use Phpdup\Pipeline\Stages\ScanningStage;
use Phpdup\Reporting\Ranker;
use Phpdup\Reporting\Report;
use Phpdup\Reporting\SarifReporter;
use Phpdup\Util\LineRange;
use Symfony\Component\Console\Output\NullOutput;

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
     * once out-of-band (HTTP 200, application/json, size 112,768 B — see
     * reviews/exec/once-S52.impl.md). No network in this test.
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

    public function testFixtureReportEmitsResultPerMember(): void
    {
        $report  = $this->buildReport();
        $payload = (new SarifReporter())->build($report);

        $this->assertNotEmpty($payload['runs'][0]['results']);

        $expectedResults = 0;
        foreach ($report->clusters as $c) {
            $expectedResults += count($c->members);
        }
        $this->assertCount($expectedResults, $payload['runs'][0]['results']);

        $first = $payload['runs'][0]['results'][0];
        $this->assertSame('phpdup/duplicate-logic', $first['ruleId']);
        $this->assertArrayHasKey('partialFingerprints', $first);
        $this->assertArrayHasKey('clusterId', $first['partialFingerprints']);
        $this->assertArrayHasKey('startLine', $first['locations'][0]['physicalLocation']['region']);
    }

    public function testWriteToProducesParseableJson(): void
    {
        $tmp = sys_get_temp_dir() . '/phpdup-sarif-' . uniqid() . '.json';
        try {
            (new SarifReporter())->writeTo($this->buildReport(), $tmp);
            $this->assertFileExists($tmp);
            $decoded = json_decode((string)file_get_contents($tmp), true);
            $this->assertIsArray($decoded);
            $this->assertSame('2.1.0', $decoded['version']);
        } finally {
            @unlink($tmp);
        }
    }

    public function testOptionalBlockClusterSurfacesOptionalSegmentMetadata(): void
    {
        $report  = $this->buildOptionalReport();
        $payload = (new SarifReporter())->build($report);

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
        $this->assertGreaterThanOrEqual(1, $props['optionalSegmentCount']);
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

    private function buildReport(): Report
    {
        $config = new Config(
            paths: [__DIR__ . '/../../Fixtures/sql'],
            exclude: Config::defaults([])->exclude,
            lazyAst: false,
        );
        return $this->runPipeline($config);
    }

    private function buildOptionalReport(): Report
    {
        $config = new Config(
            paths: [__DIR__ . '/../../Fixtures/optional'],
            exclude: Config::defaults([])->exclude,
            minBlockSize: 4,
            maxDocumentFrequency: 0.5,
            minClusterImpact: 1,
            lazyAst: false,
        );
        return $this->runPipeline($config, exactOnly: false);
    }

    private function runPipeline(Config $config, bool $exactOnly = true): Report
    {
        $state = new PipelineState($config);
        $out   = new NullOutput();
        (new ScanningStage())->run($state, $out);
        (new PreprocessStage(useCache: false))->run($state, $out);
        (new ClusterStage(exactOnly: $exactOnly))->run($state, $out);
        (new RefactorStage(useCache: false))->run($state, $out);

        $clusters = (new Ranker($config->minClusterImpact))->rank($state->clusters);

        return new Report(
            files: count($state->files),
            blocks: count($state->blocks),
            parseErrors: $state->parseErrors,
            clusters: $clusters,
            config: $config,
        );
    }
}
