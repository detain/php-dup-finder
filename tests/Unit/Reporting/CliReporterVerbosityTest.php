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
use Phpdup\Reporting\CliReporter;
use Phpdup\Reporting\Ranker;
use Phpdup\Reporting\Report;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;

final class CliReporterVerbosityTest extends TestCase
{
    public function testRejectsUnknownVerbosity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CliReporter('bogus');
    }

    public function testSummaryOnlySkipsClusterDetails(): void
    {
        $report = $this->buildReport();
        $out    = $this->buffered();
        (new CliReporter(CliReporter::VERBOSITY_SUMMARY_ONLY))->render($report, $out);
        $rendered = $out->fetch();

        $this->assertStringContainsString('phpdup', $rendered);
        $this->assertStringContainsString('summary  ', $rendered);
        // No member tables or "Suggested abstraction" sections in summary-only.
        $this->assertStringNotContainsString('Suggested abstraction', $rendered);
        $this->assertStringNotContainsString('LOCATION', $rendered);
    }

    public function testClustersModeShowsTableButNoSignaturePanel(): void
    {
        $report = $this->buildReport();
        $out    = $this->buffered();
        (new CliReporter(CliReporter::VERBOSITY_CLUSTERS))->render($report, $out);
        $rendered = $out->fetch();

        $this->assertStringContainsString('CLUSTER', $rendered);
        $this->assertStringContainsString('IMPACT', $rendered);
        $this->assertStringNotContainsString('Suggested abstraction', $rendered);
        $this->assertStringContainsString('summary  ', $rendered);
    }

    public function testFullModeIsTheDefault(): void
    {
        $report = $this->buildReport();
        $out    = $this->buffered();
        (new CliReporter())->render($report, $out);
        $rendered = $out->fetch();
        $this->assertStringContainsString('summary  ', $rendered);
        // Full-mode-only sections (only present when there are clusters)
        if (count($report->clusters) > 0) {
            $this->assertStringContainsString('Cluster #', $rendered);
        }
    }

    public function testEmptyReportRendersClean(): void
    {
        $cfg = Config::defaults([__DIR__]);
        $report = new Report(0, 0, 0, [], $cfg);
        $out    = $this->buffered();
        (new CliReporter(CliReporter::VERBOSITY_SUMMARY_ONLY))->render($report, $out);
        $this->assertStringContainsString('clean', $out->fetch());
    }

    private function buffered(): BufferedOutput
    {
        $out = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, decorated: false);
        return $out;
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
