<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Reporting;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Pipeline\Pipeline;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\Stages\ClusterStage;
use Phpdup\Pipeline\Stages\PreprocessStage;
use Phpdup\Pipeline\Stages\RefactorStage;
use Phpdup\Pipeline\Stages\ReportStage;
use Phpdup\Pipeline\Stages\ScanningStage;
use Phpdup\Reporting\GitLabSastReporter;
use Phpdup\Reporting\Ranker;
use Phpdup\Reporting\Report;
use Phpdup\Reporting\ScanWindow;
use Symfony\Component\Console\Output\NullOutput;

final class GitLabSastReporterTest extends TestCase
{
    /** Epoch 1779700000 == 2026-05-25T09:06:40Z (fixed test clock). */
    private const CLOCK = 1_779_700_000.0;

    /** output-S04 shape, widened by once-S59 for the honest fractional tail. */
    private const RFC3339Z = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?Z$/';

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

    /**
     * once-S59: build() never reads the clock. A synthetic Report without
     * pipeline metadata omits the timestamp fields honestly — so two runs
     * over the identical Report are byte-identical (the old
     * gmdate()-per-build path silently violated this across second
     * boundaries and fabricated a start==end "instant scan" lie besides).
     */
    public function testTwoBuildsOfIdenticalSyntheticReportAreByteIdentical(): void
    {
        $reporter = new GitLabSastReporter();
        $report   = $this->buildReport(); // real fixture pipeline, hand-built Report → no window

        $first  = (string) json_encode($reporter->build($report), JSON_PRETTY_PRINT);
        $second = (string) json_encode($reporter->build($report), JSON_PRETTY_PRINT);

        $this->assertNotSame('', $first, 'payload sanity — a false json_encode must not vacuate the equality');
        $this->assertSame($first, $second, 'same Report in, same bytes out (no wall clock in the build path)');
    }

    public function testTimestampsOmittedWhenReportCarriesNoPipelineMetadata(): void
    {
        $payload = (new GitLabSastReporter())->build(
            new Report(0, 0, 0, [], Config::defaults([])),
        );

        $this->assertArrayNotHasKey('start_time', $payload['scan'],
            'no measured window must mean no timestamp — never a fabricated clock read');
        $this->assertArrayNotHasKey('end_time', $payload['scan']);
    }

    /**
     * output-S04 carried forward: when a window IS present the timestamps
     * stay RFC3339 UTC with the explicit 'Z' designator (naive gmdate
     * output is rejected by strict date-time validators). Values derive
     * from the fixed test clock, proving determinism end-to-end.
     */
    public function testScanTimestampsCarryRfc3339UtcDesignator(): void
    {
        $report  = $this->reportWithWindow(new ScanWindow(self::CLOCK, self::CLOCK + 2.5));
        $payload = (new GitLabSastReporter())->build($report);

        $this->assertSame('2026-05-25T09:06:40Z', $payload['scan']['start_time']);
        $this->assertSame('2026-05-25T09:06:42.5Z', $payload['scan']['end_time']);
        $this->assertMatchesRegularExpression(self::RFC3339Z, (string) $payload['scan']['start_time']);
        $this->assertMatchesRegularExpression(self::RFC3339Z, (string) $payload['scan']['end_time']);
    }

    /**
     * once-S59 acceptance: start == end only when the duration is truly
     * zero. Sub-second durations must survive formatting (the old pair of
     * gmdate() calls both collapsed to one naive second either way).
     */
    public function testStartEqualsEndOnlyForZeroDurationWindow(): void
    {
        $zero = (new GitLabSastReporter())->build(
            $this->reportWithWindow(new ScanWindow(self::CLOCK, self::CLOCK)),
        );
        $this->assertSame('2026-05-25T09:06:40Z', $zero['scan']['start_time']);
        $this->assertSame($zero['scan']['start_time'], $zero['scan']['end_time'],
            'a truly zero-duration window is the one case start == end');

        $micro = (new GitLabSastReporter())->build(
            $this->reportWithWindow(new ScanWindow(self::CLOCK, self::CLOCK + 0.000_001)),
        );
        $this->assertSame('2026-05-25T09:06:40.000001Z', $micro['scan']['end_time']);
        $this->assertNotSame($micro['scan']['start_time'], $micro['scan']['end_time'],
            'even a 1µs scan must not print as a zero-length one');

        $half = (new GitLabSastReporter())->build(
            $this->reportWithWindow(new ScanWindow(self::CLOCK, self::CLOCK + 0.5)),
        );
        $this->assertSame('2026-05-25T09:06:40.5Z', $half['scan']['end_time'],
            'shortest fractional form: trailing zeros trimmed');
    }

    public function testRejectsWindowEndingBeforeItStarted(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ScanWindow(2.0, 1.0);
    }

    /**
     * once-S59: the production path carries the metadata. A Pipeline-driven
     * run stamps PipelineState::$runStartedAt, ReportStage turns it into a
     * real ScanWindow on the Report, and the written SAST file shows a
     * window whose end is strictly after its start (a real scan is never
     * presented as an instant).
     */
    public function testPipelineRunCarriesRealWindowIntoSastReport(): void
    {
        $out = sys_get_temp_dir() . '/phpdup-s59-sast-' . uniqid('', true) . '.json';
        try {
            $config = new Config(
                paths: [__DIR__ . '/../../Fixtures/sql'],
                exclude: Config::defaults([])->exclude,
                lazyAst: false,
            );
            $state = new PipelineState($config);
            (new Pipeline([
                new ScanningStage(),
                new PreprocessStage(useCache: false),
                new ClusterStage(exactOnly: true),
                new RefactorStage(useCache: false),
                new ReportStage(limit: 50, showStats: false, gitlabSastFile: $out),
            ]))->run($state, new NullOutput());

            $this->assertNotNull($state->report);
            $this->assertNotNull($state->report->scanWindow, 'a Pipeline-driven Report carries its measured window');
            $this->assertGreaterThan(0.0, $state->report->scanWindow->durationSeconds());

            /** @var array{scan: array<string, mixed>} $payload */
            $payload = json_decode((string) file_get_contents($out), true, 512, JSON_THROW_ON_ERROR);
            $this->assertMatchesRegularExpression(self::RFC3339Z, (string) $payload['scan']['start_time']);
            $this->assertMatchesRegularExpression(self::RFC3339Z, (string) $payload['scan']['end_time']);
            $this->assertNotSame($payload['scan']['start_time'], $payload['scan']['end_time'],
                'a real multi-stage run is never a zero-duration scan');
        } finally {
            @unlink($out);
        }
    }

    private function reportWithWindow(?ScanWindow $window): Report
    {
        return new Report(0, 0, 0, [], Config::defaults([]), scanWindow: $window);
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
