<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Pipeline;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Pipeline\CooperativeStageInterface;
use Phpdup\Pipeline\DebugLogger;
use Phpdup\Pipeline\Pipeline;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\Stage;
use Phpdup\Pipeline\StageInterface;
use Phpdup\Pipeline\Stages\ClusterStage;
use Phpdup\Pipeline\Stages\PreprocessStage;
use Phpdup\Pipeline\Stages\ReportStage;
use Phpdup\Pipeline\Stages\ScanningStage;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;

final class PipelineTest extends TestCase
{
    public function testRunsStagesInOrderAndAdvancesState(): void
    {
        $observed = [];
        $stages = [
            $this->recordingStage(Stage::Scanning, $observed),
            $this->recordingStage(Stage::Preprocessing, $observed),
            $this->recordingStage(Stage::Clustering, $observed),
        ];
        $state = new PipelineState(Config::defaults([__DIR__]));

        (new Pipeline($stages))->run($state, new NullOutput());

        $this->assertSame(
            ['scanning', 'preprocessing', 'clustering'],
            array_map(fn(Stage $s) => $s->value, $observed),
        );
        $this->assertSame(Stage::Clustering, $state->stage);
        $this->assertSame(1.0, $state->stageProgress);
    }

    public function testStopAfterHaltsAtBoundary(): void
    {
        $observed = [];
        $stages = [
            $this->recordingStage(Stage::Scanning, $observed),
            $this->recordingStage(Stage::Preprocessing, $observed),
            $this->recordingStage(Stage::Clustering, $observed),
        ];
        $state = new PipelineState(Config::defaults([__DIR__]));

        (new Pipeline($stages, stopAfter: Stage::Preprocessing))->run($state, new NullOutput());

        $this->assertSame(
            ['scanning', 'preprocessing'],
            array_map(fn(Stage $s) => $s->value, $observed),
            'Clustering must not run when stopAfter=preprocessing',
        );
        $this->assertSame(Stage::Preprocessing, $state->stage);
    }

    public function testStageProgressIsResetBetweenStages(): void
    {
        $progressSeenAtStart = [];
        $stages = [
            new class($progressSeenAtStart, Stage::Scanning) implements StageInterface {
                public function __construct(private array &$record, private Stage $name) {}
                public function name(): Stage { return $this->name; }
                public function run(PipelineState $state, OutputInterface $output): void
                {
                    $this->record[$this->name->value] = $state->stageProgress;
                    $state->stageProgress = 0.42; // simulate mid-stage progress
                }
            },
            new class($progressSeenAtStart, Stage::Preprocessing) implements StageInterface {
                public function __construct(private array &$record, private Stage $name) {}
                public function name(): Stage { return $this->name; }
                public function run(PipelineState $state, OutputInterface $output): void
                {
                    $this->record[$this->name->value] = $state->stageProgress;
                }
            },
        ];
        $state = new PipelineState(Config::defaults([__DIR__]));

        (new Pipeline($stages))->run($state, new NullOutput());

        $this->assertSame(0.0, $progressSeenAtStart['scanning']);
        $this->assertSame(0.0, $progressSeenAtStart['preprocessing']);
    }

    public function testIterYieldsTwiceForSynchronousStage(): void
    {
        $observed = [];
        $stages = [$this->recordingStage(Stage::Scanning, $observed)];
        $state  = new PipelineState(Config::defaults([__DIR__]));

        $iter = (new Pipeline($stages))->iter($state, new NullOutput());
        $yields = iterator_to_array($iter, false);

        // Pre-stage frame + post-stage frame = 2 yields per synchronous stage.
        $this->assertCount(2, $yields);
        $this->assertSame(Stage::Scanning, $yields[0]);
        $this->assertSame(Stage::Scanning, $yields[1]);
    }

