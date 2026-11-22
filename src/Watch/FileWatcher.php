<?php
declare(strict_types=1);

namespace Phpdup\Watch;

use Phpdup\Scanning\FileScanner;
use Psr\Log\LoggerInterface;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use SplFileInfo;

/**
 * Unified file watcher with native backend support and polling fallback.
 *
 * Detects new, modified, and deleted files by integrating with React's
 * event loop. Uses inotify on Linux and periodic polling as a portable
 * fallback. Native FSEvents support (macOS) is planned for a future release
 * when a suitable PHP extension is available.
 *
 * ## Hint → debounce → diff → ONE dispatch (php-dup-finder-shell-024)
 *
 * Raw backends never call the consumer per event any more. An inotify
 * event or a poll tick is only a HINT that something may have changed;
 * the first hint after an idle period arms a one-shot debounce timer
 * ({@see FileWatcher::DEBOUNCE_SECONDS}, tunable, deliberately not a CLI
 * flag). When the window expires, `flush()` performs the authoritative
 * pass:
 *
 *  - re-enumerate watchable files through {@see FileScanner::scan()},
 *    which is the single home of both the PHP-extension predicate
 *    (`looksLikePhp` — reused, never forked here), the shell-006
 *    symlink containment posture (out-of-root and root-re-entering
 *    links are pruned, so the watcher cannot follow them either), and
 *    the exclude verdicts this watcher applies (see below), and
 *  - diff that list against the snapshot, rebase the snapshot, and
 *  - dispatch the consumer ONCE with the whole coalesced batch.
 *
 * Consequences the consumer can rely on: a burst of edits inside one
 * window is a single callback; a touch of a non-PHP file changes nothing
 * in the enumerated set and dispatches nothing at all; changes landing
 * DURING a dispatch are caught by the next window's diff against the
 * post-dispatch rebase (nothing mid-run is lost, nothing is restarted
 * per event — the once-086 A-16/A-19 halves).
 *
 * ## Coherence (php-dup-finder-once-051 / 086 / 094)
 *
 * - **Recursive inotify.** The kernel watch set covers the scan root and
 *   EVERY existing watchable subdirectory ({@see collectInotifySetupDirs()}),
 *   and follows directory creation under watched parents. An edit in any
 *   subdirectory wakes the debounce — the HIGH finding's "only the root
 *   is watched" backend asymmetry is closed.
 * - **Bounded wd bookkeeping.** IN_DELETE_SELF/IN_IGNORED sit in the
 *   mask; a self-destroyed watch drains its `wdToPath` entry, so the map
 *   tracks the live kernel set instead of growing across churn.
 * - **Size-aware poll key.** The snapshot stores `mtime:size` composites
 *   ({@see statKey()}); an edit that lands within the mtime resolution
 *   but moves bytes (exFAT/FAT cards, editor fsync races) is seen.
 * - **Config::exclude honored in BOTH backends.** The glob list supplied
 *   via the constructor or {@see applyExcludes()} drives the poll
 *   enumeration (through FileScanner itself), the inotify setup walk, and
 *   the new-directory watch decision alike.
 *
 * ## Snapshot lifecycle
 *
 * The watcher maintains an internal snapshot of known files and their
 * stat keys:
 * - Call {@see resync()} after each pipeline run to rebase the snapshot on
 *   the watcher's own enumeration (what a flush would diff against anyway).
 * - {@see syncFromFileList()} remains for callers that seed from an external
 *   list (the TUI watch leg). Such a list is only consistent with this
 *   watcher when it was produced under the SAME exclude view the watcher
 *   enumerates with; {@see applyExcludes()} rebases the snapshot itself, so
 *   a mid-life view swap never fabricates a phantom Created/Deleted batch.
 *   The TUI leg in Command.php currently seeds its exclude-shaped list
 *   into a watcher built without globs — the wiring half of that cure
 *   (`applyExcludes($config->exclude)` at that leg) rides its own Command
 *   lane; {@see resync()} is the glob-free alternative.
 * - Call {@see detectNewFiles()}, {@see detectModifiedFiles()}, and
 *   {@see detectDeletedFiles()} to diff a given file list against the
 *   snapshot and return the respective change sets.
 * - All three `detect*` methods read from the snapshot but do not modify it.
 *   Only {@see syncFromFileList()}, {@see resync()}, {@see applyExcludes()}
 *   and an internal flush update the snapshot.
 *
 * The consumer callback is invoked once per debounced flush with the
 * representative (last) change, its type, and the full coalesced batch.
 */
