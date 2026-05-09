<?php
declare(strict_types=1);

namespace Phpdup\Cli;

use Phpdup\Pipeline\PipelineState;

/**
 * SIGINT (Ctrl+C) handler that flips PipelineState::$cancelled so
 * cooperative stages can exit cleanly with whatever clusters they've
 * produced so far — instead of crashing out and losing partial work.
 *
 * On the second SIGINT, the handler raises the default signal so a
 * truly stuck process can still be killed.
 */
final class SignalHandler
{
    private static int $count = 0;

    public static function install(PipelineState $state): void
    {
        if (!function_exists('pcntl_signal')) {
            return;
        }
        @pcntl_async_signals(true);
        @pcntl_signal(SIGINT, static function (int $signo) use ($state): void {
            self::$count++;
            $state->cancelled = true;
            if (self::$count >= 2) {
                // Restore default disposition so the second ^C kills us.
                pcntl_signal(SIGINT, SIG_DFL);
            }
        });
    }

    /** Restore the default disposition (used by tests / cleanup). */
    public static function uninstall(): void
    {
        if (function_exists('pcntl_signal')) {
            @pcntl_signal(SIGINT, SIG_DFL);
        }
        self::$count = 0;
    }
}
