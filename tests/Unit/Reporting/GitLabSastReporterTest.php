<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Reporting;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Parsing\AstParser;
use Phpdup\Pipeline\Pipeline;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\Stage;
use Phpdup\Pipeline\StageInterface;
use Phpdup\Pipeline\Stages\ReportStage;
use Phpdup\Reporting\GitLabSastReporter;
use Phpdup\Reporting\Report;
use Phpdup\Reporting\ScanWindow;
use Phpdup\Util\LineRange;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;

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
        // output-S56b: hand-built synthetic clusters replace the sql
        // fixture pipeline; still no pipeline metadata, so no window.
        $report   = new Report(2, 5, 0, $this->syntheticClusters(), Config::defaults([]));

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
     *
     * output-S56b: the sql-fixture scan legs are retired — Pipeline stamps
     * the start itself (Pipeline::iter, src/Pipeline/Pipeline.php:57) and
     * ReportStage carries it, so the state is hand-populated and a 5ms
     * pacing stage stands in for the multi-stage work that used to create
     * the measured duration. The pinned contract is unchanged: stamped
     * window, positive duration, RFC3339 pair with start != end in the
     * written artifact. What the report now carries is zero clusters (the
     * per-member payload is pinned by the synthetic-vector arm below).
     */
    public function testPipelineRunCarriesRealWindowIntoSastReport(): void
    {
        $out = sys_get_temp_dir() . '/phpdup-s59-sast-' . uniqid('', true) . '.json';
        try {
            $state = new PipelineState(Config::defaults([]));
            // ReportStage short-circuits on an empty scan (output-041 law),
            // so the state must carry files — the paths are count-only for
            // this arm; nothing reads them.
            $state->files = ['/phpdup-syn/a.php', '/phpdup-syn/b.php'];
            (new Pipeline([
                new class implements StageInterface {
                    public function name(): Stage
                    {
                        return Stage::Scanning;
                    }

                    public function run(PipelineState $state, OutputInterface $output): void
                    {
                        usleep(5000); // deterministic measured window, no fixture scan
                    }
                },
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
                'a paced pipeline run is never a zero-duration scan');
        } finally {
            @unlink($out);
        }
    }

    private function reportWithWindow(?ScanWindow $window): Report
    {
        return new Report(0, 0, 0, [], Config::defaults([]), scanWindow: $window);
    }

    /**
     * output-S56b (was testFixtureReportEmitsVulnerabilityPerMember): the
     * re-derived member count and the membership-only vectors of the
     * fixture era are replaced by exact per-entry pins over the synthetic
     * clusters — five member slots, severity tier per impact
     * (120 > 100 High; 30 >= 20 Low — Severity::forImpact), confidence
     * tier per exact/score (exact 'High'; 0.7 >= 0.65 Medium —
     * Severity::forScore), and the id/location/range projection.
     */
    public function testSyntheticReportEmitsVulnerabilityPerMember(): void
    {
        $payload = (new GitLabSastReporter())->build(
            new Report(2, 5, 0, $this->syntheticClusters(), Config::defaults([])),
        );

        $vulns = $payload['vulnerabilities'];
        $this->assertCount(5, $vulns);
        $this->assertSame(['High', 'High', 'Low', 'Low', 'Low'], array_column($vulns, 'severity'));
        $this->assertSame(['High', 'High', 'Medium', 'Medium', 'Medium'], array_column($vulns, 'confidence'));
        $this->assertSame('F0000000000000071::M71a', $vulns[0]['id']);
        $this->assertSame('/phpdup-syn/alpha.php', $vulns[0]['location']['file']);
        $this->assertSame([11, 21, 31, 41, 51], array_column(array_column($vulns, 'location'), 'start_line'));
        $this->assertSame([15, 25, 35, 45, 55], array_column(array_column($vulns, 'location'), 'end_line'));
    }

    /**
     * Hand-built carrier for the retired sql-fixture pipeline report.
     * Every vector pinned in the tests above derives from these exact
     * numbers: F…071 exact, impact 120, 2 members (ranges 11-15, 21-25);
     * F…072 near at similarity 0.72 / confidence 0.7, impact 30, 3 members
     * (ranges 31-35, 41-45, 51-55).
     *
     * @return list<Cluster>
     */
    private function syntheticClusters(): array
    {
        $stmts  = (new AstParser())->parseCode('<?php $x;');
        $member = static function (string $id, string $file, int $start) use ($stmts): Block {
            $b = new Block(
                file: $file,
                range: new LineRange($start, $start + 4),
                kind: 'function',
                namespace: null, class: null, name: $id,
                ast: $stmts[0],
            );
            $b->id = $id;
            return $b;
        };

        $high = new Cluster('F0000000000000071', [
            $member('M71a', '/phpdup-syn/alpha.php', 11),
            $member('M71b', '/phpdup-syn/beta.php', 21),
        ], 1.0, true);
        $high->impact = 120;

        $near = new Cluster('F0000000000000072', [
            $member('M72a', '/phpdup-syn/gamma.php', 31),
            $member('M72b', '/phpdup-syn/delta.php', 41),
            $member('M72c', '/phpdup-syn/epsilon.php', 51),
        ], 0.72, false);
        $near->impact      = 30;
        $near->confidence  = 0.7;

        return [$high, $near];
    }
}
