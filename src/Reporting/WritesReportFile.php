<?php
declare(strict_types=1);

namespace Phpdup\Reporting;

/**
 * The fail-loud file-output venue shared by every writer on the report
 * surface (php-dup-finder-once-040, php-dup-finder-output-024).
 *
 * Reporters that emit to a file path — JSON, SARIF, GitLab SAST,
 * Checkstyle, CSV, Prometheus, Graphviz, PlantUML, Timeseries, HTML, Diff,
 * RefactorTest, RefactorPatch — and the corpus/apply sinks that share the
 * venue (FuzzCorpusGenerator, ApplyExtractor) call these methods instead of
 * duplicating a directory-creation + write sequence.
 *
 * The doctrine of the venue: a write either lands byte-for-byte or throws
 * {@see ReportWriteException}. Nothing in here swallows. The historical
 * suppressed-mkdir + unchecked-write pair reported success on a disk that
 * took none of the bytes — the silent-success class this trait kills.
 * The one intentional variety is {@see appendFile()}, the documented
 * stream-append exception (output-S54 §54): it appends under LOCK_EX for
 * the growing timeseries ledger, with the identical byte accounting.
 *
 * Consumers call {@see writeFile()} (or {@see appendFile()}) from their own
 * public `writeTo()` method, which is the contract expected by ReportStage:
 * a throw there means the stage never prints its success line.
 *
 * @see JsonReporter     for a canonical consumer
 * @see SarifReporter    for SARIF-shaped output
 * @see CsvReporter      for delimited output
 */
trait WritesReportFile
{
    /**
     * Creates the parent directory of `$path` if it does not exist, then
     * writes the full `$content` string to `$path`, and demands that every
     * byte landed. A refused open, a short write, or a directory that can
     * be neither found nor created throws {@see ReportWriteException}
     * naming the target and the byte delta — never a silent success.
     *
     * Directory creation uses mode `0775` recursively, exactly as the
     * historic swallow did; what changed is that its failure is now a
     * named, non-zero exit instead of a missing file with an "ok" line.
     *
     * @param string $path    Absolute or relative file path to write to.
     * @param string $content The complete serialized report content.
     */
    protected function writeFile(string $path, string $content): void
    {
        $this->ensureDirectory(dirname($path));

        $expected = strlen($content);
        [$written, $diagnostic] = self::attempt(static fn () => file_put_contents($path, $content));
        if ($written === false || $written !== $expected) {
            throw ReportWriteException::writeFailed(
                $path,
                $diagnostic,
                $expected,
                is_int($written) ? $written : 0,
            );
        }
    }

    /**
     * The documented stream-append exception (output-S54): appends
     * `$content` to `$path` under `FILE_APPEND | LOCK_EX` — the growing
     * timeseries ledger wants an append, not a rewrite of its history —
     * but with the same fail-loud contract as {@see writeFile()}: the
     * bytes this call intended must land, or {@see ReportWriteException}
     * says so with the byte delta.
     *
     * @param string $path    File to append to (created, with parents, when absent).
     * @param string $content The line (and newline) to append.
     */
    protected function appendFile(string $path, string $content): void
    {
        $this->ensureDirectory(dirname($path));

        $expected = strlen($content);
        [$written, $diagnostic] = self::attempt(
            static fn () => file_put_contents($path, $content, FILE_APPEND | LOCK_EX)
        );
        if ($written === false || $written !== $expected) {
            throw ReportWriteException::writeFailed(
                $path,
                $diagnostic,
                $expected,
                is_int($written) ? $written : 0,
                'appending',
            );
        }
    }

    /**
     * Creates `$dir` recursively when absent — loudly.
     *
     * A creation that fails while the directory still does not exist
     * throws {@see ReportWriteException}; the race where another process
     * wins the mkdir between the `is_dir` test and the call is resolved
     * by the re-check, exactly as the old suppressed arm did, except the
     * losing branch now names the directory instead of proceeding to
     * write into nothing.
     *
     * @param string $dir Directory path to ensure.
     */
    protected function ensureDirectory(string $dir): void
    {
        if ($dir === '' || $dir === '.' || is_dir($dir)) {
            return;
        }

        [$created, $diagnostic] = self::attempt(static fn () => mkdir($dir, 0o775, true));
        if ($created === false && !is_dir($dir)) {
            throw ReportWriteException::directoryUnreachable($dir, $diagnostic);
        }
    }

    /**
     * Runs `$operation` under a scoped error handler that captures the
     * first diagnostic the stream machinery emits, and restores the
     * previous handler in a `finally` — so a failed write neither sprays
     * a raw warning at the user's terminal nor leaves the failure riding
     * in process-global error state for some later arm to misattribute
     * (the stale-diagnostic class the apply window names in its comments).
     *
     * @param callable(): mixed $operation
     * @return array{0: mixed, 1: string} the operation result plus the captured diagnostic ('' when none)
     */
    private static function attempt(callable $operation): array
    {
        $diagnostic = '';
        set_error_handler(
            static function (int $severity, string $message) use (&$diagnostic): bool {
                if ($diagnostic === '') {
                    $diagnostic = $message;
                }
                return true;
            }
        );
        try {
            $result = $operation();
        } finally {
            restore_error_handler();
        }

        return [$result, $diagnostic];
    }
}
