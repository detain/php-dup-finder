<?php
declare(strict_types=1);

namespace Phpdup\Cli;

use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Wraps an OutputInterface so that, when the user wants paging,
 * the captured output is piped through `$PAGER` (default `less -R`)
 * after the underlying renderer finishes.
 *
 * Modes (as consulted through {@see shouldPage}, enforced at the single
 * spawn choke-point {@see send}):
 *   - 'auto'    pipe through pager when stdout is a TTY *and* the
 *               buffered output exceeds {@see THRESHOLD_LINES} lines.
 *   - 'always'  the user's page request stands regardless of buffer
 *               size; the TTY honesty gate is enforced in send() so a
 *               non-terminal stream is NEVER fed to a pager child
 *               (once-S76 / php-dup-finder-once-092: paging into a
 *               pipe or file used to write raw escape/pager content
 *               into captured output).
 *   - 'never'   pass-through (no buffering, no pager spawn).
 *
 * Integrity posture (once-S76, three legs):
 *   1. No silent truncation — the payload is handed over in a
 *      short-write loop and the pager is reaped through proc_close's
 *      exit status; if the pager dies or stops reading early, exactly
 *      how many bytes were lost and with what status is disclosed on
 *      the error stream and the undelivered tail is written unpaged
 *      (a report >64 KiB — one Linux pipe buffer — used to vanish
 *      silently behind a discarded @fwrite return).
 *   2. No TTY writes — send() refuses to spawn anything when stdout
 *      is not a terminal and the caller pinned us there anyway; the
 *      refusal is disclosed, never silent. Detection rides the ONE
 *      stream_isatty/posix_isatty policy below (the same policy
 *      Command::stdoutIsTerminal() mirrors since once-S22 — no second
 *      parallel tty heuristic exists in the binary).
 *   3. Honest args — the $PAGER command is tokenized with shell-style
 *      quoting (preg_split('/\s+/') used to shred quoted arguments and
 *      could not restore them), and the `-R` pass-through flag is
 *      detected per argv token with word boundaries against the real
 *      pager binary (`less`) only — `vim -R` is a read-only vim, not
 *      a less that already has raw mode.
 *
 * Layering note (once-S06 seam): the TUI dispatch pins pagerMode to
 * MODE_NEVER Command-side and ReportStage only reaches send() through
 * a ConsoleOutputInterface sink; the gates here are the spawn-point
 * defense under that same seam, not a replacement for it.
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

    /** Pager command assumed when $PAGER is not present in the environment at all. */
    public const DEFAULT_COMMAND = 'less -R';

    /**
     * Decide whether the given mode warrants paging given the live
     * environment.  Centralised so the CLI Command and ReportStage can
     * agree on the policy.
     *
     * The optional $stdoutIsTty is a hermetic test seam: null consults
     * the live STDOUT stream, true/false overrides for unit pins. It
     * deliberately gates ONLY the 'auto' heuristic here — 'always' is a
     * recorded user request whose spawn-time honesty send() enforces —
     * so the existing "always pages" policy arms keep their contract.
     */
    public static function shouldPage(string $mode, ?int $bufferedLines = null, ?bool $stdoutIsTty = null): bool
    {
        if ($mode === self::MODE_NEVER)  return false;
        if ($mode === self::MODE_ALWAYS) return true;
        // 'auto': require a TTY on stdout and a buffer big enough.
        if (!($stdoutIsTty ?? self::stdoutIsTty())) return false;
        if ($bufferedLines === null) return true;
        return $bufferedLines >= self::THRESHOLD_LINES;
    }

    /**
     * Pipe $payload through $PAGER (or {@see DEFAULT_COMMAND}).
     *
     * Every degradation from the ideal page is DISCLOSED, never silent:
     * no terminal (refusal), unusable command (empty $PAGER, missing
     * proc_open, failed spawn), short write + exit status (early-exiting
     * pager). In each case the report bytes still reach the caller's
     * stream in full; only the paging is given up, and the error stream
     * says so. proc_close reaps the child on every path that spawned.
     *
     * $stdoutIsTty is the same hermetic seam as in shouldPage().
     */
    public static function send(string $payload, OutputInterface $output, ?bool $stdoutIsTty = null): void
    {
        if (!($stdoutIsTty ?? self::stdoutIsTty())) {
            // once-092 leg 2: a pager child writes terminal control and
            // pass-through content onto OUR stdout (fd 1). When fd 1 is a
            // pipe or file that is no longer paging — it is corrupting a
            // captured artifact. Refuse, disclose, write the bytes direct.
            self::disclose($output, 'phpdup: pager requested but stdout is not a terminal — wrote the report unpaged.');
            $output->write($payload);
            return;
        }

        $env = getenv('PAGER');
        $command = $env === false ? self::DEFAULT_COMMAND : $env;
        if (trim($command) === '') {
            // once-092 leg 3: an explicitly empty $PAGER is a user statement,
            // not an unset one — say so instead of silently substituting less.
            self::disclose($output, 'phpdup: $PAGER is set but empty — wrote the report unpaged.');
            $output->write($payload);
            return;
        }

        $argv = self::resolvePagerCommand($command);
        if ($argv === []) {
            self::disclose($output, 'phpdup: $PAGER command "' . $command . '" carries no executable — wrote the report unpaged.');
            $output->write($payload);
            return;
        }

        if (!function_exists('proc_open')) {
            self::disclose($output, 'phpdup: proc_open is unavailable — wrote the report unpaged.');
            $output->write($payload);
            return;
        }

        $proc = @proc_open(
            $argv,
            [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR],
            $pipes,
        );
        if (!is_resource($proc)) {
            self::disclose($output, 'phpdup: cannot launch pager "' . $command . '" — wrote the report unpaged.');
            $output->write($payload);
            return;
        }

        // once-092 leg 1: the old code did @fwrite($pipes[0], $payload) and
        // discarded both the byte count and proc_close's status. A report
        // beyond the ~64 KiB pipe buffer depends on the loop below: PHP's
        // streams ignore SIGPIPE around writes, so an early-exiting pager
        // makes fwrite return 0/false once its stdin reader is gone —
        // exactly the truncation point, which we then disclose honestly.
        $length = strlen($payload);
        $written = 0;
        while ($written < $length) {
            $chunk = @fwrite($pipes[0], substr($payload, $written));
            if ($chunk === false || $chunk === 0) {
                break;
            }
            $written += $chunk;
        }
        @fclose($pipes[0]);
        $status = proc_close($proc);

        if ($written < $length) {
            self::disclose($output, sprintf(
                'phpdup: pager "%s" stopped after %d of %d bytes (exit %d) — wrote the remaining %d bytes unpaged.',
                $command,
                $written,
                $length,
                $status,
                $length - $written,
            ));
            // The child is reaped above; fd 1 is ours alone again, so the
            // undelivered tail can still reach the consumer complete.
            $output->write(substr($payload, $written));
            return;
        }
        if ($status !== 0) {
            self::disclose($output, sprintf(
                'phpdup: pager "%s" exited with status %d after receiving the full report.',
                $command,
                $status,
            ));
        }
    }

    /**
     * Tokenize a shell-style command line into an argv list (once-092 leg 3).
     *
     * Replaces `preg_split('/\s+/', $pager)`, which split INSIDE quoted
     * arguments — `PAGER="'/my less' -R"` became ["'/my", "less'", '-R']
     * and no consumer could recover the space. Rules, mirroring POSIX
     * word splitting minus expansion: single quotes are literal, double
     * quotes honour backslash escapes, a backslash outside quotes escapes
     * the next character, quoted and bare sections concatenate into ONE
     * token (`a"b"c` -> `abc`), and an unterminated quote keeps its whole
     * remainder (lossless — bytes are never dropped by the splitter).
     *
     * @return list<string>
     */
    public static function parseCommand(string $command): array
    {
        $tokens = [];
        $current = null;
        $length = strlen($command);
        $i = 0;
        while ($i < $length) {
            $char = $command[$i];
            if ($char === ' ' || $char === "\t") {
                if ($current !== null) {
                    $tokens[] = $current;
                    $current = null;
                }
                $i++;
                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
                $content = '';
                $i++;
                while ($i < $length) {
                    $inner = $command[$i];
                    if ($quote === '"' && $inner === '\\' && $i + 1 < $length
                        && ($command[$i + 1] === '"' || $command[$i + 1] === '\\'
                            || $command[$i + 1] === '$' || $command[$i + 1] === '`')) {
                        $content .= $command[$i + 1];
                        $i += 2;
                        continue;
                    }
                    if ($inner === $quote) {
                        $i++;
                        break;
                    }
                    $content .= $inner;
                    $i++;
                }
                $current = ($current ?? '') . $content;
                continue;
            }
            if ($char === '\\' && $i + 1 < $length) {
                $current = ($current ?? '') . $command[$i + 1];
                $i += 2;
                continue;
            }
            $current = ($current ?? '') . $char;
            $i++;
        }
        if ($current !== null) {
            $tokens[] = $current;
        }
        return $tokens;
    }

    /**
     * Turn a raw $PAGER command line into the final argv: tokenized with
     * quoting, and ANSI-preserving `-R` appended ONLY when the pager really
     * is `less` and no argv token ALREADY carries it (once-092 leg 3).
     *
     * The old check was `str_starts_with(trim($pager), 'less') &&
     * !str_contains($pager, '-R')` on the raw string — a whole-line
     * substring test. Detection is now per token with word boundaries:
     * combined short-option clusters (`-RF`, `-FsR`) count as raw-enabled,
     * long options like `--status-column` do not, and a non-less pager
     * (`vim -R`) is never touched: its `-R` means read-only, and the old
     * substring logic mis-read exactly that shape.
     *
     * @return list<string>
     */
    public static function resolvePagerCommand(string $command): array
    {
        $argv = self::parseCommand($command);
        if ($argv === []) {
            return [];
        }
        $binary = basename($argv[0]);
        if ($binary !== 'less' && $binary !== 'less.exe') {
            return $argv;
        }
        foreach ($argv as $index => $token) {
            if ($index === 0) {
                continue;
            }
            if (preg_match('/^-[A-Za-z]*R/', $token) === 1) {
                return $argv;
            }
        }
        $argv[] = '-R';
        return $argv;
    }

    /**
     * Write a disclosure line WITHOUT touching the stream a pager child may
     * own: a ConsoleOutputInterface routes to its paired error stream (the
     * process stderr), which stays honest and visible while fd 1 is being
     * consumed by the pager or by a captured redirect. Any other sink has
     * no side channel, so the line lands inline and testable.
     * OUTPUT_RAW skips the formatter — pager commands may legitimately
     * contain '<' and '>' characters which must not be parsed as tags.
     */
    private static function disclose(OutputInterface $output, string $line): void
    {
        if ($output instanceof ConsoleOutputInterface) {
            $output->getErrorOutput()->writeln($line, OutputInterface::OUTPUT_RAW);
            return;
        }
        $output->writeln($line, OutputInterface::OUTPUT_RAW);
    }

    /**
     * THE terminal-truth arbiter: Command::stdoutIsTerminal() mirrors this
     * exact policy by documented intent (once-S22); nothing else may
     * invent a second way to ask "is stdout a tty".
     */
    private static function stdoutIsTty(): bool
    {
        if (function_exists('stream_isatty')) {
            return @stream_isatty(STDOUT);
        }
        return function_exists('posix_isatty') ? @posix_isatty(STDOUT) : false;
    }
}
