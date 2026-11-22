<?php
declare(strict_types=1);

namespace Phpdup\Reporting;

/**
 * Shared per-run source-text store (php-dup-finder-output-016 / -043 / -048).
 *
 * Before this class the report surfaces carried three independent
 * whole-file readers — `HtmlReporter::loadSources()`,
 * `DiffReporter::sourceOf()` (also the JSON reporter's read path) and
 * `RefactorPatchReporter::memberSource()` — each slurping and splitting
 * every member's file once per cluster it participates in. A file in 30
 * clusters was re-read 30+ times per enabled surface, and each reader
 * invented its own failure posture: DiffReporter embedded the literal
 * `<source unavailable: …>` string AS diff content (which then also
 * polluted JSON evidence), while HTML silently rendered an empty cell
 * that reads like a real empty source.
 *
 * The store is the single read path:
 *
 *  - **one read per file per run** — the whole file is slurped once and
 *    cached as a line list; surfaces slice the ranges they need. Failed
 *    reads are negative-cached too, so an unavailable path stays cheap
 *    and earns exactly one skip note per tree state.
 *  - **mtime/size revalidation** — every lookup re-stats first; a file
 *    that changed under the run (edited tree, watch-loop reprieve) is
 *    re-read rather than served stale. A cached hit with an unchanged
 *    stamp costs one stat, never a read.
 *  - **symlink confinement on reads** (output-048, read-side half of the
 *    durable FOLLOW_SYMLINKS root cause): each lookup canonicalizes via
 *    `realpath()` and, when the path diverges from its canonical form
 *    (a link anywhere in its chain — planted after the scan, replayed
 *    from a cluster cache, or hand-built by a library caller), refuses
 *    the read when the resolved target escapes every configured scan
 *    root, re-enters a root, or does not resolve. The refusal is
 *    recorded as a skip note mirroring the
 *    {@see \Phpdup\Scanning\FileScanner} skipNotes() posture —
 *    non-fatal, informative, in encounter order — and the out-of-root
 *    content never reaches any report artifact. Links to in-root
 *    targets keep working, exactly as at the walk boundary (shell-006).
 *    With no roots configured (library mode) divergence cannot be
 *    adjudicated and reads proceed unconfined: the confinement gate is
 *    belt-and-braces, and only the roots make it decidable.
 *  - **honest unavailability** — `lines()` answers null, never a
 *    placeholder string, and keeps a per-path reason so every surface
 *    can render the same explicit "not fetched" posture the plan calls
 *    for (HTML unfetched marker, `.diff`/`.patch` metadata rows, JSON
 *    `null` text).
 *
 * Lifetime: the store is run-scoped, never process-scoped. ReportStage
 * constructs one per run and injects it into all four source-reading
 * surfaces; a reporter built without one (library, server workers, hand-
 * built reports) creates a transient store from the report's own scan
 * roots, so the confinement defence is never opt-out by omission. It is
 * deliberately not on PipelineState — the Report stage is the only
 * consumer, and a report payload may outlive its stage, so the Report
 * surface keeps carrying no store reference at all.
 */
final class SourceTextStore
{
    /** Shared explicit-posture label every read surface renders. */
    public const UNFETCHED = 'source not read';

    /** Path is gone or was never there (no skip note: stale trees are normal). */
    public const REASON_MISSING = 'file not present';

    /** Path exists but the read failed (skip note + optional stderr warning). */
    public const REASON_UNREADABLE = 'unreadable';

    /** Link chain resolved to nothing — dangling symlink (skip note). */
    public const REASON_UNRESOLVED = 'target does not resolve';

    /** Link chain resolves back into the root it came from (skip note). */
    public const REASON_REENTER = 'target re-enters the scan root';

    /** Link chain resolves outside every configured scan root (skip note). */
    public const REASON_ESCAPE = 'target escapes the scan root';

    /** Sentinel for "the refusal could not stat anything" (null stamps). */
    private const MISSING_STAMP = ['mtime' => -1, 'size' => -1];

    /** @var array<string, list<string>> path => cached whole-file lines */
    private array $lines = [];

    /** @var array<string, ?array{mtime:int,size:int}> stamp each cached read saw */
    private array $stamps = [];

    /** @var array<string, string> path => reason of the last unavailable lookup */
    private array $unavailable = [];

    /** @var array<string, ?array{mtime:int,size:int}> stamp each refusal saw */
    private array $unavailableStamps = [];

    /** @var list<string> divergence/unreadability notes in encounter order */
    private array $skipNotes = [];

    /** @var array<string, string|false> realpath memo for the scan roots */
    private array $rootReal = [];

    private int $readCount = 0;

    /**
     * @param list<string> $roots      scan roots confinement is checked against
     *                                 (Config::$paths); empty = library mode
     * @param bool         $warnOnSkip emit a stderr warning per skip note, the
     *                                 FileScanner posture (tests assert notes
     *                                 through skipNotes() without STDERR)
     */
    public function __construct(
        private readonly array $roots = [],
        private readonly bool $warnOnSkip = true,
    ) {}

