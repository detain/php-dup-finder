<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Reporting;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\Stages\ClusterStage;
use Phpdup\Pipeline\Stages\PreprocessStage;
use Phpdup\Pipeline\Stages\RefactorStage;
use Phpdup\Pipeline\Stages\ScanningStage;
use Phpdup\Reporting\Ranker;
use Phpdup\Reporting\Report;
use Phpdup\Reporting\SarifReporter;
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
