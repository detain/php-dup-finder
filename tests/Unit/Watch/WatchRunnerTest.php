<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Watch;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Clustering\CandidateBudgetException;
use Phpdup\Pipeline\Pipeline;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\Stage;
use Phpdup\Pipeline\StageInterface;
use Phpdup\Watch\FileChangeType;
use Phpdup\Watch\WatchRunner;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * ui-grammar: the two watch-mode render surfaces (the "watching …" line and
 * the candidate-budget halt posture) are pinned through the seams extracted
 * from run(); the blocking event loop itself stays unexercised.
 *
 * shell-S33 (php-dup-finder-shell-023/024) adds the resilience pins on the
 * same seam convention: handleChange() is the exact callback the live loop
 * installs (run() passes [$this, 'handleChange'] to FileWatcher::watch()),
 * so surviving-crash, streak and pause behavior is testable without ever
 * entering the blocking event loop.
 */
final class WatchRunnerTest extends TestCase
{
    /** @var string watch-root stand-in for handleChange tests (resync walks it) */
    private string $scanRoot;

    protected function setUp(): void
    {
        $this->scanRoot = sys_get_temp_dir() . '/phpdup_watchrunner_test_' . uniqid('', true);
        mkdir($this->scanRoot, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->scanRoot . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->scanRoot);
    }

    public function testAnnounceWatchingSingularizesSingleFile(): void
    {
        $output = new BufferedOutput();
        $this->runner($output)->announceWatching(1);

        $rendered = $output->fetch();
        $this->assertStringContainsString('watching 1 file · last update', $rendered);
        $this->assertStringNotContainsString('1 files', $rendered);
    }

    public function testAnnounceWatchingStaysPluralAboveOne(): void
    {
        $output = new BufferedOutput();
        $this->runner($output)->announceWatching(2);

        $this->assertStringContainsString('watching 2 files · last update', $output->fetch());
    }

    /**
     * A budget halt on the watch path renders as the clean `phpdup:` line
     * (mirror of the ft-n2 plain-path catch in Command::execute()) — never
     * Symfony's throwable renderer with its usage dump or a stack trace.
     */
    public function testBudgetHaltKeepsWatchSurfaceClean(): void
    {
        $output = new BufferedOutput();
        $pipeline = new Pipeline([new class implements StageInterface {
            public function name(): Stage
            {
                return Stage::Clustering;
            }

            public function run(PipelineState $state, OutputInterface $output): void
            {
                throw CandidateBudgetException::exceeded(1, 1, 2);
            }
        }]);

        $runner = new WatchRunner(
            $pipeline,
            static fn(): PipelineState => new PipelineState(Config::defaults([sys_get_temp_dir()])),
            $output,
            new NullLogger(),
            sys_get_temp_dir(),
        );
        $this->assertTrue($runner->runGuarded(new PipelineState(Config::defaults([sys_get_temp_dir()]))));

        $rendered = $output->fetch();
        $this->assertStringContainsString('phpdup: Candidate generation exceeded', $rendered);
        $this->assertStringNotContainsString('Usage:', $rendered);
        $this->assertStringNotContainsString('Stack trace:', $rendered);
    }

    // ─── shell-S33: survive any pipeline Throwable (php-dup-finder-shell-023) ──

    /**
     * The headline acceptance: a plain RuntimeException on pass 1 no longer
     * kills the long-lived watch loop (was: escape to Symfony's throwable
     * renderer → process death). The warning renders in the clean `phpdup:`
     * posture and the NEXT event still triggers a full pass.
     */
    public function testRuntimeCrashOnPass1KeepsWatcherAliveAndNextEventRepasses(): void
    {
        $stage = new WatchScriptedStage();
        $stage->script = [new \RuntimeException('poisoned cache row'), null];
        $output = new BufferedOutput();
        $runner = $this->runnerWithStage($stage, $output);

        $runner->handleChange('a.php', FileChangeType::Modified); // must NOT throw

        $first = $output->fetch();
        $this->assertSame(1, $stage->runs);
        $this->assertStringContainsString('phpdup: watch pass failed (RuntimeException): poisoned cache row', $first);
        $this->assertStringNotContainsString('Usage:', $first);
        $this->assertStringNotContainsString('Stack trace:', $first);

        $runner->handleChange('b.php', FileChangeType::Created);

        $second = $output->fetch();
        $this->assertSame(2, $stage->runs, 'next event after a crash must still trigger a pass');
        $this->assertStringContainsString('change detected (created: b.php) — reload #2', $second);
        $this->assertStringNotContainsString('watch pass failed', $second);
    }