final class FileWatcher
{
    /**
     * Quiet window (seconds) that raw change hints are coalesced into a
     * single dispatch. Middle of the 150–500 ms band named by shell-024;
     * interactive watch latency stays imperceptible while a 100-file
     * touch collapses from 100 pipeline passes into one.
     */
    public const DEBOUNCE_SECONDS = 0.25;

    /** Allowed values of the $backend constructor argument. */
    public const BACKEND_AUTO    = 'auto';
    public const BACKEND_POLL    = 'poll';
    public const BACKEND_INOTIFY = 'inotify';

    // inotify event mask constants — matches PHP's inotify extension constants.
    private const IN_MODIFY      = 2;      // IN_MODIFY
    private const IN_MOVED_TO    = 64;     // IN_MOVED_TO
    private const IN_MOVED_FROM  = 128;    // IN_MOVED_FROM
    private const IN_CREATE      = 256;    // IN_CREATE
    private const IN_DELETE      = 512;    // IN_DELETE
    private const IN_DELETE_SELF = 1024;   // IN_DELETE_SELF (0x400): watched dir removed
    private const IN_IGNORED     = 32768;  // IN_IGNORED (0x8000): kernel dropped the watch

    /**
     * Mask combining all events the watcher cares about. The self-destroy
     * pair (once-094) is what lets the read handler drain `wdToPath` the
     * moment the kernel retires a watch, instead of leaving dead entries
     * behind after directory deletions.
     */
    private const INOTIFY_MASK = self::IN_MODIFY | self::IN_CREATE | self::IN_DELETE | self::IN_MOVED_FROM | self::IN_MOVED_TO | self::IN_DELETE_SELF | self::IN_IGNORED;

    /** @var array<string,string> path -> "mtime:size" snapshot of known files */
    private array $snapshot = [];

    /** @var array<string,FileChangeType> paths that changed since last sync */
    private array $pendingChanges = [];

    private bool $running = false;

    /** @var resource|null inotify file descriptor (Linux) */
    private $inotifyFd = null;

    /** @var array<int,string> watch descriptor -> watched path */
    private array $wdToPath = [];

    /** Loop actually driving this watcher (resolved in watch()). */
    private ?LoopInterface $activeLoop = null;

    private ?TimerInterface $flushTimer = null;

    private ?TimerInterface $pollTimer = null;

    /** @var (callable(string,FileChangeType,array<string,FileChangeType>):void)|null */
    private $changeCallback = null;

    /**
     * The exclude view both backends honor (once-086/094). Mutable via
     * {@see applyExcludes()} because WatchRunner learns the pipeline's
     * Config::exclude from the first dispatch state, while the watcher
     * itself is built (and in the TUI leg, started) before that state
     * exists.
     *
     * @var list<string>
     */
    private array $excludeGlobs;

    /**
     * The watchable-file enumerator. The PHP predicate + symlink
     * containment come from FileScanner itself (reused, never forked
     * here); the exclude globs ride this watcher's own view. warnOnSkip
     * stays off — the pipeline's own scan renders skip notes once per
     * pass; the watcher must not spray them from a timer callback.
     */
    private FileScanner $fileScanner;

    /**
     * @param float $pollInterval seconds between poll-backend ticks
     * @param list<string> $excludeGlobs Config::exclude globs the watcher
     *        must honor in both backends from the first flush (an empty
     *        list watches the whole tree — Callers whose exclude view
     *        arrives late use {@see applyExcludes()} instead).
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $scanRoot,
        private readonly float $pollInterval = 1.0,
        private readonly ?LoopInterface $loop = null,
        private readonly string $backend = self::BACKEND_AUTO,
        private readonly float $debounceSeconds = self::DEBOUNCE_SECONDS,
        array $excludeGlobs = [],
    ) {
        if (!in_array($this->backend, [self::BACKEND_AUTO, self::BACKEND_POLL, self::BACKEND_INOTIFY], true)) {
            throw new \InvalidArgumentException("FileWatcher: unknown backend '{$this->backend}' (expected auto, poll or inotify)");
        }
        $this->excludeGlobs = array_values($excludeGlobs);
        $this->fileScanner = new FileScanner($this->excludeGlobs, false);
    }

    /**
     * Start watching for file changes.
     *
     * @param callable(string $path, FileChangeType $type, array<string,FileChangeType> $batch): void $onChange
     *     Called ONCE per debounced flush. $path/$type carry the last
     *     change of the coalesced batch; $batch maps every changed path
     *     to its change type. Callbacks declaring only the first two
     *     parameters (the historical per-event shape) keep working: PHP
     *     ignores the extra positional argument.
     */
    public function watch(callable $onChange): void
    {
        if ($this->running) {
            return;
        }
        $this->running = true;
        $this->changeCallback = $onChange;
        $loop = $this->activeLoop = $this->loop ?? Loop::get();

        if ($this->backend === self::BACKEND_POLL) {
            $this->startPolling($loop);
            return;
        }
        if ($this->backend === self::BACKEND_INOTIFY) {
            if (!function_exists('inotify_init')) {
                $this->logger->warning('FileWatcher: inotify requested but the extension is unavailable, falling back to polling');
                $this->startPolling($loop);
                return;
            }
            $this->startInotify($loop);
            return;
        }

        // auto: inotify when the extension exists, portable poll otherwise.
        if (function_exists('inotify_init')) {
            $this->startInotify($loop);
            return;
        }
        $this->logger->info('FileWatcher: inotify not available, using polling backend (native FSEvents planned for future release)');
        $this->startPolling($loop);
    }

