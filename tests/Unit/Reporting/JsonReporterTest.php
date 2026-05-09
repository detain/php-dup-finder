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
use Phpdup\Reporting\JsonReporter;
use Phpdup\Reporting\Ranker;
use Phpdup\Reporting\Report;
use Symfony\Component\Console\Output\NullOutput;

final class JsonReporterTest extends TestCase
{
    public function testEmptyReportProducesEmptyClustersArray(): void
    {
        $payload = (new JsonReporter())->build(new Report(0, 0, 0, [], Config::defaults([])));
        $this->assertSame(0, $payload['summary']['clusters']);
        $this->assertSame([], $payload['clusters']);
        $this->assertArrayHasKey('phpdup_version', $payload);
        $this->assertSame('aggressive', $payload['config']['normalization_mode']);
    }

    public function testFixtureReportPopulatesClusters(): void
    {
        $report  = $this->buildReport(__DIR__ . '/../../Fixtures/sql', exactOnly: true);
        $payload = (new JsonReporter())->build($report);

        $this->assertGreaterThan(0, $payload['summary']['clusters']);
        $this->assertNotEmpty($payload['clusters']);

        foreach ($payload['clusters'] as $c) {
            $this->assertArrayHasKey('id', $c);
            $this->assertArrayHasKey('exact', $c);
            $this->assertArrayHasKey('similarity', $c);
            $this->assertArrayHasKey('signature', $c);
            $this->assertArrayHasKey('members', $c);
            $this->assertArrayHasKey('holes', $c);
            $this->assertArrayHasKey('kind', $c, 'cluster.kind populated from member[0].kind');
        }
    }

    public function testWriteToCreatesParseableJsonFile(): void
    {
        $tmp = sys_get_temp_dir() . '/phpdup-json-' . uniqid() . '.json';
        try {
            (new JsonReporter())->writeTo(
                $this->buildReport(__DIR__ . '/../../Fixtures/sql', exactOnly: true),
                $tmp,
            );
            $this->assertFileExists($tmp);
            $decoded = json_decode((string)file_get_contents($tmp), true);
            $this->assertIsArray($decoded);
            $this->assertSame('0.1.0', $decoded['phpdup_version']);
        } finally {
            @unlink($tmp);
        }
    }

    public function testOptionalBlockHolesIncludePresentInMembers(): void
    {
        $report  = $this->buildReport(
            __DIR__ . '/../../Fixtures/optional',
            exactOnly: false,
            minBlockSize: 4,
            maxDf: 0.5,
        );
        $payload = (new JsonReporter())->build($report);

        // Locate the optional-segments cluster.
        $optional = null;
        foreach ($payload['clusters'] as $c) {
            if (in_array('optional-segments', $c['pattern_tags'], true)) {
                $optional = $c;
                break;
            }
        }
        $this->assertNotNull($optional, 'fixture should produce an optional-segments cluster');

        $optionalHoles = array_filter($optional['holes'], static fn($h) => $h['kind'] === 'optional_block');
        $this->assertNotEmpty($optionalHoles, 'cluster has at least one optional_block hole');

        foreach ($optionalHoles as $h) {
            $this->assertArrayHasKey('present_in_members', $h, 'optional_block holes carry present_in_members[]');
            $this->assertIsArray($h['present_in_members']);
            $this->assertNotEmpty($h['present_in_members'], 'at least one member must have the segment');
            // Indices should be valid integer offsets into cluster.members.
            foreach ($h['present_in_members'] as $idx) {
                $this->assertIsInt($idx);
                $this->assertGreaterThanOrEqual(0, $idx);
                $this->assertLessThan(count($optional['members']), $idx);
            }
        }
    }

    public function testNonOptionalHolesOmitPresentInMembers(): void
    {
        $report  = $this->buildReport(__DIR__ . '/../../Fixtures/notify', exactOnly: false);
        $payload = (new JsonReporter())->build($report);

        $sawAnyHole = false;
        foreach ($payload['clusters'] as $c) {
            foreach ($c['holes'] as $h) {
                if ($h['kind'] !== 'optional_block') {
                    $this->assertArrayNotHasKey('present_in_members', $h);
                    $sawAnyHole = true;
                }
            }
        }
        $this->assertTrue($sawAnyHole, 'fixture should have produced at least one non-optional hole');
    }

    private function buildReport(
        string $path,
        bool $exactOnly = true,
        int $minBlockSize = 8,
        float $maxDf = 0.01,
    ): Report {
        $config = new Config(
            paths: [$path],
            exclude: Config::defaults([])->exclude,
            minBlockSize: $minBlockSize,
            maxDocumentFrequency: $maxDf,
            minClusterImpact: 1,
            lazyAst: false,
        );
        $state = new PipelineState($config);
        $out   = new NullOutput();
        (new ScanningStage())->run($state, $out);
        (new PreprocessStage(useCache: false))->run($state, $out);
        (new ClusterStage(exactOnly: $exactOnly))->run($state, $out);
        (new RefactorStage(useCache: false))->run($state, $out);

        return new Report(
            files: count($state->files),
            blocks: count($state->blocks),
            parseErrors: $state->parseErrors,
            clusters: (new Ranker($config->minClusterImpact))->rank($state->clusters),
            config: $config,
        );
    }
}