    /**
     * Back-off posture (pinned choice: simple skip-note): after
     * MAX_CONSECUTIVE_PASS_FAILURES crashing passes the loop no longer
     * re-runs the pipeline per event; a single pause notice is rendered,
     * and it is rendered exactly once however many further events arrive.
     */
    public function testConsecutiveCrashesTripSkipNotePostureOnce(): void
    {
        $stage = new WatchScriptedStage();
        $stage->always = new \RuntimeException('boom');
        $output = new BufferedOutput();
        $runner = $this->runnerWithStage($stage, $output);

        $max = WatchRunner::MAX_CONSECUTIVE_PASS_FAILURES;
        for ($i = 0; $i < $max; $i++) {
            $runner->handleChange('x.php', FileChangeType::Modified);
        }
        $this->assertSame($max, $stage->runs);

        // Beyond the cap: events are skipped, not re-run.
        $runner->handleChange('x.php', FileChangeType::Modified);
        $runner->handleChange('x.php', FileChangeType::Modified);
        $this->assertSame($max, $stage->runs, 'paused watch must not re-run the crashing pipeline');

        $rendered = $output->fetch();
        $this->assertSame($max, substr_count($rendered, 'watch pass failed'));
        $this->assertSame(1, substr_count($rendered, 'watch mode paused after ' . $max));
        $this->assertStringContainsString('restart the command to resume watching', $rendered);
    }

    /**
     * A clean pass resets the streak: two crashes, a success, then two more
     * crashes is NOT four in a row — the pause only arms once the THIRD
     * consecutive crash has happened and the next event arrives.
     */
    public function testCleanPassResetsFailureStreak(): void
    {
        $stage = new WatchScriptedStage();
        $stage->script = [
            new \RuntimeException('c1'),
            new \RuntimeException('c2'),
            null,                      // clean pass -> reset
            new \RuntimeException('c3'),
            new \RuntimeException('c4'),
            new \RuntimeException('c5'), // 3rd in a row since reset -> cap
        ];
        $output = new BufferedOutput();
        $runner = $this->runnerWithStage($stage, $output);

        for ($i = 0; $i < 6; $i++) {
            $runner->handleChange('x.php', FileChangeType::Modified);
        }
        $this->assertSame(6, $stage->runs);
        $this->assertStringNotContainsString('watch mode paused', $output->fetch());

        $runner->handleChange('x.php', FileChangeType::Modified); // 7th: paused
        $this->assertSame(6, $stage->runs);
        $this->assertStringContainsString('watch mode paused', $output->fetch());
    }

    /**
     * Typed halts (budget / script-kind refusal) are NEUTRAL for the
     * streak: they neither crash-count nor reset it. One crash + budget
     * halt + two crashes = three-in-a-row cap, armed on the fourth event.
     */
    public function testTypedHaltsAreNeutralToFailureStreak(): void
    {
        $stage = new WatchScriptedStage();
        $stage->script = [
            new \RuntimeException('crash1'),
            CandidateBudgetException::exceeded(1, 1, 2), // neutral halt
            new \RuntimeException('crash2'),
            new \RuntimeException('crash3'),             // streak now 3
        ];
        $output = new BufferedOutput();
        $runner = $this->runnerWithStage($stage, $output);

        for ($i = 0; $i < 4; $i++) {
            $runner->handleChange('x.php', FileChangeType::Modified);
        }
        $this->assertSame(4, $stage->runs);

        $rendered = $output->fetch();
        $this->assertStringContainsString('phpdup: Candidate generation exceeded', $rendered);
        $this->assertSame(3, substr_count($rendered, 'watch pass failed'));
        $this->assertStringNotContainsString('watch mode paused', $rendered);

        $runner->handleChange('x.php', FileChangeType::Modified); // 5th: cap hit
        $this->assertSame(4, $stage->runs, 'budget halt must not have reset the crash streak nor counted as a crash');
        $this->assertStringContainsString('watch mode paused', $output->fetch());
    }

