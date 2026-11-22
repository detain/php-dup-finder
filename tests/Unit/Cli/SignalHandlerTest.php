<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Cli;

use Phpdup\Cli\Config;
use Phpdup\Cli\SignalHandler;
use Phpdup\Pipeline\Pipeline;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Watch\FileChangeType;
use Phpdup\Watch\WatchRunner;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * once-S75 (php-dup-finder-once-090): the scoped SIGINT contract.
 *
 * Every signal DELIVERY test runs in a forked child so an exit(130) can
 * never kill the phpunit worker, and so a broken contract has to show up
 * as a wrong child exit code (red) instead of collateral damage. The
 * box running CI has pcntl + posix + fork; the skips below are
 * portability statements for pcntl-less machines, never evasions.
 *
 * Arms pinned here, one named test each (red-before-green per the
 * once-S75 brief):
 *   - scope opens (async armed, disposition replaced):
 *       testScopeArmsAsyncAndReplacesDispositionInsideTheScope
 *   - scope closes (async + handler return to as-found — the plan's
 *       literal "pcntl_async_signals(false) after scope, handler
 *       restored" — and the S93 leave-as-found dependency):
 *       testAsyncModeAndHandlerReturnToAsFoundAfterScope
 *   - first press = cooperative sink (state or closure), process lives:
 *       testFirstPressCancelsPipelineStateNotProcess
 *       testFirstPressInvokesClosureSinkExactlyOnce
 *   - second press exits 130 NOW (within one loop iteration, never
 *       after a third press — the old false docblock):
 *       testSecondPressExits130ImmediatelyNotAfterAThird
 *   - press counts are per-scope (the old process-global $count bug):
 *       testPressCountsArePerScopeNotProcessGlobal
 *   - the second press reaps direct children before dying (once-S74
 *       no-orphan-children precedent):
 *       testSecondPressReapsDirectChildrenWithinBound
 *   - the TUI layering contract (scope stacked over sugar-core Program's
 *       own arm, entry snapshot gets the last word):
 *       testCaptureEnvironmentGivesTheLastWordOverStackedArms
 *   - restorers are idempotent (double-close safe):
 *       testRestorersAreIdempotentUnderDoubleClose
 *   - the watch gate boots no new pass behind an unwinding loop:
 *       testWatchGateBootsNoNewPassAfterCancelRequest
 */
final class SignalHandlerTest extends TestCase
{
    private function requireSignals(): void
    {
        if (
            !function_exists('pcntl_signal')
            || !function_exists('pcntl_signal_get_handler')
            || !function_exists('pcntl_fork')
            || !function_exists('posix_kill')
            || !function_exists('posix_getpid')
        ) {
            $this->markTestSkipped('pcntl/posix unavailable — signal contract cannot be exercised here');
        }
    }

    /**
     * Run $child in a forked process and reap it. Never returns in the
     * child: whatever code the child reaches with exit() (or the 99
     * fall-through sentinel) is the answer the parent asserts on.
     *
     * @return array{code:?int, signal:?int, elapsed:float}
     */
    private function forkChild(\Closure $child): array
    {
        $before = microtime(true);
        $pid    = pcntl_fork();
        if ($pid === -1) {
            $this->fail('pcntl_fork failed — the contract cannot be observed');
        }
        if ($pid === 0) {
            $child();
            exit(99); // sentinel: the child fell through the contract
        }
        $status = 0;
        pcntl_waitpid($pid, $status);
        return [
            'code'    => pcntl_wifexited($status) ? pcntl_wexitstatus($status) : null,
            'signal'  => pcntl_wifsignaled($status) ? pcntl_wtermsig($status) : null,
            'elapsed' => microtime(true) - $before,
        ];
    }

    private function freshState(): PipelineState
    {
        return new PipelineState(Config::defaults([sys_get_temp_dir()]));
    }

    // ── scope opens / closes ───────────────────────────────────────────

    public function testScopeArmsAsyncAndReplacesDispositionInsideTheScope(): void
    {
        $this->requireSignals();

        $file = tempnam(sys_get_temp_dir(), 's75-arm-');
        self::assertIsString($file);

        $outcome = $this->forkChild(function () use ($file): void {
            $entryDisp = pcntl_signal_get_handler(SIGINT);
            $restore   = SignalHandler::install($this->freshState());
            $inAsync   = pcntl_async_signals();
            $inDisp    = pcntl_signal_get_handler(SIGINT);
            $restore();
            file_put_contents($file, json_encode([
                // A fresh process walks in with the OS default (int 0).
                'entryDefault' => $entryDisp === 0,
                // Inside the scope async dispatch is live and the
                // disposition is OURS (a callable), not the default.
                'inAsync'      => $inAsync === true,
                'inScoped'     => is_callable($inDisp) && !is_int($inDisp),
            ]));
            exit(0);
        });

        $this->assertSame(0, $outcome['code'], 'child completed the arm probe');
        $seen = json_decode((string) file_get_contents($file), true);
        unlink($file);

        $this->assertTrue($seen['entryDefault'], 'a fresh process starts at the SIGINT default (probe sanity)');
        $this->assertTrue($seen['inAsync'], 'install() switches async dispatch on INSIDE the scope');
        $this->assertTrue($seen['inScoped'], 'install() replaces the SIGINT disposition with the scope handler');
    }

    public function testAsyncModeAndHandlerReturnToAsFoundAfterScope(): void
    {
        $this->requireSignals();

        $file = tempnam(sys_get_temp_dir(), 's75-close-');
        self::assertIsString($file);

        $outcome = $this->forkChild(function () use ($file): void {
            $entryAsync = pcntl_async_signals();
            $entryDisp  = pcntl_signal_get_handler(SIGINT);
            $restore    = SignalHandler::install($this->freshState());
            $restore();
            $postAsync = pcntl_async_signals();
            $postDisp  = pcntl_signal_get_handler(SIGINT);
            // A second close must not flip anything again.
            $restore();
            file_put_contents($file, json_encode([
                // The plan's literal acceptance: async mode is FALSE
                // again after the scope closes, and the disposition the
                // caller found is back (S93 depends on this as-found).
                'asyncWasOff'   => $entryAsync === false,
                'asyncRestored' => $postAsync === false,
                'dispRestored'  => $postDisp === $entryDisp,
                'stillRestored' => pcntl_async_signals() === false && pcntl_signal_get_handler(SIGINT) === $entryDisp,
            ]));
            exit(0);
        });

        $this->assertSame(0, $outcome['code'], 'child completed the close probe');
        $seen = json_decode((string) file_get_contents($file), true);
        unlink($file);

        $this->assertTrue($seen['asyncWasOff'], 'probe sanity: a fresh process has async off');
        $this->assertTrue($seen['asyncRestored'], 'pcntl_async_signals(false) after scope — the restorer returns async mode to as-found');
        $this->assertTrue($seen['dispRestored'], 'handler restored: the scope leaves the disposition it found');
        $this->assertTrue($seen['stillRestored'], 'idempotent restorer: the second close changes nothing');
    }

    // ── first press: cooperative sink, process lives ───────────────────

    public function testFirstPressCancelsPipelineStateNotProcess(): void
    {
        $this->requireSignals();

        $outcome = $this->forkChild(function (): void {
            $state   = $this->freshState();
            $restore = SignalHandler::install($state);
            posix_kill(posix_getpid(), SIGINT);
            $cancelled = $state->cancelled;
            $restore();
            exit($cancelled ? 0 : 91); // 91 = sink never ran
        });

        $this->assertSame(
            0,
            $outcome['code'],
            'first SIGINT flips PipelineState->cancelled and lets the process live '
            . '(91 = handler never cancelled, 130 = it exited too early, 99 = fell through)'
        );
    }

    public function testFirstPressInvokesClosureSinkExactlyOnce(): void
    {
        $this->requireSignals();

        $outcome = $this->forkChild(function (): void {
            $hits    = 0;
            $restore = SignalHandler::install(static function (int $signo) use (&$hits): void {
                $hits += $signo === SIGINT ? 1 : 0;
            });
            posix_kill(posix_getpid(), SIGINT);
            $seen = $hits;
            $restore();
            exit($seen === 1 ? 0 : 92); // 92 = sink fired 0 or 2+ times
        });

        $this->assertSame(
            0,
            $outcome['code'],
            'closure sinks receive the first press exactly once, with the signal number '
            . '(92 = wrong fire count, 130 = second-press path fired on a single press)'
        );
    }

    // ── second press: exits now, honestly, and leaves no orphans ───────

    public function testSecondPressExits130ImmediatelyNotAfterAThird(): void
    {
        $this->requireSignals();

        $outcome = $this->forkChild(function (): void {
            $restore = SignalHandler::install($this->freshState());
            posix_kill(posix_getpid(), SIGINT); // press 1 — cooperative
            posix_kill(posix_getpid(), SIGINT); // press 2 — must never return
            exit(94);                            // 94 = second press did not exit (the old bug)
        });

        $this->assertSame(
            130,
            $outcome['code'],
            'the second SIGINT exits 130 from inside the scope — the old contract died only on a THIRD press (94), the old asyncless shape was killed by signal (signal arm), laundering success would be 0'
        );
        $this->assertNull($outcome['signal'], 'exit 130 is OUR honest code, not an uncaught signal death');
        $this->assertLessThan(2.0, $outcome['elapsed'], 'second press lands within one loop iteration — exit now, not after a delayed stop');
    }

    public function testPressCountsArePerScopeNotProcessGlobal(): void
    {
        $this->requireSignals();

        $outcome = $this->forkChild(function (): void {
            $a       = $this->freshState();
            $closeA  = SignalHandler::install($a);
            posix_kill(posix_getpid(), SIGINT); // press 1 of scope A
            $closeA();

            $b      = $this->freshState();
            $closeB = SignalHandler::install($b);
            posix_kill(posix_getpid(), SIGINT); // press 1 of scope B — must NOT inherit A's count
            $closeB();

            exit(($a->cancelled && $b->cancelled) ? 7 : 95); // 95 = a sink missed
        });

        $this->assertSame(
            7,
            $outcome['code'],
            'a fresh scope starts its own press count — with the old process-global counter this child had already spent its first press and died 130 on B press 1 (130), never 7'
        );
    }

    public function testSecondPressReapsDirectChildrenWithinBound(): void
    {
        $this->requireSignals();

        $pidFile = tempnam(sys_get_temp_dir(), 's75-reap-');
        self::assertIsString($pidFile);

        $outcome = $this->forkChild(function () use ($pidFile): void {
            $restore = SignalHandler::install($this->freshState());

            $kid = pcntl_fork();
            if ($kid === -1) {
                exit(97); // fork failure sentinel — parent must notice
            }
            if ($kid === 0) {
                usleep(20_000_000); // 20 s sleeper: outlives every generous bound below
                exit(11);
            }

            file_put_contents($pidFile, json_encode(['h' => posix_getpid(), 'c' => $kid]));
            posix_kill(posix_getpid(), SIGINT);
            posix_kill(posix_getpid(), SIGINT);
            exit(98);
        });

        $this->assertSame(0, $outcome['code'] === 97 ? 97 : 0, 'the helper forked its child (97 = fork failed)');
        $this->assertSame(130, $outcome['code'], 'the armed helper died via the second-press contract');

        $pids = json_decode((string) file_get_contents($pidFile), true);
        unlink($pidFile);
        self::assertIsArray($pids);
        $kid = (int) $pids['c'];

        // The helper exited already; its SIGKILL sweep + bounded drain ran
        // before that exit. Census the orphan window NOW.
        $deadline = microtime(true) + 2.0;
        $alive    = true;
        while (microtime(true) < $deadline) {
            $alive = posix_kill($kid, 0);
            if (!$alive) {
                break;
            }
            usleep(10_000);
        }

        $this->assertFalse(
            $alive,
            'no-orphan-children (once-S74 precedent): the second press must SIGKILL direct children '
            . 'before exit — the 20 s sleeper is still alive after 2 s, so the sweep never ran'
        );

        // Do not reap the (already dead) kid ourselves twice; harmless if gone.
        if (function_exists('pcntl_waitpid')) {
            $status = 0;
            pcntl_waitpid($kid, $status, WNOHANG);
        }
    }

    // ── the TUI layering surface ───────────────────────────────────────

    public function testCaptureEnvironmentGivesTheLastWordOverStackedArms(): void
    {
        $this->requireSignals();

        $entryDisp  = pcntl_signal_get_handler(SIGINT);
        $entryAsync = pcntl_async_signals();

        $foreign = static function (): void {
        }; // stands in for sugar-core Program's arm

        try {
            pcntl_signal(SIGINT, $foreign);
            pcntl_async_signals(false);

            $outer  = SignalHandler::captureEnvironment(); // TuiRunner entry snapshot
            $scoped = SignalHandler::install($this->freshState()); // timer scope stacked over the foreign arm

            $this->assertTrue(pcntl_async_signals(), 'the stacked scope owns async while open');
            $this->assertNotSame($foreign, pcntl_signal_get_handler(SIGINT), 'the stacked scope displaces the foreign arm');

            $scoped();
            $this->assertSame($foreign, pcntl_signal_get_handler(SIGINT), 'stacked scope unwinds to the arm it covered (LIFO)');
            $this->assertTrue(pcntl_async_signals(), 'inner scope returns async to what the foreign owner had set');

            $outer();
            $this->assertSame($entryDisp, pcntl_signal_get_handler(SIGINT), 'the entry snapshot — last word — leaves the process exactly as TuiRunner found it');
            $this->assertSame($entryAsync, pcntl_async_signals(), 'and async mode too (the S93 leave-as-found dependency)');
        } finally {
            pcntl_signal(SIGINT, is_int($entryDisp) ? $entryDisp : SIG_DFL);
            pcntl_async_signals($entryAsync);
        }
    }

    public function testRestorersAreIdempotentUnderDoubleClose(): void
    {
        $this->requireSignals();

        $entryDisp  = pcntl_signal_get_handler(SIGINT);
        $entryAsync = pcntl_async_signals();

        try {
            $scoped = SignalHandler::install($this->freshState());
            $scoped();
            $scoped();
            $scoped();
            $this->assertSame($entryDisp, pcntl_signal_get_handler(SIGINT), 're-calling the install restorer changes nothing further');
            $this->assertSame($entryAsync, pcntl_async_signals(), '...including async mode');

            $outer = SignalHandler::captureEnvironment();
            $outer();
            $outer();
            $this->assertSame($entryDisp, pcntl_signal_get_handler(SIGINT), 're-calling the capture restorer changes nothing further');
        } finally {
            pcntl_signal(SIGINT, is_int($entryDisp) ? $entryDisp : SIG_DFL);
            pcntl_async_signals($entryAsync);
        }
    }

    // ── the watch gate (run()-side leg, observable without the loop) ───

    public function testWatchGateBootsNoNewPassAfterCancelRequest(): void
    {
        $built  = 0;
        $runner = new WatchRunner(
            new Pipeline([]),
            static function () use (&$built): PipelineState {
                $built++;
                return new PipelineState(Config::defaults([sys_get_temp_dir()]));
            },
            new BufferedOutput(),
            new NullLogger(),
            sys_get_temp_dir(),
        );

        // Gate closed: an event boots exactly one pass (baseline so the
        // pin below cannot pass vacuously on a dead counter).
        $runner->handleChange('a.php', FileChangeType::Modified, []);
        $this->assertSame(1, $built, 'uncancelled events dispatch normally (probe sanity)');

        // The once-S75 sink sets this on the first press while the loop is
        // unwinding; no NEW pass may boot behind it.
        $gate = new \ReflectionProperty(WatchRunner::class, 'cancelRequested');
        $gate->setAccessible(true);
        $gate->setValue($runner, true);

        $runner->handleChange('b.php', FileChangeType::Modified, []);
        $this->assertSame(
            1,
            $built,
            'after cancelRequested the watch gate boots no new pass — without the early return this is 2'
        );
    }
}
