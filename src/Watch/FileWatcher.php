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
 *    (`looksLikePhp` — reused, never forked here) and the shell-006
 *    symlink containment posture (out-of-root and root-re-entering links
 *    are pruned, so the watcher cannot follow them either), and
 *  - diff that list against the snapshot, rebase the snapshot, and
 *  - dispatch the consumer ONCE with the whole coalesced batch.
 *
 * Consequences the consumer can rely on: a burst of edits inside one
 * window is a single callback; a touch of a non-PHP file changes nothing
 * in the enumerated set and dispatches nothing at all.
 *
 * Config::exclude is deliberately NOT honored yet (that leg rides
 * once-S47, which owns the exclude-honoring coherence work). Self-
 * consistency is preserved by {@see resync()}: callers that want the
 * watcher's own view of the tree rebase on the watcher's own enumeration
 * instead of handing in the pipeline's (differently-excluded) file list.
 *
 * ## Snapshot lifecycle
 *
 * The watcher maintains an internal snapshot of known files and their mtimes:
 * - Call {@see resync()} after each pipeline run to rebase the snapshot on
 *   the watcher's own enumeration (what a flush would diff against anyway).
 * - {@see syncFromFileList()} remains for callers that seed from an external
 *   list (the TUI watch leg). Such a list is only consistent with this
 *   watcher when it matches the watcher's own no-excludes walk — an
 *   exclude-shaped pipeline list leaves files out of the snapshot and the
 *   next flush manufactures a phantom Created/Deleted batch from the
 *   mismatch. The TUI leg currently carries exactly that mismatch; its cure
 *   (exclude-honoring in both backends, or a Command-leg flip to resync())
 *   rides once-S47.
 * - Call {@see detectNewFiles()}, {@see detectModifiedFiles()}, and
 *   {@see detectDeletedFiles()} to diff a given file list against the
 *   snapshot and return the respective change sets.
 * - All three `detect*` methods read from the snapshot but do not modify it.
 *   Only {@see syncFromFileList()}, {@see resync()} and an internal flush
 *   update the snapshot.
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
    private const IN_MODIFY     = 2;     // IN_MODIFY
    private const IN_CREATE     = 256;   // IN_CREATE
    private const IN_DELETE     = 512;   // IN_DELETE
    private const IN_MOVED_FROM = 128;   // IN_MOVED_FROM
    private const IN_MOVED_TO   = 64;    // IN_MOVED_TO

    /** Mask combining all events the watcher cares about. */
    private const INOTIFY_MASK = self::IN_MODIFY | self::IN_CREATE | self::IN_DELETE | self::IN_MOVED_FROM | self::IN_MOVED_TO;

    /** @var array<string,int> path -> mtime snapshot of known files */
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
     * The watchable-file enumerator. Empty exclude globs on purpose: the
     * PHP predicate + symlink containment come from FileScanner itself,
     * while exclude-honoring is once-S47's declared scope. warnOnSkip
     * stays off — the pipeline's own scan renders skip notes once per
     * pass; the watcher must not spray them from a timer callback.
     */
    private readonly FileScanner $fileScanner;

    /** @param float $pollInterval seconds between poll-backend ticks */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $scanRoot,
        private readonly float $pollInterval = 1.0,
        private readonly ?LoopInterface $loop = null,
        private readonly string $backend = self::BACKEND_AUTO,
        private readonly float $debounceSeconds = self::DEBOUNCE_SECONDS,
    ) {
        if (!in_array($this->backend, [self::BACKEND_AUTO, self::BACKEND_POLL, self::BACKEND_INOTIFY], true)) {
            throw new \InvalidArgumentException("FileWatcher: unknown backend '{$this->backend}' (expected auto, poll or inotify)");
        }
        $this->fileScanner = new FileScanner([], false);
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
     * flush, and the diff state all drop together.
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
     * root (FileScanner::scan — PHP predicate + containment included).
     * This is exactly the set the next flush diffs against, so a resync
     * after a completed pass can never manufacture phantom "created"
     * events out of an exclude-shape mismatch between the pipeline's
     * file list and the watcher's walk.
     */
    public function resync(): void
    {
        $this->rebaseSnapshot($this->collectWatchableFiles());
        $this->pendingChanges = [];
    }

    /**
     * Sync the internal file snapshot from a caller-supplied file list.
     *
     * Consistency note (why this is NOT generally "just seed the list"):
     * flush() diffs against this watcher's OWN no-excludes enumeration, so
     * a caller-supplied list shaped by different rules (the pipeline's
     * exclude-shaped file list the TUI watch leg currently seeds with)
     * leaves excluded files out of the snapshot and the next flush
     * dispatches them as a phantom Created batch. The TUI leg's cure
     * (exclude-honoring in both backends, or flipping the leg to
     * {@see resync()}) rides once-S47. WatchRunner already uses
     * {@see resync()} instead so enumeration and diffing share one
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
     * Detect files whose mtime changed since the last snapshot.
     *
     * @param SplFileInfo[] $currentFiles
     * @return array<string> paths of modified files
     */
    public function detectModifiedFiles(array $currentFiles): array
    {
        $modified = [];
        foreach ($currentFiles as $file) {
            $path = $file instanceof SplFileInfo ? $file->getPathname() : (string)$file;
            if (!array_key_exists($path, $this->snapshot)) {
                continue;
            }
            clearstatcache(true, $path);
            $mtime = @filemtime($path);
            if ($mtime !== false && $mtime !== $this->snapshot[$path]) {
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
     * An empty batch (a .txt touch, a file behind an out-of-root symlink)
     * stays silent. The diff is mtime-keyed, so a re-write that bumps the
     * mtime — even an editor re-writing identical bytes — classifies as
     * Modified and DOES dispatch; only a re-touch within the same second
     * stays silent on mtime resolution. Size-aware content suppression is
     * once-S47's poll-key leg.
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
     * @param SplFileInfo[] $fileInfos
     */
    private function rebaseSnapshot(array $fileInfos): void
    {
        $this->snapshot = [];
        foreach ($fileInfos as $file) {
            $path = $file instanceof SplFileInfo ? $file->getPathname() : (string)$file;
            $mtime = @filemtime($path);
            if ($mtime !== false) {
                $this->snapshot[$path] = $mtime;
            }
        }
    }

    /**
     * Enumerate watchable files via FileScanner::scan — the single source
     * of truth for "is this a PHP file" (its private looksLikePhp is
     * reused, not forked) and for symlink containment (shell-006):
     * out-of-root links are pruned before the walk descends, so the poll
     * path cannot leak events for files outside the scan root.
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

        $loop->addReadStream($this->inotifyFd, function (): void {
            $events = @inotify_read($this->inotifyFd);
            if ($events === false || $events === []) {
                return;
            }
            foreach ($events as $event) {
                $basePath = $this->wdToPath[$event['wd']] ?? null;
                if ($basePath === null) {
                    continue;
                }
                // Track new directories so deeper events keep arriving.
                // This is the ONLY per-event work: WHICH paths changed is
                // decided by the flush diff, never by the raw mask — so no
                // extension filter and no snapshot edits happen in here.
                if ((((int)$event['mask']) & self::IN_CREATE) && is_dir($basePath . '/' . $event['name'])) {
                    $this->addInotifyWatch($basePath . '/' . $event['name']);
                }
            }
            $this->noteChangeHint();
        });

        $this->logger->info('FileWatcher: using inotify backend');
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
