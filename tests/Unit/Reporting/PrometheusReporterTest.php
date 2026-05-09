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
use Phpdup\Reporting\PrometheusReporter;
use Phpdup\Reporting\Ranker;
use Phpdup\Reporting\Report;
use Symfony\Component\Console\Output\NullOutput;

final class PrometheusReporterTest extends TestCase
{
    public function testEmptyReportEmitsZeroGauges(): void
    {
        $text = (new PrometheusReporter())->build(
            new Report(0, 0, 0, [], Config::defaults([])),
        );
        $this->assertStringContainsString('# TYPE phpdup_clusters_total gauge', $text);
        $this->assertStringContainsString("phpdup_clusters_total 0", $text);
        $this->assertStringContainsString("phpdup_files_scanned 0", $text);
    }

    public function testFixtureReportEmitsNonZeroClusterCount(): void
    {
        $report = $this->buildReport();
        $text = (new PrometheusReporter())->build($report);

        $this->assertStringContainsString('# HELP phpdup_clusters_total', $text);
        $this->assertMatchesRegularExpression('/^phpdup_clusters_total \d+$/m', $text);
        $this->assertMatchesRegularExpression('/^phpdup_total_impact \d+$/m', $text);

        // Per-cluster gauge lines, with a tag/kind label
        if (count($report->clusters) > 0) {
            $this->assertMatchesRegularExpression('/^phpdup_cluster_impact\{id="[^"]+",kind="[^"]*"\} \d+$/m', $text);
        }
    }

    public function testTextEndsWithNewline(): void
    {
        $text = (new PrometheusReporter())->build(
            new Report(0, 0, 0, [], Config::defaults([])),
        );
        $this->assertStringEndsWith("\n", $text);
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
