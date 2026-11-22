<?php
declare(strict_types=1);

namespace Phpdup\Cli;

use Phpdup\Pipeline\PipelineState;

/**
 * Scoped SIGINT (Ctrl+C) contract for cooperative runs (once-S75,
 * php-dup-finder-once-090).
 *
 * install() arms a per-scope disposition and hands back a restorer that
 * closes EXACTLY what it opened: the SIGINT disposition and the
 * pcntl_async_signals mode return to their as-found values when the
 * caller's scope ends. Press counts are per-scope — a fresh install never
 * inherits a stale "one press already spent" from a previous run, and no
 * scope silently leaves async dispatch switched on for the suite.
 *
 * Inside a scope:
 *   - first SIGINT  → the sink fires: a PipelineState gets ->cancelled =
 *     true, a closure sink is invoked. Stages that poll the flag unwind
 *     and the caller renders whatever partial work survives.
 *   - second SIGINT → the process dies NOW: every direct child is
 *     SIGKILLed (no orphaned pool workers or watch children), then
 *     exit(130) — the canonical SIGINT code the CLI has carried since
 *     S24. A signal-driven exit never launders into success-exit-0.
 *
 * Where /proc is absent (non-Linux), the reaper cannot enumerate the
 * child table; the second press then restores SIG_DFL and re-raises, so
 * the death still reports 130 and the children are inherited by init —
 * a bounded, documented fallback.
 */
final class SignalHandler
{
    /**
     * Arm the scoped SIGINT contract for one call site.
     *
     * @param PipelineState|\Closure(int):void $onCancel first-press sink
     *
     * @return \Closure idempotent restorer — closes the scope, returns
     *                  the signal environment to as-found
     */
    public static function install(PipelineState|\Closure $onCancel): \Closure
    {
        if (!function_exists('pcntl_signal')) {
            return static function (): void {
            };
        }

        $prevHandler = function_exists('pcntl_signal_get_handler')
            ? pcntl_signal_get_handler(SIGINT)
            : SIG_DFL;
        // pcntl_async_signals() with an argument returns the PREVIOUS mode
        // (measured on 8.3): this is the as-found capture, not a guess.
        $prevAsync = pcntl_async_signals(true);

        $presses = 0;
        pcntl_signal(SIGINT, static function (int $signo) use (&$presses, $onCancel): void {
            $presses++;
            if ($presses >= 2) {
                self::reapDirectChildren();
                exit(130);
            }
            if ($onCancel instanceof PipelineState) {
                $onCancel->cancelled = true;
                return;
            }
            $onCancel($signo);
        });

        $restored = false;
        return static function () use (&$restored, $prevHandler, $prevAsync): void {
            if ($restored) {
                return;
            }
            $restored = true;
            pcntl_signal(SIGINT, self::normalizeDisposition($prevHandler));
            pcntl_async_signals($prevAsync);
        };
    }

    /**
     * Snapshot the current SIGINT disposition + async mode and hand back
     * an idempotent restorer for that snapshot. Callers that stack a scope
     * over a THIRD-PARTY arm — the TUI's sugar-core Program installs its
     * own SIGINT handler inside run() — use this to guarantee the process
     * returns to its entry environment no matter how the layers unwind.
     */
    public static function captureEnvironment(): \Closure
    {
        if (!function_exists('pcntl_signal')) {
            return static function (): void {
            };
        }

        $handler = function_exists('pcntl_signal_get_handler')
            ? pcntl_signal_get_handler(SIGINT)
            : SIG_DFL;
        $async = pcntl_async_signals();

        $restored = false;
        return static function () use (&$restored, $handler, $async): void {
            if ($restored) {
                return;
            }
            $restored = true;
            pcntl_signal(SIGINT, self::normalizeDisposition($handler));
            pcntl_async_signals($async);
        };
    }

    /**
     * SIGKILL every direct child, then briefly drain the zombies so a
     * process census taken at our exit sees an empty child table
     * (once-S74 no-orphan-children precedent).
     */
    private static function reapDirectChildren(): void
    {
        $self = function_exists('posix_getpid') ? posix_getpid() : getmypid();
        if ($self === false || $self === null || $self <= 0) {
            return;
        }

        if (!is_dir('/proc') || !function_exists('posix_kill')) {
            // Cannot enumerate (or signal) the child table: die by signal
            // instead — the shell still reports 130 and init adopts the
            // children. Bounded fallback, documented at the class head.
            pcntl_signal(SIGINT, SIG_DFL);
            if (function_exists('posix_kill')) {
                posix_kill($self, SIGINT);
            }
            exit(130);
        }

        $dirs = glob('/proc/[0-9]*') ?: [];
        foreach ($dirs as $dir) {
            $stat = $dir . '/stat';
            $bytes = is_readable($stat) ? @file_get_contents($stat) : false;
            if (!is_string($bytes)) {
                continue; // vanished mid-scan — the race a reaper lives with
            }
            // stat(2): "pid (comm) STATE PPID …" — comm may hold spaces and
            // parens, so field 3 starts only after the LAST ')'.
            $close = strrpos($bytes, ')');
            if ($close === false) {
                continue;
            }
            $fields = explode(' ', substr($bytes, $close + 1));
            // $fields[0] = state, $fields[1] = PPID.
            if (($fields[1] ?? '') !== (string) $self) {
                continue;
            }
            $child = (int) basename($dir);
            if ($child <= 0 || $child === $self) {
                continue;
            }
            @posix_kill($child, SIGKILL);
        }

        // Bounded wait so our exiting process leaves no zombies behind its
        // killed children even for a census taken one read() later:
        // waitpid(..., WNOHANG) answers 0 while a child is still dying and
        // -1 once the table is empty — poll until one of the two, capped.
        if (function_exists('pcntl_waitpid')) {
            $status   = 0;
            $deadline = microtime(true) + 0.2;
            while (microtime(true) < $deadline) {
                $gone = pcntl_waitpid(-1, $status, WNOHANG);
                if ($gone === -1) {
                    break; // no children left — table drained
                }
                if ($gone === 0) {
                    usleep(2_000); // one of ours still dying — keep polling
                }
            }
        }
    }

    /**
     * Normalize whatever pcntl_signal_get_handler() reported (measured on
     * 8.3: int 0/1 for DFL/IGN, the callable itself — incl. React's
     * [object,'method'] array form — for userland arms) into an argument
     * pcntl_signal() accepts.
     *
     * @param mixed $disposition
     */
    private static function normalizeDisposition(mixed $disposition): int|callable
    {
        if (is_int($disposition)) {
            return $disposition;
        }
        if ($disposition === true) {
            return SIG_IGN; // legacy SIG_IGN shape (<= 8.0 stubs)
        }
        if (is_callable($disposition)) {
            return $disposition;
        }
        return SIG_DFL;
    }
}
