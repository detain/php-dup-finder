<?php
declare(strict_types=1);

namespace Phpdup\Pipeline;

final class DebugLogger
{
    /**
     * Handle state machine: null = closed / not yet opened (append may open),
     * resource = open log file, false = the one open attempt failed —
     * TERMINAL for this handle lifecycle (append never retries; only a
     * close + reopen resets it). Every state is destructor-safe: close
     * only ever runs on a real resource.
     *
     * @var resource|false|null
     */
    private $handle = null;

    public function __construct(private readonly ?string $path)
    {
    }

    /**
     * CLI admission probe (once-S69 / php-dup-finder-once-079): can the
     * path be opened for append, checked BEFORE any DebugLogger exists?
     * Returns null when the path is usable (the probe handle is closed
     * again immediately) or a one-line failure reason otherwise. The
     * fopen is warning-suppressed — the caller renders exactly one clear
     * error line, so a mistyped path never pollutes the run with native
     * fopen diagnostics.
     */
    public static function admissionProbe(string $path): ?string
    {
        $handle = @fopen($path, 'ab');
        if (!is_resource($handle)) {
            $last = error_get_last();
            // Greedy on purpose (once-S69 r1 / n-1): a ')' inside the path
            // must not stop the strip — the fopen label ends at the LAST
            // '): ' separator, since the OS reason never contains one.
            $reason = preg_replace('/^fopen\(.*\): /', '', (string) ($last['message'] ?? ''));
            return $reason === null || $reason === '' ? 'cannot open for writing' : $reason;
        }
        fclose($handle);
        return null;
    }

    public function append(string $message): void
    {
        if ($this->path === null) {
            return;
        }
        if ($this->handle === null) {
            // One open attempt per handle lifecycle (php-dup-finder-once-079):
            // the old retry-on-false leg fired a fresh fopen warning per debug
            // message — warning SPAM on every unwritable path. A single failed
            // open now parks the state terminal-false. This leg is only
            // reachable when the caller bypassed CLI admission (serve/watch
            // payloads) or the path broke mid-run; the one native warning
            // stays unsuppressed as the honest marker of that rare event.
            $this->handle = fopen($this->path, 'ab');
        }
        if ($this->handle === false) {
            return; // terminal: open failed once — no retry, no warning spam
        }
        fwrite($this->handle, $message . "\n");
    }

    /**
     * Close the current handle (if any) and return the state machine to
     * "closed". Only a real resource is ever handed to fclose: a failed
     * open leaves handle === false, and fclose(false) raises a TypeError
     * during shutdown — turning a completed successful run into exit 255.
     */
    public function __destruct()
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
        $this->handle = null;
    }
}