    public function testIterDriveSynchronousStagesInOrder(): void
    {
        $observed = [];
        $stages = [
            $this->recordingStage(Stage::Scanning, $observed),
            $this->recordingStage(Stage::Preprocessing, $observed),
            $this->recordingStage(Stage::Clustering, $observed),
        ];
        $state = new PipelineState(Config::defaults([__DIR__]));

        $yields = iterator_to_array(
            (new Pipeline($stages))->iter($state, new NullOutput()),
            false,
        );

        $this->assertSame(
            [Stage::Scanning, Stage::Scanning, Stage::Preprocessing, Stage::Preprocessing, Stage::Clustering, Stage::Clustering],
            $yields,
        );
    }

    public function testIterYieldsMidStageForCooperativeStages(): void
    {
        $stage = new class implements \Phpdup\Pipeline\CooperativeStageInterface {
            public function name(): Stage { return Stage::Scanning; }
            public function run(PipelineState $s, OutputInterface $o): void { foreach ($this->iter($s, $o) as $_) {} }
            public function iter(PipelineState $state, OutputInterface $output): \Generator
            {
                // Three mid-stage yields.
                yield Stage::Scanning;
                yield Stage::Scanning;
                yield Stage::Scanning;
            }
        };

        $state = new PipelineState(Config::defaults([__DIR__]));
        $yields = iterator_to_array(
            (new Pipeline([$stage]))->iter($state, new NullOutput()),
            false,
        );

        // Pre + 3 mid + post = 5
        $this->assertCount(5, $yields);
    }

    public function testRunDrainsIterAndReturnsState(): void
    {
        $observed = [];
        $stages = [$this->recordingStage(Stage::Scanning, $observed)];
        $state  = new PipelineState(Config::defaults([__DIR__]));

        $returned = (new Pipeline($stages))->run($state, new NullOutput());

        $this->assertSame($state, $returned);
        $this->assertSame(Stage::Scanning, $state->stage);
    }

    // ── DebugLogger wiring (once-S69 / php-dup-finder-once-079) ─────────

    /**
     * A writable debug-log path records every debug()-level message the
     * pipeline pushes (the normal, admitted posture).
     */
    public function testPipelineRecordsDebugMessagesToWritableLog(): void
    {
        $log = sys_get_temp_dir() . '/phpdup-s69-ok-' . getmypid() . '.log';
        @unlink($log);
        try {
            $state = new PipelineState(
                Config::defaults([__DIR__])->withOverrides(['debug_log' => $log]),
            );
            (new Pipeline([$this->debugEmittingStage()]))->run($state, $this->debugOutput());

            $this->assertSame("m1\nm2\nm3\n", @file_get_contents($log));
        } finally {
            @unlink($log);
        }
    }

    /**
     * Unopenable log path (bypassing CLI admission — the serve/watch
     * payload posture): the whole run surfaces EXACTLY ONE fopen warning,
     * not one per message. The old retry-on-false leg spammed fopen
     * diagnostics on every debug() call; the failed open now parks the
     * handle terminal-false. The run still completes and shutdown stays
     * TypeError-free (shell-S29 safe-close core).
     */
    public function testUnwritableDebugLogWarnsOnceNotPerMessage(): void
    {
        $deadPath = sys_get_temp_dir() . '/phpdup-s69-missing-' . getmypid() . '/x.log';

        $warnings = self::captureWarnings(function () use ($deadPath): void {
            $state = new PipelineState(
                Config::defaults([__DIR__])->withOverrides(['debug_log' => $deadPath]),
            );
            (new Pipeline([$this->debugEmittingStage()]))->run($state, $this->debugOutput());
            $this->assertSame(Stage::Reporting, $state->stage, 'run must complete despite dead log');
        });

        $fopenWarnings = array_values(array_filter(
            $warnings,
            static fn(string $w): bool => str_contains($w, 'Failed to open stream'),
        ));
        $this->assertCount(1, $fopenWarnings, 'three debug messages must cost one warning, not three');
        clearstatcache();
        $this->assertFalse(is_file($deadPath), 'no log file may materialize on a dead path');
    }

    public function testAdmissionProbePassesWritablePathSilently(): void
    {
        $log = sys_get_temp_dir() . '/phpdup-s69-probe-' . getmypid() . '.log';
        @unlink($log); // start from an absent path
        try {
            $warnings = self::captureWarnings(function () use ($log): void {
                $this->assertNull(DebugLogger::admissionProbe($log));
            });
            $this->assertSame([], $warnings, 'the admission probe never leaks a warning');
            $this->assertFileExists($log, 'append-mode probe materializes the log');
            $this->assertSame('', file_get_contents($log));
        } finally {
            @unlink($log);
        }
    }

