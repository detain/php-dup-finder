<?php
declare(strict_types=1);

namespace Phpdup\Reporting;

/**
 * The named failure of the silent-success write class (php-dup-finder-once-040,
 * php-dup-finder-output-024).
 *
 * Every sink routed through {@see WritesReportFile} raises this instead of
 * letting a refused, truncated, or otherwise unwritten file ride on into the
 * success line. It extends \RuntimeException so the transactional catches
 * that predate it — the apply window's `catch (\Throwable)` and the
 * RuntimeException assertions in its tests — keep their exact posture;
 * nothing that caught the base class loses coverage of the new failure.
 *
 * The message always names the target path and the byte accounting
 * ("landed W of N bytes; delta D"), so the CLI error line, the apply
 * refusal's cause clause, and a rollback problem row carry the same
 * evidence without a second diagnostic channel.
 */
final class ReportWriteException extends \RuntimeException
{
    /**
     * A whole-file (or append) write that did not land all its bytes.
     *
     * @param string $path      Target the writer attempted.
     * @param string $diagnostic Raw PHP diagnostic captured for the attempt ('' when none).
     * @param int    $expected  Bytes the writer intended to place.
     * @param int    $written   Bytes the stream reported landed (0 for a refused open).
     * @param string $verb      Leading word of the sentence — 'writing' or 'appending'.
     */
    public static function writeFailed(
        string $path,
        string $diagnostic,
        int $expected,
        int $written,
        string $verb = 'writing'
    ): self {
        return new self(sprintf(
            '%s %s failed: %s (landed %d of %d bytes; delta %d)',
            $verb,
            $path,
            $diagnostic !== '' ? $diagnostic : 'no diagnostic recorded',
            $written,
            $expected,
            $expected - $written,
        ));
    }

    /**
     * A parent directory the writer could neither find nor create.
     *
     * @param string $dir       Directory path whose creation was refused.
     * @param string $diagnostic Raw PHP diagnostic captured for the attempt ('' when none).
     */
    public static function directoryUnreachable(string $dir, string $diagnostic): self
    {
        return new self(sprintf(
            'creating directory %s failed: %s',
            $dir,
            $diagnostic !== '' ? $diagnostic : 'no diagnostic recorded',
        ));
    }
}