    // ─── shell-S33: coalesced batch rendering (php-dup-finder-shell-024) ───────

    /**
     * One debounced flush = one reload line; a multi-file batch says so
     * ("· N files coalesced"), a lone change does not.
     */
    public function testCoalescedBatchRendersOncePerFlushWithCount(): void
    {
        $stage = new WatchScriptedStage();
        $output = new BufferedOutput();
        $runner = $this->runnerWithStage($stage, $output);

        $runner->handleChange('e.php', FileChangeType::Created, [
            'a.php' => FileChangeType::Created,
            'b.php' => FileChangeType::Modified,
            'e.php' => FileChangeType::Created,
        ]);

        $first = $output->fetch();
        $this->assertSame(1, $stage->runs);
        $this->assertStringContainsString('change detected (created: e.php · 3 files coalesced) — reload #1', $first);

        $runner->handleChange('solo.php', FileChangeType::Modified, ['solo.php' => FileChangeType::Modified]);
        $second = $output->fetch();
        $this->assertSame(2, $stage->runs);
        $this->assertStringContainsString('change detected (modified: solo.php) — reload #2', $second);
        $this->assertStringNotContainsString('coalesced', $second);
    }

    /**
     * The historical per-event call shape (two args — what the TUI watch
     * leg in Command.php still passes to FileWatcher::watch) must keep
     * working on the callback seam: the batch defaults to lone-change.
     */
    public function testHandleChangeAcceptsLegacyTwoArgShape(): void
    {
        $stage = new WatchScriptedStage();
        $output = new BufferedOutput();
        $runner = $this->runnerWithStage($stage, $output);

        $runner->handleChange('only.php', FileChangeType::Deleted);

        $this->assertSame(1, $stage->runs);
        $this->assertStringContainsString('change detected (deleted: only.php) — reload #1', $output->fetch());
    }

    private function runner(BufferedOutput $output): WatchRunner
    {
        return new WatchRunner(
            new Pipeline([]),
            static fn(): PipelineState => new PipelineState(Config::defaults([sys_get_temp_dir()])),
            $output,
            new NullLogger(),
            sys_get_temp_dir(),
        );
    }

    private function runnerWithStage(WatchScriptedStage $stage, BufferedOutput $output): WatchRunner
    {
        return new WatchRunner(
            new Pipeline([$stage]),
            fn(): PipelineState => new PipelineState(Config::defaults([$this->scanRoot])),
            $output,
            new NullLogger(),
            $this->scanRoot,
        );
    }
}

/**
 * Pipeline stage double driven per pass: `script` entries are consumed one
 * per run (Throwable to throw, null to pass clean); once exhausted,
 * `always` decides. Counting runs is the "did the watch re-analyze?" pin.
 */
final class WatchScriptedStage implements StageInterface
{
    public int $runs = 0;

    /** @var list<\Throwable|null> */
    public array $script = [];

    public ?\Throwable $always = null;

    public function name(): Stage
    {
        return Stage::Clustering;
    }

    public function run(PipelineState $state, OutputInterface $output): void
    {
        $this->runs++;
        if ($this->script !== []) {
            $throwable = array_shift($this->script);
            if ($throwable !== null) {
                throw $throwable;
            }
            return;
        }
        if ($this->always !== null) {
            throw $this->always;
        }
    }
}