    public function testAdmissionProbeNamesTheFailureWithoutWarning(): void
    {
        $deadPath = sys_get_temp_dir() . '/phpdup-s69-missing-' . getmypid() . '/x.log';

        $reason = null;
        $warnings = self::captureWarnings(static function () use ($deadPath, &$reason): void {
            $reason = DebugLogger::admissionProbe($deadPath);
        });

        $this->assertSame([], $warnings, 'admission failures surface via the return value only');
        $this->assertIsString($reason);
        $this->assertStringContainsString('Failed to open stream', $reason);
        $this->assertStringStartsNotWith('fopen(', $reason, 'the fopen() prefix is stripped for the one-line message');
    }

    /**
     * Regression pin (once-S69 r1 review / n-1): a debug-log path that
     * itself contains ')' — e.g. "a)b.log" — must STILL get the whole
     * "fopen(...): " label stripped. The old non-greedy [^)]* regex
     * stopped at the first paren inside the path and leaked the label
     * into the one-line refusal reason.
     */
    public function testAdmissionProbeStripsLabelForPathContainingParenthesis(): void
    {
        $parenPath = sys_get_temp_dir() . '/phpdup-s69-missing-' . getmypid() . '/a)b.log';

        $reason = null;
        $warnings = self::captureWarnings(static function () use ($parenPath, &$reason): void {
            $reason = DebugLogger::admissionProbe($parenPath);
        });

        $this->assertSame([], $warnings, 'the paren-in-path probe leaks no warning either');
        $this->assertIsString($reason);
        $this->assertStringStartsWith(
            'Failed to open stream',
            $reason,
            'the greedy strip consumes the fopen label even when the path holds ")"',
        );
        $this->assertStringNotContainsString('): ', $reason, 'no fragment of the fopen(...) label survives');
    }

    private function debugEmittingStage(): StageInterface
    {
        return new class implements StageInterface {
            public function name(): Stage { return Stage::Scanning; }
            public function run(PipelineState $state, OutputInterface $output): void
            {
                $state->debug($output, 'm1');
                $state->debug($output, 'm2');
                $state->debug($output, 'm3');
                $state->stage = Stage::Reporting; // mark completion for the dead-log pin
            }
        };
    }

    private function debugOutput(): OutputInterface
    {
        $output = new BufferedOutput();
        $output->setVerbosity(OutputInterface::VERBOSITY_DEBUG);
        return $output;
    }

    /**
     * Run a callback with PHP warnings collected instead of emitted —
     * hermetic under phpunit.xml's failOnWarning="true" while still
     * counting what the user would have seen. Only EXPRESSION-LEVEL
     * suppression (@fopen in the admission probe) is skipped: PHPUnit
     * globally lowers error_reporting, so the capture first installs an
     * E_ALL baseline — under it, a diagnostic whose own bit is absent
     * from the live mask was suppressed at the call site.
     *
     * @return list<string>
     */
    private static function captureWarnings(callable $fn): array
    {
        $collected = [];
        $previousMask = error_reporting(E_ALL); // strict baseline under PHPUnit's lowered mask
        set_error_handler(static function (int $severity, string $message) use (&$collected): bool {
            if (!(error_reporting() & $severity)) {
                return false; // @-suppressed at the call site: nothing surfaces to the user either
            }
            $collected[] = $message;
            return true;
        });
        try {
            $fn();
        } finally {
            restore_error_handler();
            error_reporting($previousMask);
        }
        return $collected;
    }

    /** @param list<Stage> $observed */
    private function recordingStage(Stage $name, array &$observed): StageInterface
    {
        return new class($name, $observed) implements StageInterface {
            public function __construct(private Stage $stage, private array &$observed) {}
            public function name(): Stage { return $this->stage; }
            public function run(PipelineState $state, OutputInterface $output): void
            {
                $this->observed[] = $state->stage;
            }
        };
    }

