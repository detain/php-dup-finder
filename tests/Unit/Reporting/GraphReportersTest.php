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
use Phpdup\Reporting\GraphvizReporter;
use Phpdup\Reporting\PlantumlReporter;
use Phpdup\Reporting\Ranker;
use Phpdup\Reporting\Report;
use Symfony\Component\Console\Output\NullOutput;

final class GraphReportersTest extends TestCase
{
    public function testGraphvizEmitsValidDigraph(): void
    {
        $report = $this->buildReport();
        $dot = (new GraphvizReporter())->build($report);

        $this->assertStringStartsWith('digraph phpdup {', $dot);
        $this->assertStringEndsWith("}\n", $dot);
        $this->assertMatchesRegularExpression('/^\s*n_[a-f0-9]{12} -> n_[a-f0-9]{12};/m', $dot);
    }

    public function testGraphvizEmptyReportProducesValidEmptyGraph(): void
    {
        $dot = (new GraphvizReporter())->build(
            new Report(0, 0, 0, [], Config::defaults([])),
        );
        $this->assertStringStartsWith('digraph phpdup {', $dot);
        $this->assertStringEndsWith("}\n", $dot);
    }

    public function testPlantumlEmitsValidUmlDocument(): void
    {
        $report = $this->buildReport();
        $puml = (new PlantumlReporter())->build($report);
        $this->assertStringStartsWith('@startuml phpdup', $puml);
        $this->assertStringEndsWith("@enduml\n", $puml);
        if (count($report->clusters) > 0) {
            $this->assertStringContainsString('package "', $puml);
            $this->assertStringContainsString('class ', $puml);
        }
    }

    public function testPlantumlEmptyReport(): void
    {
        $puml = (new PlantumlReporter())->build(
            new Report(0, 0, 0, [], Config::defaults([])),
        );
        $this->assertStringContainsString('@startuml', $puml);
        $this->assertStringContainsString('@enduml', $puml);
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