    /**
     * Stop watching and clean up resources: backends, a pending debounce
     * flush, and the diff state all drop together. Every fd/timer/map
     * resource acquired by a start path is released here (once-086
     * teardown symmetry; fenced by the fd-count test).
     */
    public function stop(): void
    {
        if (!$this->running) {
            return;
        }
        $this->running = false;

        if ($this->activeLoop !== null) {
            if ($this->flushTimer !== null) {
                $this->activeLoop->cancelTimer($this->flushTimer);
            }
            if ($this->pollTimer !== null) {
                $this->activeLoop->cancelTimer($this->pollTimer);
            }
        }
        $this->flushTimer = null;
        $this->pollTimer = null;

        $this->stopInotify();
        $this->snapshot = [];
        $this->pendingChanges = [];
        $this->changeCallback = null;
    }

    /**
     * Rebase the snapshot on the watcher's OWN enumeration of the scan
     * root (FileScanner::scan — PHP predicate + containment + this
     * watcher's exclude view included). This is exactly the set the next
     * flush diffs against, so a resync after a completed pass can never
     * manufacture phantom "created" events out of an exclude-shape
     * mismatch between the pipeline's file list and the watcher's walk.
     */
    public function resync(): void
    {
        $this->rebaseSnapshot($this->collectWatchableFiles());
        $this->pendingChanges = [];
    }

    /**
     * Adopt a Config::exclude glob view (once-086/094: the excludes the
     * pipeline scans under must be the excludes the watcher wakes under —
     * otherwise vendor/.git churn re-analyzes every tick, or an
     * exclude-shaped external seed fabricates a phantom Created batch).
     *
     * Replaces the enumerator and rebases the snapshot on the NEW view
     * immediately: a stale-view key left in the snapshot would classify
     * as a phantom Deleted against the first flush of the new view. The
     * inotify kernel watch set keeps any already-watched directories
     * (including ones a mid-life exclude now covers): a redundant wake
     * flushes to an empty diff and stays silent — the flush walk remains
     * the single authority. New directories are gated by the new view.
     *
     * @param list<string> $excludeGlobs
     */
    public function applyExcludes(array $excludeGlobs): void
    {
        $globs = array_values($excludeGlobs);
        if ($globs === $this->excludeGlobs) {
            return;
        }
        $this->excludeGlobs = $globs;
        $this->fileScanner = new FileScanner($globs, false);
        $this->resync();
    }

    /**
     * Sync the internal file snapshot from a caller-supplied file list.
     *
     * Consistency note (why this is NOT generally "just seed the list"):
     * flush() diffs against this watcher's OWN enumeration — PHP
     * predicate + containment + {@see applyExcludes()} view — so a
     * caller-supplied list shaped by different rules (the TUI watch leg
     * in Command.php currently seeds its exclude-shaped pipeline list
     * into a watcher that was never given the globs) leaves files out of
     * the snapshot and the next flush dispatches them as a phantom
     * Created batch. The watcher-side half of the once-S47 cure is
     * delivered: both backends now DO honor whatever view they were
     * given. The leg-side wiring (pass the globs / call applyExcludes,
     * or flip to {@see resync()}) rides Command.php's own lane.
     * WatchRunner already uses {@see resync()} + per-dispatch
     * {@see applyExcludes()} so enumeration and diffing share one
     * predicate.
     *
     * @param SplFileInfo[] $currentFiles
     */
    public function syncFromFileList(array $currentFiles): void
    {
        $this->rebaseSnapshot($currentFiles);
        $this->pendingChanges = [];
    }