    // ── once-S72: cooperative ReportStage (php-dup-finder-once-087) ─────────

    /**
     * Pump clock that never advances: the cost window cannot expire, so a
     * budget-gated stage owes ZERO mid-stage yields no matter how many items
     * it walks — the counterfactual that separates COST accounting from the
     * old count-driven pump rhythms.
     */
    private static function frozenPumpClock(): \Closure
    {
        return static fn(): float => 0.0;
    }

    /**
     * Pump clock advancing 10 ms per read: every third budget checkpoint
     * crosses the 25 ms window, so the reporting tail MUST start yielding —
     * the counterfactual that separates a real cooperative conversion from
     * a non-yielding synchronous body wearing a generator costume.
     */
    private static function hotPumpClock(): \Closure
    {
        $t = 0.0;
        return static function () use (&$t): float {
            return $t += 0.01;
        };
    }

    /**
     * Fresh deterministic reporting fixture: two exact-duplicate function
     * pairs in their own tmp tree → at least two clusters for the per-unit
     * loops to walk. Returns [state, tmpDir]; runs the real stages up to
     * (and including) clustering.
     *
     * @return array{0: PipelineState, 1: string}
     */
    private function reportingFixtureState(string $tag, ?string $jsonFile = null): array
    {
        $parent = sys_get_temp_dir() . '/phpdup-s72-' . $tag . '-' . getmypid() . '-' . bin2hex(random_bytes(4));
        // The corpus dir gets a FIXED basename so the repo label (derived
        // from the path's tail) is equal across sibling fixtures — only the
        // absolute paths differ, and the byte-equality pins normalize those.
        $dir = $parent . '/corpus';
        mkdir($dir . '/cache', 0o775, true);
        $alpha = "<?php\nfunction s72_alpha(int \$n): int\n{\n    \$x = \$n + 1;\n    \$y = \$x * 2;\n    return \$y - \$x;\n}\n";
        $beta  = "<?php\nfunction s72_beta(string \$s): string\n{\n    \$t = trim(\$s);\n    return strtoupper(\$t) . '!';\n}\n";
        file_put_contents($dir . '/alpha.php', $alpha);
        file_put_contents($dir . '/alpha2.php', $alpha);
        file_put_contents($dir . '/beta.php', $beta);
        file_put_contents($dir . '/beta2.php', $beta);

        $config = new Config(
            paths: [$dir],
            exclude: [],
            minBlockSize: 1,
            cacheDir: $dir . '/cache',
            jsonReportFile: $jsonFile,
            lazyAst: false,
        );
        $state = new PipelineState($config);
        $out   = new NullOutput();
        (new ScanningStage())->run($state, $out);
        (new PreprocessStage(useCache: false))->run($state, $out);
        (new ClusterStage(exactOnly: false, useClusterCache: false))->run($state, $out);
        return [$state, $parent];
    }

