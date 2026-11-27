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
 * The venue offers two whole-file postures: {@see writeFile()} places a
 * fresh file byte-for-byte-or-throw, and {@see writeFileAtomic()}
 * (once-S88, php-dup-finder-once-111) replaces an existing artifact through
 * a private sibling temp plus rename, so an interrupted overwrite can never
 * leave a torn half-old/half-new file at the trusted name.
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
     * once-S88 (php-dup-finder-once-111): the ATOMIC sibling of
     * {@see writeFile()} — whole-file replace via a private sibling temp
     * plus rename, for artifacts a human trusts without opening.
     *
     * {@see writeFile()} already guarantees byte-for-byte-or-throw; what it
     * cannot promise is the FAILURE shape: a direct file_put_contents over
     * an existing file truncates first, so a short write leaves a torn
     * artifact — half old bytes, half new. rename(2) over an existing
     * target is atomic on POSIX: a reader sees the previous complete
     * version or the new complete version, never a mixture. The temp name
     * carries the artifact name plus random bytes, so concurrent writers
     * over one directory cannot clash on the temp; the race settles
     * last-writer-whole-file-wins. Consumers keep the single-venue law
     * (their source stays free of raw write primitives — pinned by
     * RefactorPatchReporterTest) and opt into atomicity per call site:
     * today the F1a apply.diff and F1b apply-summary.md legs of
     * RefactorPatchReporter, where an interrupted overwrite used to be
     * able to half-rewrite the whole artifact (php-dup-finder-once-111).
     *
     * On any failure the created temp is removed with the same race
     * posture as {@see removeOwnedName()} — litter the disk refuses to
     * give up is named in the raised exception, chained to the primary
     * failure — and the target keeps its previous bytes untouched.
     *
     * @param string $path    Target file to replace atomically.
     * @param string $content The complete new bytes.
     */
    protected function writeFileAtomic(string $path, string $content): void
    {
        $dir      = dirname($path);
        $this->ensureDirectory($dir);
        $temp     = $dir . DIRECTORY_SEPARATOR . '.' . basename($path) . '.' . bin2hex(random_bytes(8));
        $expected = strlen($content);

        try {
            [$written, $diagnostic] = self::attempt(static fn () => file_put_contents($temp, $content));
            if ($written === false || $written !== $expected) {
                throw ReportWriteException::writeFailed(
                    $temp,
                    $diagnostic,
                    $expected,
                    is_int($written) ? $written : 0,
                );
            }
            [$moved, $renameDiagnostic] = self::attempt(static fn () => rename($temp, $path));
            if ($moved !== true) {
                throw new ReportWriteException(sprintf(
                    'renaming %s over %s failed: %s — the target keeps its previous bytes',
                    $temp,
                    $path,
                    $renameDiagnostic !== '' ? $renameDiagnostic : 'no diagnostic recorded',
                ));
            }
        } catch (\Throwable $failure) {
            [$removed, $unlinkDiagnostic] = self::attempt(static fn () => unlink($temp));
            if ($removed === false && (is_link($temp) || is_file($temp))) {
                $failure = new ReportWriteException(
                    $failure->getMessage()
                        . '; the temporary file ' . $temp . ' could not be removed either ('
                        . ($unlinkDiagnostic !== '' ? $unlinkDiagnostic : 'no diagnostic recorded') . ')',
                    0,
                    $failure,
                );
            }
            throw $failure;
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
     * once-074 (step once-S65, r1 MEDIUM-1 fold): remove `$dir`'s stale
     * generation of entries THIS consumer can mint, before the current run
     * writes its own — the single removal-semantics site shared by every
     * directory-writing reporter (output-S55's convergence law: a write, a
     * directory, and now a sweep all fail loud from one venue).
     *
     * `$pattern` is the caller's OWNED_* constant — the exact image of the
     * names that writer mints — and `$what` is the singular noun its refusal
     * messages speak with. The doctrine, unchanged from the three bodies
     * this replaces: list `$dir` once through {@see attempt()} (a directory
     * that cannot be listed cannot be proven clean — refuse, naming it);
     * touch only string entries matching `$pattern` that are regular files
     * or symlinks (a link is removed AS a link, never written through; a
     * directory or exotic type wearing an owned name is user content the
     * reporter could not have minted and survives — the current run's own
     * write fails loudly on it if the name is ever needed). Everything
     * outside the pattern survives, at every level: one dir is listed,
     * nothing is recursed. A removal the disk refused while the name is
     * still there raises {@see ReportWriteException}; one that raced away is
     * already clean — the same race posture as {@see ensureDirectory()}.
     * A mixed-generation surface is exactly the silent success the venue
     * exists to kill, so the CLI prints the `phpdup:` line and exits 1 over
     * an honest refusal instead of a success line over stale bytes.
     *
     * @param string $dir     The output directory to clear of this generation's predecessors.
     * @param string $pattern Ownership regex over bare entry names (the caller's OWNED_* constant).
     * @param string $what    Singular noun for the refusal messages ('report page', 'refactor patch', ...).
     */
    protected function sweepOwnedNames(string $dir, string $pattern, string $what): void
    {
        [$entries, $diagnostic] = self::attempt(static fn () => scandir($dir));
        if (!is_array($entries)) {
            throw new ReportWriteException(sprintf(
                'sweeping stale %s in %s failed: directory cannot be listed (%s)',
                $what,
                $dir,
                $diagnostic !== '' ? $diagnostic : 'no diagnostic recorded',
            ));
        }
        foreach ($entries as $entry) {
            if (!is_string($entry) || preg_match($pattern, $entry) !== 1) {
                continue; // not a name this reporter can mint — never its business
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_link($path)) {
                $this->removeOwnedName($path, $what);
                continue;
            }
            if (is_file($path)) {
                $this->removeOwnedName($path, $what);
            }
        }
    }

    /**
     * Unlink one owned name — or say so (race posture identical to the old
     * per-reporter arms and to {@see ensureDirectory()}: refused-and-still-
     * there is a refusal that names the path; raced-away is already clean).
     */
    private function removeOwnedName(string $path, string $what): void
    {
        [$ok, $diagnostic] = self::attempt(static fn () => unlink($path));
        if ($ok === false && (is_link($path) || is_file($path))) {
            throw new ReportWriteException(sprintf(
                'removing stale %s %s failed: %s',
                $what,
                $path,
                $diagnostic !== '' ? $diagnostic : 'no diagnostic recorded',
            ));
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
