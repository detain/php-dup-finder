<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Watch;

use PhpParser\Node\Stmt;
use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Clustering\CandidateBudgetException;
use Phpdup\Extraction\Block;
use Phpdup\Extraction\BlockExtractor;
use Phpdup\Pipeline\Pipeline;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\Stage;
use Phpdup\Pipeline\StageInterface;
use Phpdup\Pipeline\Stages\ClusterStage;
use Phpdup\Pipeline\Stages\PreprocessStage;
use Phpdup\Pipeline\Stages\ScanningStage;
use Phpdup\Refactor\ScriptKindApplyRefusal;
use Phpdup\Refactor\WindowApplyRefusal;
use Phpdup\Util\LineRange;
use Phpdup\Watch\FileChangeType;
use Phpdup\Watch\FileWatcher;
use Phpdup\Watch\WatchRunner;
use Psr\Log\NullLogger;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
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
 *
 * once-S08 (php-dup-finder-once-008 closure) extends the pins to the legs
 * OUTSIDE the pipeline call: handleChange and the boot dispatch share one
 * private seam (dispatchGuarded: rebuildState closure -> runGuarded ->
 * resync), so a construction or rebase throw — which pre-closure escaped
 * Loop::run() and died the daemon — is pinned through the same
 * handleChange() drive-by, loop still unexercised.
 *
 * once-S08 fix r1 (review LOW-1): the rebase leg of that seam was guarded
 * but unpinned (guarding its call site away stayed green). The
 * testRebaseThrow… pin below closes the gap with the prescription's forcing:
 * a stream-wrapper protocol as the watcher's scan root makes resync()
 * itself raise, so the per-site-mutant law holds for the third leg too.
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
        // Recursive: the once-S47 pins create an excluded vendor/ tree
        // under the root; a plain glob+unlink would strand it (and the
        // root rmdir) in /tmp on failure paths.
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->scanRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iter as $path) {
            /** @var \SplFileInfo $path */
            if ($path->isDir() && !$path->isLink()) {
                @rmdir($path->getPathname());
            } else {
                @unlink($path->getPathname());
            }
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

    /**
     * E2 fix-1 (r1 MAJOR-1): the window apply refusal is a typed halt of the
     * same family — a warm-replay rebaseClusters throw on a window member
     * (default-ON windowing puts window species in every cache, and the CLI
     * seam Command::execute lists all three refusals) must render as the
     * clean `phpdup:` line at the watch seam too. Before the fix the union
     * arm missed it: the throw rode the generic Throwable arm, counted as a
     * pass FAILURE and could arm the watch-mode pause. runGuarded returning
     * true (not-crashed) with no failure render is the pin; the script-kind
     * arm gets the same direct pin, which r1 established was missing
     * (it had been covered only by the union listing).
     */
    public function testWindowHaltKeepsWatchSurfaceClean(): void
    {
        $output = new BufferedOutput();
        $pipeline = new Pipeline([new class implements StageInterface {
            public function name(): Stage
            {
                return Stage::Clustering;
            }

            public function run(PipelineState $state, OutputInterface $output): void
            {
                throw WindowApplyRefusal::forBlock(
                    new Block('w.php', new LineRange(3, 900), BlockExtractor::WINDOW_KIND, null, null, 'giant', new Stmt\Nop()),
                    'loader rebase',
                );
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
        $this->assertStringContainsString("'window'-kind blocks are synthetic", $rendered);
        $this->assertStringNotContainsString('watch pass failed', $rendered, 'a typed refusal must not count as a crash');
        $this->assertStringNotContainsString('Usage:', $rendered);
        $this->assertStringNotContainsString('Stack trace:', $rendered);
    }

    public function testScriptHaltKeepsWatchSurfaceClean(): void
    {
        $output = new BufferedOutput();
        $pipeline = new Pipeline([new class implements StageInterface {
            public function name(): Stage
            {
                return Stage::Clustering;
            }

            public function run(PipelineState $state, OutputInterface $output): void
            {
                throw ScriptKindApplyRefusal::forBlock(
                    new Block('s.php', new LineRange(1, 40), 'script', null, null, null, new Stmt\Nop()),
                    'loader rebase',
                );
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
        $this->assertStringContainsString("'script'-kind blocks are synthetic", $rendered);
        $this->assertStringNotContainsString('watch pass failed', $rendered, 'a typed refusal must not count as a crash');
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
     * shell-S33 fix r1 (M-2): the survival contract is ANY Throwable, not
     * just Exception. Every other pin throws Exception subclasses, which is
     * why the review's catch-NARROWING mutation (Throwable → Exception —
     * the exact half-regression the step title forbids) measured GREEN.
     * A bare \Error (realistic: a stage hitting a type error) must render
     * through the same clean posture AND count toward the streak; under a
     * catch (\Exception) arm this throwable escapes and kills the loop, so
     * this test flips red on that mutation.
     */
    public function testErrorCrashAlsoKeepsWatcherAlive(): void
    {
        $stage = new WatchScriptedStage();
        $stage->script = [new \Error('poisoned span'), null];
        $output = new BufferedOutput();
        $runner = $this->runnerWithStage($stage, $output);

        $runner->handleChange('a.php', FileChangeType::Modified); // must NOT throw

        $first = $output->fetch();
        $this->assertSame(1, $stage->runs);
        $this->assertStringContainsString('phpdup: watch pass failed (Error): poisoned span', $first);
        $this->assertStringNotContainsString('Usage:', $first);
        $this->assertStringNotContainsString('Stack trace:', $first);

        $runner->handleChange('b.php', FileChangeType::Created);

        $second = $output->fetch();
        $this->assertSame(2, $stage->runs, 'an Error crash must not end the loop for the next event');
        $this->assertStringContainsString('change detected (created: b.php) — reload #2', $second);
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

    // ─── once-S08: the catch-all covers the WHOLE dispatch (php-dup-finder-once-008) ──

    /**
     * The closure acceptance for once-008: shell-S33 armed runGuarded()
     * against pipeline Throwables, but the per-event rebuildState closure
     * (this pass's fresh state/pool construction — the finding's
     * "misplaced config" vector) sat OUTSIDE any catch: a RuntimeException
     * from it escaped handleChange, rode out of Loop::run() and killed the
     * daemon — the bug survived its own fix. Now the whole dispatch is
     * under the catch-all: the throw logs one clean failure line, no pass
     * is started, the loop is alive, and the NEXT event re-analyzes.
     */
    public function testRebuildStateThrowKeepsWatcherAliveAndNextEventRepasses(): void
    {
        $stage = new WatchScriptedStage();
        $output = new BufferedOutput();
        $attempts = 0;
        $rebuild = function () use (&$attempts): PipelineState {
            $attempts++;
            if ($attempts === 1) {
                throw new \RuntimeException('misplaced config in tree');
            }
            return new PipelineState(Config::defaults([$this->scanRoot]));
        };
        $runner = new WatchRunner(
            new Pipeline([$stage]), $rebuild, $output, new NullLogger(), $this->scanRoot,
        );

        $runner->handleChange('a.php', FileChangeType::Modified); // must NOT throw

        $first = $output->fetch();
        $this->assertSame(0, $stage->runs, 'a failed state construction must not start a pass');
        $this->assertStringContainsString(
            'phpdup: watch pass failed (RuntimeException): misplaced config in tree', $first,
        );
        $this->assertStringNotContainsString('Usage:', $first);
        $this->assertStringNotContainsString('Stack trace:', $first);

        $runner->handleChange('b.php', FileChangeType::Created);

        $second = $output->fetch();
        $this->assertSame(1, $stage->runs, 'next event after a construction throw must re-analyze');
        $this->assertStringContainsString('change detected (created: b.php) — reload #2', $second);
        $this->assertStringNotContainsString('watch pass failed', $second);
    }

    /**
     * Same catch-narrowing guard shell-S33 r1 (M-2) established for the
     * pipeline arm, applied to the new dispatch arm: a bare \Error from
     * the construction closure must survive too. Under a catch (\Exception)
     * dispatch arm this throwable escapes and kills the loop, so this pin
     * flips red on Throwable→Exception narrowing of the once-S08 catch.
     */
    public function testRebuildStateErrorKeepsWatcherAlive(): void
    {
        $stage = new WatchScriptedStage();
        $output = new BufferedOutput();
        $attempts = 0;
        $rebuild = function () use (&$attempts): PipelineState {
            $attempts++;
            if ($attempts === 1) {
                throw new \Error('typed property poisoned at construction');
            }
            return new PipelineState(Config::defaults([$this->scanRoot]));
        };
        $runner = new WatchRunner(
            new Pipeline([$stage]), $rebuild, $output, new NullLogger(), $this->scanRoot,
        );

        $runner->handleChange('a.php', FileChangeType::Modified); // must NOT throw

        $this->assertSame(0, $stage->runs);
        $this->assertStringContainsString(
            'phpdup: watch pass failed (Error): typed property poisoned at construction',
            $output->fetch(),
        );

        $runner->handleChange('b.php', FileChangeType::Modified);
        $this->assertSame(1, $stage->runs, 'an Error at construction must not end the loop');
    }

    /**
     * Construction throws share the skip-note streak (they are exactly a
     * broken tree that will fail every reload): MAX throws in a row arm
     * the pause posture, and the process stays alive taking further
     * events — the forced-failure "process alive" assertion of the
     * acceptance, end to end.
     */
    public function testConstructionThrowsCountTowardPauseStreakAndProcessStaysAlive(): void
    {
        $stage = new WatchScriptedStage();
        $output = new BufferedOutput();
        $rebuild = static fn(): PipelineState => throw new \RuntimeException('poisoned config');
        $runner = new WatchRunner(
            new Pipeline([$stage]), $rebuild, $output, new NullLogger(), $this->scanRoot,
        );

        $max = WatchRunner::MAX_CONSECUTIVE_PASS_FAILURES;
        for ($i = 0; $i < $max; $i++) {
            $runner->handleChange('x.php', FileChangeType::Modified); // must NOT throw
        }
        $this->assertSame(0, $stage->runs, 'no pass ever started: every dispatch died at construction');

        $rendered = $output->fetch();
        $this->assertSame($max, substr_count($rendered, 'watch pass failed'));
        $this->assertStringNotContainsString('watch mode paused', $rendered);

        // Cap reached: the next two events are acknowledged with the pause
        // notice — once in total, not per event — and still must not throw
        // (the loop would have died here pre-closure: the throw escaped
        // Loop::run()).
        $runner->handleChange('x.php', FileChangeType::Modified);
        $runner->handleChange('x.php', FileChangeType::Modified);
        $paused = $output->fetch();
        $this->assertSame(
            1, substr_count($paused, 'watch mode paused after ' . $max),
            'pause notice renders exactly once however many events keep arriving',
        );
        $this->assertStringNotContainsString('watch pass failed', $paused);
        $this->assertStringContainsString('restart the command to resume watching', $paused);
    }

    /**
     * once-S08 fix r1 (review LOW-1): the rebase leg of dispatchGuarded()
     * was guarded but unpinned — removing its guard (moving resync() out of
     * the try) left the whole suite green. Forcing: FileScanner swallows
     * every ENVIRONMENT error the hardened walk can meet (NN2/once-095:
     * unreadable roots, permission races, hostile globs — no chmod trick
     * raises), but a thrown exception from a user stream wrapper's
     * url_stat escapes is_dir() unchanged (PHP 8), so pointing the
     * watcher's scan root at a one-protocol poison wrapper makes the REAL
     * resync() → collectWatchableFiles() → scan() chain raise inside the
     * try, no src seam needed. The pipeline itself still completes
     * (runs == 1): only the rebase dies — the shape that pre-closure rode
     * out of Loop::run() and terminated `phpdup watch`.
     *
     * Pinned acceptance: no throwable escapes handleChange; exactly one
     * clean `watch pass failed` line renders (no Usage dump); the announce
     * stays honest at `watching 0 files`; and the rebase throw COUNTS
     * toward the skip-note streak — it arms the counter at 1 so the two
     * construction failures behind it reach MAX and the NEXT event takes
     * the pause posture (the notice rendering once). If the rebase failure
     * were dropped from the counter, the cap would arm one event later and
     * the mid-flight asserts would fail.
     */
    public function testRebaseThrowKeepsWatcherAliveAndCountsTowardStreak(): void
    {
        stream_wrapper_register(PoisonedRebaseWrapper::PROTOCOL, PoisonedRebaseWrapper::class);
        try {
            $stage = new WatchScriptedStage();
            $output = new BufferedOutput();
            $attempts = 0;
            // Construction is healthy on pass 1 (only the rebase dies); the
            // two later dispatches fail at construction to walk the shared
            // streak counter up to the cap.
            $rebuild = function () use (&$attempts): PipelineState {
                $attempts++;
                if ($attempts >= 2) {
                    throw new \RuntimeException('misplaced config in tree');
                }
                return new PipelineState(Config::defaults([$this->scanRoot]));
            };
            $runner = new WatchRunner(
                new Pipeline([$stage]), $rebuild, $output, new NullLogger(),
                PoisonedRebaseWrapper::PROTOCOL . '://root',
            );

            $runner->handleChange('a.php', FileChangeType::Modified); // must NOT throw

            $first = $output->fetch();
            $this->assertSame(1, $stage->runs, 'the pass completed; only the rebase died');
            $this->assertStringContainsString(
                'phpdup: watch pass failed (RuntimeException): poisoned rebase enumeration', $first,
            );
            $this->assertStringContainsString('watching 0 files', $first);
            $this->assertStringNotContainsString('Usage:', $first);
            $this->assertStringNotContainsString('Stack trace:', $first);

            $runner->handleChange('b.php', FileChangeType::Modified); // must NOT throw — streak 2
            $this->assertStringContainsString(
                'phpdup: watch pass failed (RuntimeException): misplaced config in tree', $output->fetch(),
            );
            $this->assertSame(1, $stage->runs, 'a construction throw must not start a pass');

            $runner->handleChange('c.php', FileChangeType::Modified); // must NOT throw — streak 3 = MAX
            $this->assertStringContainsString('watch pass failed', $output->fetch());

            // Cap reached — and it armed one event EARLIER because the
            // rebase throw counted: events 2+3 alone would only be 2 of 3.
            $runner->handleChange('d.php', FileChangeType::Modified); // must NOT throw
            $paused = $output->fetch();
            $this->assertSame(1, $stage->runs, 'paused watch must not dispatch again');
            $this->assertStringContainsString(
                'watch mode paused after ' . WatchRunner::MAX_CONSECUTIVE_PASS_FAILURES, $paused,
            );
            $this->assertStringNotContainsString('watch pass failed', $paused);

            $runner->handleChange('e.php', FileChangeType::Modified); // still alive, notice not repeated
            $this->assertStringNotContainsString('watch mode paused', $output->fetch());
        } finally {
            stream_wrapper_unregister(PoisonedRebaseWrapper::PROTOCOL);
        }
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

    /**
     * once-S47: pin the REAL consumer wiring (FileWatcher on a fake loop +
     * the injectable WatchRunner seam + run()'s exact
     * watch([$runner,'handleChange']) installation) so burst-to-single-pass
     * and exclude-coherence are measured end to end, blocking loop and
     * wall-clock untouched exactly like the run()-outside seams above.
     */
    private function instrumentedRunner(
        WatchScriptedStage $stage,
        BufferedOutput $output,
        FileWatcher $watcher,
    ): WatchRunner {
        return new WatchRunner(
            new Pipeline([$stage]),
            fn(): PipelineState => new PipelineState(Config::defaults([$this->scanRoot])),
            $output,
            new NullLogger(),
            $this->scanRoot,
            1.5,
            $watcher,
        );
    }

    private function pollBackedWatcher(
        LoopInterface $loop,
    ): FileWatcher {
        return new FileWatcher(
            new NullLogger(),
            $this->scanRoot,
            1.0,
            $loop,
            FileWatcher::BACKEND_POLL,
            FileWatcher::DEBOUNCE_SECONDS,
        );
    }

    // ─── once-S47: debounced bursts + exclude coherence through WatchRunner ──

    /**
     * Acceptance "events within debounce window coalesce to ONE
     * re-analysis" (php-dup-finder-once-086/094), measured through the
     * pipeline-pass counter — the ONLY consumer-visible unit of work:
     * three hint ticks inside one window are one flush = one pass (not
     * three); the pass lands exactly when the window expires, never at
     * hint time. A second window is its own single pass.
     */
    public function testBurstInsideWindowCoalescesToOneReanalysis(): void
    {
        $loop = new CoalescingTestLoop();
        $watcher = $this->pollBackedWatcher($loop);
        $stage = new WatchScriptedStage();
        $output = new BufferedOutput();
        $runner = $this->instrumentedRunner($stage, $output, $watcher);

        // run()'s consumer installation, verbatim shape.
        $watcher->watch([$runner, 'handleChange']);

        foreach (['a.php', 'b.php', 'c.php'] as $file) {
            file_put_contents($this->scanRoot . '/' . $file, "<?php\n//x\n");
            $loop->firePeriodic(); // raw hint per file
        }
        $this->assertSame(0, $stage->runs, 'hints alone never re-analyze — the window decides');

        $loop->fireTimers();
        $this->assertSame(1, $stage->runs, 'one debounced window = exactly one re-analysis');
        $this->assertStringContainsString('3 files coalesced', $output->fetch());

        // The window re-arms after expiry: the next burst is its own pass.
        file_put_contents($this->scanRoot . '/d.php', "<?php\n//y\n");
        $loop->firePeriodic();
        $loop->firePeriodic();
        $this->assertSame(1, $loop->countPendingOneShots(), 'hints inside the open window merge');
        $loop->fireTimers();
        $this->assertSame(2, $stage->runs, 'second window = second (and only second) pass');
        $this->assertStringContainsString('change detected (created:', $output->fetch());
    }

    /**
     * Acceptance "Config::exclude respected in BOTH backends" on its live
     * wiring: every dispatch pushes its state's exclude view onto the
     * watcher before the run (once-086's vendor-churn half). Mutation:
     * drop the applyExcludes call in WatchRunner::dispatchGuarded() and
     * the vendor edit below flushes a re-analysis — runs hits 2.
     */
    public function testExcludedTreeNeverReanalyzesViaDispatchedState(): void
    {
        mkdir($this->scanRoot . '/vendor', 0777, true);
        file_put_contents($this->scanRoot . '/a.php', "<?php\n");
        file_put_contents($this->scanRoot . '/vendor/loaded.php', "<?php\n");

        $loop = new CoalescingTestLoop();
        $watcher = $this->pollBackedWatcher($loop);
        $stage = new WatchScriptedStage();
        $output = new BufferedOutput();
        $runner = $this->instrumentedRunner($stage, $output, $watcher);

        // Boot dispatch first (run()'s order): rebuildState -> applyExcludes
        // -> pass -> resync. Config::defaults excludes '**/vendor/**'.
        $runner->handleChange('a.php', FileChangeType::Created);
        $this->assertSame(1, $stage->runs);

        $watcher->watch([$runner, 'handleChange']);

        // Same-second-size-changing edit under vendor: invisible in the
        // flush enumeration — neither Modified nor phantom anything.
        file_put_contents($this->scanRoot . '/vendor/loaded.php', "<?php\n//mutated\n");
        touch($this->scanRoot . '/vendor/loaded.php', time() + 10);
        $loop->firePeriodic();
        $loop->fireTimers();
        $this->assertSame(1, $stage->runs, 'vendor churn must never wake the pipeline');

        // A watched-tree edit still fires: the exclude view narrows, it
        // does not deafen.
        file_put_contents($this->scanRoot . '/a.php', "<?php\n//moved\n");
        touch($this->scanRoot . '/a.php', time() + 20);
        $loop->firePeriodic();
        $loop->fireTimers();
        $this->assertSame(2, $stage->runs, 'non-excluded changes keep re-analyzing');
        $this->assertStringContainsString('change detected', $output->fetch());
    }

    // ─── once-S94: a REAL pipeline re-runs on a REAL event ──────────────────

    /**
     * The once-S94 acceptance: the scripted-counter legs above prove the
     * dispatch machinery re-enters; this one proves the analysis is real.
     * The same live consumer wiring as the S47 pins (real FileWatcher on a
     * poll backend + fake loop + run()'s exact watch() installation), but the
     * pipeline is the genuine Scanning→Preprocess→Cluster chain over the
     * test's temp tree. When an event lands a new file, the per-pass counts
     * the real stages produce — files scanned, blocks extracted — move with
     * it. That is a re-analysis, not a reload gesture.
     */
    public function testRealEventMovesTheCountsWithARealPipelineReanalysis(): void
    {
        // Two near-identical standalone functions, each comfortably past the
        // default min_block_size of 8, built in the temp tree as the watch
        // subject. (Cluster outcomes are deliberately NOT pinned here — the
        // acceptance is that the REAL per-pass counts move with the tree.)
        $twin = static fn(string $name, int $seed): string => sprintf(
            "<?php\nfunction %s(int \$x): int\n{\n%s\n    return \$h;\n}\n",
            $name,
            implode("\n", array_map(
                static fn(int $i): string => sprintf('    $%s = $%s + %d;', chr(97 + $i), $i === 0 ? '$x' : chr(96 + $i), $seed + $i),
                range(0, 7)
            ))
        );
        file_put_contents($this->scanRoot . '/a.php', $twin('alpha', 1));

        $counter = new WatchCountStage();
        $loop = new CoalescingTestLoop();
        $watcher = $this->pollBackedWatcher($loop);
        $output = new BufferedOutput();
        $runner = new WatchRunner(
            new Pipeline([new ScanningStage(), new PreprocessStage(false), new ClusterStage(false, 0, null, false), $counter]),
            fn(): PipelineState => new PipelineState(Config::defaults([$this->scanRoot])),
            $output,
            new NullLogger(),
            $this->scanRoot,
            1.5,
            $watcher,
        );

        // Boot dispatch (run()'s order), then install the watcher.
        $runner->handleChange('a.php', FileChangeType::Created);
        $watcher->watch([$runner, 'handleChange']);

        $this->assertCount(1, $counter->passes, 'the boot pass really ran the pipeline');
        $this->assertSame(1, $counter->passes[0]['files'], 'pass one scanned exactly a.php');
        $this->assertGreaterThanOrEqual(1, $counter->passes[0]['blocks'], 'the function block was really extracted');
        $this->assertStringContainsString('watching 1 file · last update', $output->fetch());

        // A REAL change: the twin function lands in the watched tree.
        file_put_contents($this->scanRoot . '/b.php', $twin('beta', 2));
        $loop->firePeriodic(); // hint tick sees it
        $loop->fireTimers();   // the debounce window expires → one flush

        $this->assertCount(2, $counter->passes, 'the flushed event re-ran the pipeline');
        $this->assertGreaterThan($counter->passes[0]['files'], $counter->passes[1]['files'], 'the job count moved with the tree');
        $this->assertSame(2, $counter->passes[1]['files']);
        $this->assertGreaterThan($counter->passes[0]['blocks'], $counter->passes[1]['blocks'], 'the new twin contributed real extracted blocks');

        // One fetch: the change line and the honest new announce share the
        // window's render (BufferedOutput::fetch drains what it returns).
        $second = $output->fetch();
        $this->assertStringContainsString('change detected (created:', $second);
        $this->assertStringContainsString('watching 2 files · last update', $second);
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

/**
 * once-S94: the real-chain observer. A terminal stage of a genuine
 * Scanning→Preprocess→Cluster pipeline that records, per dispatch, the
 * counts the REAL analysis produced. Its `passes` list is the acceptance
 * surface: an event that only re-enters a scripted counter leaves these
 * numbers alone; a real re-analysis moves them.
 */
final class WatchCountStage implements StageInterface
{
    /** @var list<array{files:int, blocks:int, clusters:int}> */
    public array $passes = [];

    public function name(): Stage
    {
        return Stage::Reporting;
    }

    public function run(PipelineState $state, OutputInterface $output): void
    {
        $this->passes[] = [
            'files'    => count($state->files),
            'blocks'   => count($state->blocks),
            'clusters' => count($state->clusters),
        ];
    }
}

/**
 * One-protocol stream wrapper whose every stat raises — the once-S08 fix
 * r1 rebase forcing (see testRebaseThrowKeepsWatcherAliveAndCountsToward
 * Streak). WatchRunner's FileWatcher treats "<protocol>://root" as its scan
 * root, so the FIRST filesystem touch inside FileScanner::scan() (the
 * is_dir gate) runs this url_stat, and PHP 8 lets the exception escape the
 * fs call into the dispatch guard. Nothing else is reachable: the walk
 * never starts, no real path is ever named. The protocol is registered for
 * the lifetime of one test and unregistered in its finally block.
 */
final class PoisonedRebaseWrapper
{
    public const PROTOCOL = 'phpduprebase';

    /** @var resource|null stream context — property required by the wrapper API */
    public $context;

    public function url_stat(string $path, int $flags): array|false
    {
        throw new \RuntimeException('poisoned rebase enumeration');
    }
}

/**
 * Deterministic LoopInterface double for the once-S47 consumer pins. The
 * classes declared inside FileWatcherTest.php are not autoloadable from
 * here (tests autoload is PSR-4, no classmap), so this file stays
 * self-contained like every other test double in this suite — the twin of
 * WatchTestLoop under a collision-free name, with the same fire-by-hand
 * semantics (one-shot tracking is the debounce window's observable).
 */
final class CoalescingTestTimer implements TimerInterface
{
    public bool $cancelled = false;

    /** @param callable $callback */
    public function __construct(
        private readonly float $interval,
        private $callback,
        private readonly bool $periodic,
    ) {}

    public function getInterval(): float
    {
        return $this->interval;
    }

    /** @return callable */
    public function getCallback()
    {
        return $this->callback;
    }

    public function isPeriodic(): bool
    {
        return $this->periodic;
    }
}

final class CoalescingTestLoop implements LoopInterface
{
    /** @var list<CoalescingTestTimer> */
    private array $oneShot = [];

    /** @var list<CoalescingTestTimer> */
    private array $periodic = [];

    public function firePeriodic(): void
    {
        foreach ($this->periodic as $timer) {
            if (!$timer->cancelled) {
                ($timer->getCallback())();
            }
        }
    }

    /** Fire all armed one-shot timers once (then forget them, like a real loop). */
    public function fireTimers(): void
    {
        $due = $this->oneShot;
        $this->oneShot = [];
        foreach ($due as $timer) {
            if (!$timer->cancelled) {
                ($timer->getCallback())();
            }
        }
    }

    public function countPendingOneShots(): int
    {
        return count($this->oneShot);
    }

    public function addTimer($interval, $callback): TimerInterface
    {
        $timer = new CoalescingTestTimer((float)$interval, $callback, false);
        $this->oneShot[] = $timer;
        return $timer;
    }

    public function addPeriodicTimer($interval, $callback): TimerInterface
    {
        $timer = new CoalescingTestTimer((float)$interval, $callback, true);
        $this->periodic[] = $timer;
        return $timer;
    }

    public function cancelTimer(TimerInterface $timer): void
    {
        if ($timer instanceof CoalescingTestTimer) {
            $timer->cancelled = true;
        }
        $this->oneShot = array_values(array_filter(
            $this->oneShot,
            static fn(CoalescingTestTimer $t): bool => $t !== $timer,
        ));
        $this->periodic = array_values(array_filter(
            $this->periodic,
            static fn(CoalescingTestTimer $t): bool => $t !== $timer,
        ));
    }

    public function addReadStream($stream, $listener): void {}
    public function addWriteStream($stream, $listener): void {}
    public function removeReadStream($stream): void {}
    public function removeWriteStream($stream): void {}
    public function futureTick($listener): void {}
    public function addSignal($signal, $listener): void {}
    public function removeSignal($signal, $listener): void {}
    public function run(): void {}
    public function stop(): void {}
}