    private function rrmdir(string $parent): void
    {
        foreach (glob($parent . '/corpus/{,cache/}*', GLOB_BRACE) ?: [] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        @rmdir($parent . '/corpus/cache');
        @rmdir($parent . '/corpus');
        @rmdir($parent);
    }

    public function testReportStageIsCooperativeAndTransparentWhenTheBudgetNeverExpires(): void
    {
        $stage = new ReportStage(limit: 50, showStats: false, pumpClock: self::frozenPumpClock());
        $this->assertInstanceOf(CooperativeStageInterface::class, $stage, 'once-S72: the reporting stage joins the cooperative set');

        [$state, $dir] = $this->reportingFixtureState('frozen');
        try {
            $yields = iterator_to_array($stage->iter($state, new NullOutput()), false);
            $this->assertSame(
                [],
                $yields,
                'yields follow the COST budget, not a per-item count — a frozen clock can never expire it '
                . '(this goes red on any count-based pump rhythm)',
            );
            $this->assertNotNull($state->report, 'a zero-yield drive must still build the full report');
        } finally {
            $this->rrmdir($dir);
        }
    }

    public function testReportStageEmitsCostBoundedReportingFramesAcrossUnits(): void
    {
        $csv = sys_get_temp_dir() . '/phpdup-s72-hot-' . getmypid() . '.csv';
        $stage = new ReportStage(limit: 50, showStats: false, csvFile: $csv, pumpClock: self::hotPumpClock());

        [$state, $dir] = $this->reportingFixtureState('hot');
        try {
            $yields = iterator_to_array($stage->iter($state, new NullOutput()), false);
            $this->assertGreaterThanOrEqual(
                2,
                count($yields),
                'a running hot pump clock must get the report tail streaming across its units '
                . '(this goes red on a non-yielding synchronous body)',
            );
            $this->assertSame(
                array_fill(0, count($yields), Stage::Reporting),
                $yields,
                'every reporting frame names its own stage',
            );
            $this->assertNotNull($state->report);
            $this->assertFileExists($csv);
        } finally {
            @unlink($csv);
            $this->rrmdir($dir);
        }
    }

    /**
     * The #1 regression class for this conversion: carriers and payload must
     * cross the yield boundaries EXACTLY as the synchronous path built them —
     * same bytes in every sink, same CLI stream, same once-S37 carrier fields
     * on the Report. A reordered, dropped or duplicated unit inside the new
     * generator body lands here as a byte difference.
     */
    public function testRunAndCooperativeIterProduceByteIdenticalSinksCarriersAndCli(): void
    {
        $jsonA = sys_get_temp_dir() . '/phpdup-s72-eq-a-' . getmypid() . '.json';
        $jsonB = sys_get_temp_dir() . '/phpdup-s72-eq-b-' . getmypid() . '.json';
        $sarifA = sys_get_temp_dir() . '/phpdup-s72-eq-a-' . getmypid() . '.sarif';
        $sarifB = sys_get_temp_dir() . '/phpdup-s72-eq-b-' . getmypid() . '.sarif';
        [$stateRun, $dirRun] = $this->reportingFixtureState('eq-a', $jsonA);
        [$stateIter, $dirIter] = $this->reportingFixtureState('eq-b', $jsonB);
        try {
            // Same fixture bytes through both drives; the S37 carriers ride
            // identical signal so their threading is compared too.
            foreach ([$stateRun, $stateIter] as $s) {
                $s->scanNotes = ['s72 carrier note'];
                $s->parseFailures = ['ghost.php' => 'deliberately unreadable'];
                $s->clustersReplayed = true;
            }

            $stageRun  = new ReportStage(limit: 50, showStats: false, sarifFile: $sarifA, pumpClock: self::frozenPumpClock());
            $stageIter = new ReportStage(limit: 50, showStats: false, sarifFile: $sarifB, pumpClock: self::frozenPumpClock());

            $bufRun = new BufferedOutput();
            $stageRun->run($stateRun, $bufRun);
            $bufIter = new BufferedOutput();
            foreach ($stageIter->iter($stateIter, $bufIter) as $_) {
            }

            // The two tmp trees give equal-shaped-but-different path strings;
            // normalizing the sink bytes onto one path is the honest equality
            // statement for "one unit sequence, two drives" (paths themselves
            // are pinned by the golden suite).
            $normalize = static function (string $text) use ($dirRun, $dirIter, $jsonA, $jsonB, $sarifA, $sarifB): string {
                return str_replace(
                    [$dirIter, $jsonB, $sarifB],
                    [$dirRun, $jsonA, $sarifA],
                    $text,
                );
            };

            $rawJsonA = (string) file_get_contents($jsonA);
            $rawJsonB = $normalize((string) file_get_contents($jsonB));
            $this->assertSame($rawJsonA, $rawJsonB, 'the JSON sink must be byte-identical through run() vs drained iter()');
            $this->assertSame(
                (string) file_get_contents($sarifA),
                $normalize((string) file_get_contents($sarifB)),
                'the SARIF sink must be byte-identical through run() vs drained iter()',
            );
            $this->assertSame($bufRun->fetch(), $normalize($bufIter->fetch()), 'the CLI body must be byte-identical through run() vs drained iter() (after path normalization)');

            $this->assertSame(['s72 carrier note'], $stateIter->report->scanNotes, 'scanNotes cross the yield seam intact');
            $this->assertSame(['ghost.php' => 'deliberately unreadable'], $stateIter->report->parseFailures, 'parseFailures cross the yield seam intact');
            $this->assertTrue($stateIter->report->clustersReplayed, 'clustersReplayed crosses the yield seam intact');
            $this->assertSame($stateRun->report->scanNotes, $stateIter->report->scanNotes, 'carriers arrive identically on both drives');

            // Schema-1.8 posture (E2 owns the bump): the once-S37 carriers
            // cross the state→Report→JSON seam intact — the emit the
            // staging docblock promised for exactly this version move.
            $decoded = json_decode($rawJsonA, true);
            $this->assertIsArray($decoded);
            $this->assertSame(['s72 carrier note'], $decoded['summary']['scan_notes']);
            $this->assertSame(
                ['ghost.php' => 'deliberately unreadable'],
                $decoded['summary']['parse_failures'],
                'the parseFailures map written by this drive\'s stub rides the summary verbatim',
            );
            $this->assertTrue($decoded['summary']['clusters_replayed']);
            $this->assertSame(0, $decoded['summary']['blocks_oversized'], 'tracked zero — the fixture plants no oversized declaration');
            $this->assertNull($decoded['summary']['ml_status'], 'no ML client wired — honest absence, never a fabricated idle');
        } finally {
            foreach ([$jsonA, $jsonB, $sarifA, $sarifB] as $f) {
                @unlink($f);
            }
            $this->rrmdir($dirRun);
            $this->rrmdir($dirIter);
        }
    }

    /**
     * Deliberate soft-cancel exception (documented on CooperativeStageInterface):
     * ReportStage IS the partial report a cancel is meant to deliver, so it
     * runs to completion — and keeps yielding — even with $cancelled set.
     */
    public function testReportStageDeliberatelyIgnoresSoftCancelForThePartialReport(): void
    {
        [$state, $dir] = $this->reportingFixtureState('cancel');
        try {
            $state->cancelled = true;
            $stage = new ReportStage(limit: 50, showStats: false, pumpClock: self::hotPumpClock());
            $yields = iterator_to_array($stage->iter($state, new NullOutput()), false);

            $this->assertNotNull($state->report, 'a soft-cancelled run still gets its partial report');
            $this->assertTrue($state->cancelled, 'the stage must not consume the cancel flag');
            $this->assertGreaterThanOrEqual(2, count($yields), 'a cancelled tail keeps pumping frames, never aborts');
        } finally {
            $this->rrmdir($dir);
        }
    }

    /**
     * End-to-end: the Pipeline driver already passes cooperative mid-stage
     * frames from the reporting stage straight through — the live TUI pump
     * sees them without any driver-side change.
     */
    public function testPipelineDriverCarriesReportStageMidStageFrames(): void
    {
        [$state, $dir] = $this->reportingFixtureState('driver');
        try {
            $stage = new ReportStage(limit: 50, showStats: false, pumpClock: self::hotPumpClock());
            $yields = iterator_to_array((new Pipeline([$stage]))->iter($state, new NullOutput()), false);

            // pre + post frames alone would be 2; the >=4 claim proves the
            // cost-bounded mid-stage frames flowed through the driver.
            $this->assertGreaterThanOrEqual(4, count($yields), 'the driver must relay ReportStage mid-stage frames');
            $this->assertSame(Stage::Reporting, $state->stage);
            $this->assertSame(1.0, $state->stageProgress, 'the driver closes the stage at 1.0 after the cooperative body');
        } finally {
            $this->rrmdir($dir);
        }
    }

    /** The 0.6 no-files guard survives the generator conversion unchanged. */
    public function testCooperativeReportSkipsCleanlyWhenNoFilesWereScanned(): void
    {
        $state  = new PipelineState(Config::defaults([__DIR__]));
        $stage  = new ReportStage(limit: 50, showStats: false, pumpClock: self::hotPumpClock());
        $yields = iterator_to_array($stage->iter($state, new NullOutput()), false);

        $this->assertSame([], $yields, 'no files means no work — and no frames to pump');
        $this->assertNull($state->report);
    }
}