    /**
     * Detect newly-added files compared to the last snapshot.
     *
     * @param SplFileInfo[] $currentFiles
     * @return SplFileInfo[] newly-added files
     */
    public function detectNewFiles(array $currentFiles): array
    {
        $newFiles = [];
        foreach ($currentFiles as $file) {
            $path = $file instanceof SplFileInfo ? $file->getPathname() : (string)$file;
            if (!array_key_exists($path, $this->snapshot)) {
                $newFiles[] = $file;
            }
        }
        return $newFiles;
    }

    /**
     * Detect files that have disappeared since the last snapshot.
     *
     * @param SplFileInfo[] $currentFiles
     * @return array<string> paths of deleted files
     */
    public function detectDeletedFiles(array $currentFiles): array
    {
        $currentPaths = [];
        foreach ($currentFiles as $file) {
            $path = $file instanceof SplFileInfo ? $file->getPathname() : (string)$file;
            $currentPaths[$path] = true;
        }

        $deleted = [];
        foreach (array_keys($this->snapshot) as $tracked) {
            if (!isset($currentPaths[$tracked])) {
                $deleted[] = $tracked;
            }
        }
        return $deleted;
    }

    /**
     * Detect files whose stat key (mtime + size, once-094) changed since
     * the last snapshot.
     *
     * shell-S40 (php-dup-finder-shell-051): the stat-cache clear is
     * hoisted to once per scan instead of once per file. Every path is
     * stat'd at most once after the clear within this walk, so the
     * scan-wide clear gives exactly the same read-freshness guarantee
     * the per-file clears did, with N calls fewer on a hot corpus.
     *
     * @param SplFileInfo[] $currentFiles
     * @return array<string> paths of modified files
     */
    public function detectModifiedFiles(array $currentFiles): array
    {
        clearstatcache(true);

        $modified = [];
        foreach ($currentFiles as $file) {
            $path = $file instanceof SplFileInfo ? $file->getPathname() : (string)$file;
            if (!array_key_exists($path, $this->snapshot)) {
                continue;
            }
            $key = $this->statKey($path);
            if ($key !== null && $key !== $this->snapshot[$path]) {
                $modified[] = $path;
            }
        }
        return $modified;
    }

    /**
     * Changes accumulated by debounced flushes since the last
     * {@see syncFromFileList()}/{@see resync()}.
     *
     * @return array<string,FileChangeType>
     */
    public function pendingChanges(): array
    {
        return $this->pendingChanges;
    }

    /**
     * Clear pending changes after they have been processed.
     */
    public function clearPendingChanges(): void
    {
        $this->pendingChanges = [];
    }

    /**
     * @return bool true when running, false when stopped
     */
    public function isRunning(): bool
    {
        return $this->running;
    }

    // ─── Debounce core ────────────────────────────────────────────────────────

    /**
     * Record that something MAY have changed. Both backends funnel here;
     * the first hint of a burst arms the coalescing window, later hints
     * merge into it. The authoritative diff happens once, at flush time.
     */
    private function noteChangeHint(): void
    {
        if (!$this->running || $this->flushTimer !== null || $this->activeLoop === null) {
            return;
        }
        $loop = $this->activeLoop;
        $this->flushTimer = $loop->addTimer($this->debounceSeconds, function (): void {
            $this->flushTimer = null;
            $this->flush();
        });
    }

    /**
     * Authoritative pass: re-enumerate watchable files, diff against the
     * snapshot, and dispatch the consumer once with the coalesced batch.
     * An empty batch (a .txt touch, a file behind an out-of-root symlink,
     * excluded-tree churn) stays silent. The stat key is
     * mtime-AND-size-aware (once-094, closing the N-2 preview from
     * shell-S40): an edit that lands within the same second but moves
     * bytes is classified Modified; only a re-touch that changes neither
     * mtime nor size stays silent on this clock.
     */
    private function flush(): void
    {
        $currentFiles = $this->collectWatchableFiles();
        $batch = [];

        foreach ($this->detectNewFiles($currentFiles) as $info) {
            $batch[$info->getPathname()] = FileChangeType::Created;
        }
        foreach ($this->detectModifiedFiles($currentFiles) as $path) {
            $batch[$path] = FileChangeType::Modified;
        }
        foreach ($this->detectDeletedFiles($currentFiles) as $path) {
            $batch[$path] = FileChangeType::Deleted;
        }

        if ($batch === []) {
            return;
        }

        $this->rebaseSnapshot($currentFiles);
        foreach ($batch as $path => $type) {
            $this->pendingChanges[$path] = $type;
        }

        $onChange = $this->changeCallback;
        if ($onChange === null) {
            return;
        }
        $representative = array_key_last($batch);
        $onChange($representative, $batch[$representative], $batch);
    }

