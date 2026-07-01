<?php
declare(strict_types=1);

namespace Phpdup\Reporting;

/**
 * Deduplicates file-output boilerplate across file-writing reporters.
 *
 * Reporters that emit their output to a file path (JSON, SARIF, GitLab SAST,
 * Checkstyle, CSV, Prometheus, Graphviz, PlantUML, Timeseries) use this trait
 * instead of duplicating the directory-creation + write sequence.
 *
 * Consumers must call {@see writeFile()} from their own public {@see writeTo()}
 * method, which is the contract expected by {@see ReportStage}.
 *
     * The trait is intentionally minimal: it performs no locking, assumes the
     * caller has already built the serialized content, and swallows mkdir errors
     * silently so that pre-existing directories do not cause failures.
 *
 * @see JsonReporter     for a canonical consumer
 * @see SarifReporter    for SARIF-shaped output
 * @see CsvReporter      for delimited output
 */
trait WritesReportFile
{
    /**
     * Creates the parent directory of `$path` if it does not exist, then writes
     * the full `$content` string to `$path` via `file_put_contents`.
     *
     * Directory creation uses mask `0775` and is recursive (all parent
     * directories are created as needed).  Errors from `@mkdir` are suppressed
     * so that a missing directory is created silently while pre-existing
     * directories do not cause the write to fail.
     *
     * @param string $path    Absolute or relative file path to write to.
     * @param string $content The complete serialized report content.
     */
    protected function writeFile(string $path, string $content): void
    {
        $this->ensureDir($path);
        file_put_contents($path, $content);
    }

    /**
     * Creates the directory portion of `$path` if it is non-empty and absent.
     *
     * Uses mask `0775` and `is_dir` to avoid overwriting an existing directory.
     * The `dirname` call is safe for both Unix and Windows paths.
     *
     * @param string $path File path whose parent directory is to be ensured.
     */
    private function ensureDir(string $path): void
    {
        $dir = dirname($path);
        if ($dir !== '' && !is_dir($dir)) {
            @mkdir($dir, 0o775, true);
        }
    }
}