    /**
     * Whole-file lines of $file, or null when the source is not available
     * (missing, unreadable, or refused by symlink confinement). The reason
     * for the null stays queryable via {@see reason()} so surfaces can
     * render an honest marker instead of guessing.
     *
     * @return list<string>|null
     */
    public function lines(string $file): ?array
    {
        $stamp = $this->stampOf($file);

        if (
            array_key_exists($file, $this->lines)
            && $stamp !== null
            && $stamp === $this->stamps[$file]
        ) {
            return $this->lines[$file];
        }
        if (
            array_key_exists($file, $this->unavailable)
            && ($stamp ?? self::MISSING_STAMP) === $this->unavailableStamps[$file]
        ) {
            return null;
        }

        // Nothing cached, or the tree state moved: decide again from disk.
        unset($this->lines[$file], $this->stamps[$file], $this->unavailable[$file], $this->unavailableStamps[$file]);

        $verdict = $this->fetch($file);
        if ($verdict !== null) {
            $this->lines[$file]  = $verdict;
            $this->stamps[$file] = $stamp ?? self::statStamp($file);
            $this->readCount++;
        }
        return $verdict;
    }

    /** Why $file was last unavailable, or null when it is (or was) fetchable. */
    public function reason(string $file): ?string
    {
        return $this->unavailable[$file] ?? null;
    }

    /**
     * Notes the store had to record — symlink-confinement refusals and
     * unreadable paths — in encounter order. Mirrors the FileScanner
     * skipNotes() wording so operators see one dialect, not two.
     *
     * @return list<string>
     */
    public function skipNotes(): array
    {
        return $this->skipNotes;
    }

    /** Count of actual whole-file reads performed (cache spies assert on it). */
    public function readCount(): int
    {
        return $this->readCount;
    }

    /**
     * Read and split the file, recording the availability posture (reason,
     * negative stamp, and — for the refusal classes — the skip note).
     *
     * @return list<string>|null
     */
    private function fetch(string $file): ?array
    {
        $escape = $this->escapeReason($file);
        if ($escape !== null) {
            $this->markUnavailable($file, $escape, "skipped symlinked path {$file} ({$escape})");
            return null;
        }

        $contents = @file_get_contents($file);
        if ($contents === false) {
            $reason = self::statStamp($file) === null ? self::REASON_MISSING : self::REASON_UNREADABLE;
            $note   = $reason === self::REASON_UNREADABLE ? "skipped unreadable path {$file} ({$reason})" : null;
            $this->markUnavailable($file, $reason, $note);
            return null;
        }

        return preg_split("/\r?\n/", $contents) ?: [];
    }

    /**
     * Why a read must be refused as a link escape (output-048), or null
     * when the read may proceed. Only divergent paths are canonicalized
     * beyond the stat: a non-link literal path under a scanned root is
     * what the walk itself produced and cannot leave the tree by
     * accident — the same argument shell-006 makes at the walk boundary.
     */
    private function escapeReason(string $file): ?string
    {
        $real = realpath($file);
        if ($real === false) {
            // A path that never resolved and is not a link is simply
            // missing (the read itself will say so); a DANGLING symlink
            // also lands here and is a refusal the scanner would note.
            return is_link($file) ? self::REASON_UNRESOLVED : null;
        }
        if ($real === $file || $this->roots === []) {
            return null;
        }

        foreach ($this->roots as $root) {
            $rootReal = $this->rootReal($root);
            if ($rootReal === false) {
                continue;
            }
            if ($real === $rootReal) {
                return self::REASON_REENTER;
            }
            // rtrim first: the filesystem root realpath is "/" itself, and
            // a naive "/" + "/" yard-stick would refuse every in-root file
            // (the same boundary law as FileScanner::escapeReason).
            if (str_starts_with($real, rtrim($rootReal, '/') . '/')) {
                return null;
            }
        }

        return self::REASON_ESCAPE;
    }

    private function rootReal(string $root): string|false
    {
        $key = rtrim($root, '/');
        if (!array_key_exists($key, $this->rootReal)) {
            $this->rootReal[$key] = realpath($key);
        }
        return $this->rootReal[$key];
    }

    /**
     * @return ?array{mtime:int,size:int} null when statting fails
     */
    private function stampOf(string $file): ?array
    {
        // The stat cache would happily serve the stamp the previous lookup
        // recorded — which is exactly the staleness this revalidate exists
        // to catch. Clear before each decision.
        clearstatcache(true, $file);
        return self::statStamp($file);
    }

    /**
     * @return ?array{mtime:int,size:int}
     */
    private static function statStamp(string $file): ?array
    {
        $mtime = @filemtime($file);
        $size  = @filesize($file);
        if ($mtime === false || $size === false) {
            return null;
        }
        return ['mtime' => $mtime, 'size' => $size];
    }

    /** @param string|null $note skip note to record, null = informative silence */
    private function markUnavailable(string $file, string $reason, ?string $note): void
    {
        $this->unavailable[$file]       = $reason;
        $this->unavailableStamps[$file] = self::statStamp($file) ?? self::MISSING_STAMP;
        if ($note === null) {
            return;
        }
        $this->skipNotes[] = $note;
        if ($this->warnOnSkip) {
            fwrite(STDERR, "phpdup: warning: {$note}\n");
        }
    }
}