    /**
     * The snapshot's change-detector key: an mtime:size composite. mtime
     * alone missed same-second content moves (exFAT/FAT cards, editor
     * fsync races — once-094); size alone would miss same-size rewrites;
     * the composite catches either signal moving.
     */
    private function statKey(string $path): ?string
    {
        $mtime = @filemtime($path);
        if ($mtime === false) {
            return null;
        }
        $size = @filesize($path);
        return $mtime . ':' . ($size === false ? -1 : $size);
    }

    /**
     * @param SplFileInfo[] $fileInfos
     */
    private function rebaseSnapshot(array $fileInfos): void
    {
        $this->snapshot = [];
        foreach ($fileInfos as $file) {
            $path = $file instanceof SplFileInfo ? $file->getPathname() : (string)$file;
            $key = $this->statKey($path);
            if ($key !== null) {
                $this->snapshot[$path] = $key;
            }
        }
    }

    /**
     * Enumerate watchable files via FileScanner::scan — the single source
     * of truth for "is this a PHP file" (its private looksLikePhp is
     * reused, not forked), for symlink containment (shell-006):
     * out-of-root links are pruned before the walk descends, so the poll
     * path cannot leak events for files outside the scan root, and for
     * the exclude verdicts of this watcher's current view.
     *
     * @return list<SplFileInfo>
     */
    private function collectWatchableFiles(): array
    {
        $files = [];
        foreach ($this->fileScanner->scan($this->scanRoot) as $path) {
            $files[] = new SplFileInfo($path);
        }
        return $files;
    }

    /**
     * Root-relative slash form of a path this watcher constructed, or
     * null when the path is not under the root (kernel events only ever
     * arrive for watched paths, but the guard keeps the exclude verdict
     * fail-closed: an unmappable path is never watched).
     */
    private function relUnderRoot(string $abs): ?string
    {
        $root = rtrim($this->scanRoot, '/');
        if ($abs === $root) {
            return '';
        }
        if (!str_starts_with($abs, $root . '/')) {
            return null;
        }
        return str_replace('\\', '/', substr($abs, strlen($root) + 1));
    }

    /**
     * Is one child entry of a watched directory worth its own kernel
     * watch? Directories only, never through a symlink (the containment
     * posture has no realpath duplication here: a link's real target is
     * enumerated — and watched, if in-root — under its own path by the
     * setup walk), and never under the exclude view (once-086).
     */
    private function inotifyWatchableChild(string $basePath, string $name): bool
    {
        if ($name === '' || !is_dir($basePath . '/' . $name)) {
            return false;
        }
        if (is_link($basePath . '/' . $name)) {
            return false;
        }
        $rel = $this->relUnderRoot($basePath . '/' . $name);
        if ($rel === null) {
            return false;
        }
        return !$this->fileScanner->isExcluded($rel . '/');
    }

    /**
     * Recursive watch setup (once-051): the root plus every existing
     * watchable subdirectory. Deriving the set by hand here is only a
     * WAKE-latency decision — flush() re-enumerates through FileScanner
     * regardless — but without it an edit inside a pre-existing (or
     * empty) nested directory produced no kernel event at all, and the
     * default Linux backend silently missed everything below the root.
     *
     * A directory joins the set only after its OWN listing completed:
     * the kernel likewise refuses to watch what PHP cannot open
     * (read-permission), so an unreadable branch is never watchable and
     * never listed, and every UnexpectedValueException the raw SPL walk
     * can raise (once-094: symlink loops, vanished-mid-descent races)
     * costs exactly its own branch, never the tree.
     *
     * @return list<string> absolute paths, root excluded
     */
    private function collectInotifySetupDirs(): array
    {
        $root = rtrim($this->scanRoot, '/');
        $watchable = [];
        /** @var list<string> $queue root-relative directory paths ('' is the root) */
        $queue = [''];
        while ($queue !== []) {
            $rel = (string)array_pop($queue);
            $abs = $rel === '' ? $root : $root . '/' . $rel;
            $children = [];
            try {
                foreach (new \DirectoryIterator($abs) as $entry) {
                    if ($entry->isDot() || !$entry->isDir() || $entry->isLink()) {
                        continue;
                    }
                    $childRel = $rel === '' ? $entry->getFilename() : $rel . '/' . $entry->getFilename();
                    if ($this->fileScanner->isExcluded($childRel . '/')) {
                        continue;
                    }
                    $children[] = $childRel;
                }
            } catch (\UnexpectedValueException $e) {
                // Per-entry catch, mirroring the scanner's posture: this
                // branch cannot be listed → it is not watchable → descend
                // no further; siblings already queued keep walking.
                $this->logger->info('FileWatcher: inotify setup walk skipped ' . $abs . ' (' . $e->getMessage() . ')');
                continue;
            }
            if ($rel !== '') {
                $watchable[] = $abs;
            }
            foreach ($children as $childRel) {
                $queue[] = $childRel;
            }
        }
        return $watchable;
    }

