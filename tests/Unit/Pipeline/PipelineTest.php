<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Pipeline;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Pipeline\DebugLogger;
use Phpdup\Pipeline\Pipeline;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\Stage;
use Phpdup\Pipeline\StageInterface;
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
}
