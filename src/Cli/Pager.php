<?php
declare(strict_types=1);

namespace Phpdup\Cli;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Wraps an OutputInterface so that, when the user wants paging,
 * the captured output is piped through `$PAGER` (default `less -R`)
 * after the underlying renderer finishes.
 *
 * Modes:
 *   - 'auto'    pipe through pager when stdout is a TTY *and* the
 *               buffered output exceeds {@see THRESHOLD_LINES} lines.
 *   - 'always'  always pipe through pager.
 *   - 'never'   pass-through (no buffering, no pager spawn).
 */
final class Pager
{
    /** Trigger threshold for 'auto' mode: roughly one screenful + scrollback. */
    public const THRESHOLD_LINES = 60;

    public const MODE_AUTO   = 'auto';
    public const MODE_ALWAYS = 'always';
    public const MODE_NEVER  = 'never';

    /** @var list<string> */
    public const MODES = [self::MODE_AUTO, self::MODE_ALWAYS, self::MODE_NEVER];

    /**
     * Decide whether the given mode warrants paging given the live
     * environment.  Centralised so the CLI Command and ReportStage can
     * agree on the policy.
     */
    public static function shouldPage(string $mode, ?int $bufferedLines = null): bool
    {
        if ($mode === self::MODE_NEVER)  return false;
        if ($mode === self::MODE_ALWAYS) return true;
        // 'auto': require a TTY on stdout and a buffer big enough.
        if (!self::stdoutIsTty()) return false;
        if ($bufferedLines === null) return true;
        return $bufferedLines >= self::THRESHOLD_LINES;
    }

    /**
     * Pipe $payload through `$PAGER` (or `less -R`).  Falls back to
     * writing the payload to $output verbatim if exec is unavailable
     * or the pager fails to launch — better to render than to error.
     */
    public static function send(string $payload, OutputInterface $output): void
    {
        $pager = (string)(getenv('PAGER') ?: 'less -R');
        // If less is the pager, ensure -R (preserve ANSI) is set so
        // colours pass through.
        if (str_starts_with(trim($pager), 'less') && !str_contains($pager, '-R')) {
            $pager .= ' -R';
        }

        if (!function_exists('proc_open')) {
            $output->write($payload);
            return;
        }
        $argv = preg_split('/\s+/', $pager, -1, PREG_SPLIT_NO_EMPTY);
        if ($argv === false || $argv === []) {
            $output->write($payload);
            return;
        }
        $proc = @proc_open(
            $argv,
            [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR],
            $pipes,
        );
        if (!is_resource($proc)) {
            $output->write($payload);
            return;
        }
        @fwrite($pipes[0], $payload);
        @fclose($pipes[0]);
        @proc_close($proc);
    }

    private static function stdoutIsTty(): bool
    {
        if (function_exists('stream_isatty')) {
            return @stream_isatty(STDOUT);
        }
        return function_exists('posix_isatty') ? @posix_isatty(STDOUT) : false;
    }
}