    // ─── Private backend starters ─────────────────────────────────────────────

    private function startInotify(LoopInterface $loop): void
    {
        $this->inotifyFd = @inotify_init();
        if (!is_resource($this->inotifyFd)) {
            $this->logger->warning('inotify_init failed, falling back to polling');
            $this->startPolling($loop);
            return;
        }

        stream_set_blocking($this->inotifyFd, false);

        $this->addInotifyWatch($this->scanRoot);
        foreach ($this->collectInotifySetupDirs() as $dir) {
            $this->addInotifyWatch($dir);
        }

        $loop->addReadStream($this->inotifyFd, function (): void {
            $events = @inotify_read($this->inotifyFd);
            if ($events === false || $events === []) {
                return;
            }
            $this->processInotifyEvents($events);
            $this->noteChangeHint();
        });

        $this->logger->info('FileWatcher: using inotify backend');
    }

    /**
     * Per-batch bookkeeping the kernel events exist for: drain destroyed
     * watches, adopt new watchable directories. WHICH paths changed is
     * decided by the flush diff, never by the raw mask — so no extension
     * filter and no snapshot edits happen in here (the once-051 fix stays
     * a wake-latency surface, not a second source of truth).
     *
     * @param array<array-key, array<string, mixed>> $events raw inotify_read batch
     */
    private function processInotifyEvents(array $events): void
    {
        foreach ($events as $event) {
            $wd = (int)$event['wd'];
            $mask = (int)$event['mask'];
            $basePath = $this->wdToPath[$wd] ?? null;
            if ($basePath === null) {
                continue;
            }
            if (($mask & (self::IN_DELETE_SELF | self::IN_IGNORED)) !== 0) {
                // once-094: the kernel retired (or is retiring) this watch;
                // drain the map so deletions cannot grow it unboundedly.
                // IN_IGNORED is always the last event for a wd, so a
                // delete-self entry dropped here can never shadow a wake.
                unset($this->wdToPath[$wd]);
                continue;
            }
            // Track new directories so deeper events keep arriving.
            if (($mask & self::IN_CREATE) !== 0 && $this->inotifyWatchableChild($basePath, (string)($event['name'] ?? ''))) {
                $this->addInotifyWatch($basePath . '/' . (string)($event['name'] ?? ''));
            }
        }
    }

    private function addInotifyWatch(string $path): void
    {
        $wd = @inotify_add_watch($this->inotifyFd, $path, self::INOTIFY_MASK);
        if ($wd === false) {
            return;
        }
        $this->wdToPath[$wd] = $path;
    }

    private function startPolling(LoopInterface $loop): void
    {
        $interval = $this->pollInterval;

        // A tick is only a HINT. The periodic walk stays the cost ceiling;
        // everything the window accumulates is diffed and dispatched once.
        $this->pollTimer = $loop->addPeriodicTimer($interval, function (): void {
            $this->noteChangeHint();
        });

        $this->logger->info('FileWatcher: using polling backend (interval=' . $interval . 's, debounce=' . $this->debounceSeconds . 's)');
    }

    private function stopInotify(): void
    {
        if ($this->inotifyFd !== null) {
            $fd = $this->inotifyFd;
            $wdToPath = $this->wdToPath;
            $this->inotifyFd = null;
            $this->wdToPath = [];
            if ($this->activeLoop !== null) {
                $this->activeLoop->removeReadStream($fd);
            }
            foreach (array_keys($wdToPath) as $wd) {
                @inotify_rm_watch($fd, $wd);
            }
            @fclose($fd);
        }
    }
}
