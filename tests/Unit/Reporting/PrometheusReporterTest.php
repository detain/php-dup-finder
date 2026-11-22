<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Reporting;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Parsing\AstParser;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\Stages\ClusterStage;
use Phpdup\Pipeline\Stages\PreprocessStage;
use Phpdup\Pipeline\Stages\RefactorStage;
use Phpdup\Pipeline\Stages\ScanningStage;
use Phpdup\Reporting\PrometheusReporter;
use Phpdup\Reporting\Ranker;
use Phpdup\Reporting\Report;
use Phpdup\Util\ControlText;
use Phpdup\Util\LineRange;
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

    // ── output-047: label escaping (the Go-client escape set + sweeps) ─────

    public function testCarriageReturnInLabelIsEscapedNotRaw(): void
    {
        $report = $this->syntheticReport("id\rforger");
        $text   = (new PrometheusReporter())->build($report);

        // Raw CR would break line framing on tolerant readers; the value
        // must arrive as the two-char \r escape on ONE exposition line.
        $this->assertStringNotContainsString("\r", $text);
        $this->assertStringContainsString('id="id\rforger"', $text);
        $this->assertMatchesRegularExpression(
            '/^phpdup_cluster_impact\{id="id\\\\rforger",kind="method"\} \d+$/',
            $this->sampleLine($text, 'id="id\rforger"'),
        );
    }

    public function testUnescapableBytesAreSubstitutedInLabels(): void
    {
        $report = $this->syntheticReport("nul\x00and\x1besc");
        $text   = (new PrometheusReporter())->build($report);

        $this->assertStringNotContainsString("\x00", $text);
        $this->assertStringNotContainsString("\x1b", $text);
        $this->assertSame(2, substr_count($text, ControlText::SUBSTITUTE));
    }

    public function testLineFeedInLabelStaysEscapedNoStatementSplit(): void
    {
        $report = $this->syntheticReport("id\nphpdup_forged_metric 1");
        $text   = (new PrometheusReporter())->build($report);

        // The classic forgery: an LF label value minting a second metric
        // line. It must not appear as a standalone exposition line.
        $this->assertMatchesRegularExpression('/^phpdup_\w+ /m', $text);
        $this->assertStringNotContainsString("\nphpdup_forged_metric 1", $text);
        $this->assertStringNotContainsString("\r", $text);
        $this->assertStringContainsString('id="id\nphpdup_forged_metric 1"', $text);
    }

    /** @return string the single exposition line carrying $needle */
    private function sampleLine(string $text, string $needle): string
    {
        foreach (explode("\n", $text) as $line) {
            if (str_contains($line, $needle)) {
                return $line;
            }
        }
        $this->fail("no line carries {$needle}");
    }

    private function syntheticReport(string $id): Report
    {
        $stmts   = (new AstParser())->parseCode('<?php $x;');
        $members = [];
        foreach (['a.php', 'b.php'] as $f) {
            $members[] = new Block(
                file: $f,
                range: new LineRange(1, 1),
                kind: 'method',
                namespace: null, class: null, name: null,
                ast: $stmts[0],
            );
        }
        return new Report(
            files: 2, blocks: 2, parseErrors: 0,
            clusters: [new Cluster($id, $members, 1.0, false)],
            config: Config::defaults([]),
        );
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
