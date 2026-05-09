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
use Phpdup\Reporting\GitLabSastReporter;
use Phpdup\Reporting\Ranker;
use Phpdup\Reporting\Report;
use Symfony\Component\Console\Output\NullOutput;

final class GitLabSastReporterTest extends TestCase
{
    public function testEmptyReportProducesValidSkeleton(): void
    {
        $payload = (new GitLabSastReporter())->build(
            new Report(0, 0, 0, [], Config::defaults([])),
        );
        $this->assertSame(GitLabSastReporter::SCHEMA_VERSION, $payload['version']);
        $this->assertSame('phpdup', $payload['scan']['scanner']['id']);
        $this->assertSame('sast', $payload['scan']['type']);
        $this->assertSame([], $payload['vulnerabilities']);
    }

    public function testFixtureReportEmitsVulnerabilityPerMember(): void
    {
        $report  = $this->buildReport();
        $payload = (new GitLabSastReporter())->build($report);

        $expected = 0;
        foreach ($report->clusters as $c) {
            $expected += count($c->members);
        }
        $this->assertCount($expected, $payload['vulnerabilities']);

        foreach ($payload['vulnerabilities'] as $v) {
            $this->assertContains($v['severity'], ['High', 'Medium', 'Low', 'Info']);
            $this->assertContains($v['confidence'], ['High', 'Medium', 'Low']);
            $this->assertArrayHasKey('location', $v);
            $this->assertArrayHasKey('start_line', $v['location']);
        }
    }

    private function buildReport(): Report
    {
        $config = new Config(
            paths: [__DIR__ . '/../../Fixtures/sql'],
            exclude: Config::defaults([])->exclude,
            lazyAst: false,
        );
        $state = new PipelineState($config);
        $out   = new NullOutput();
        (new ScanningStage())->run($state, $out);
        (new PreprocessStage(useCache: false))->run($state, $out);
        (new ClusterStage(exactOnly: true))->run($state, $out);
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
