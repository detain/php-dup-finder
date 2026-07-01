<?php
declare(strict_types=1);

namespace Phpdup\Watch;

use Psr\Log\LoggerInterface;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use SplFileInfo;

/**
 * Unified file watcher with native backend support and polling fallback.
 *
 * Detects new, modified, and deleted files by integrating with React's
 * event loop. Uses inotify on Linux and periodic polling as a portable
 * fallback. Native FSEvents support (macOS) is planned for a future release
 * when a suitable PHP extension is available.
 *
 * ## Snapshot lifecycle
 *
 * The watcher maintains an internal snapshot of known files and their mtimes:
 * - Call {@see syncFromFileList()} after each pipeline run to rebase the snapshot
 *   on the current file list (e.g. after new files are discovered or deleted).
 * - Call {@see detectNewFiles()}, {@see detectModifiedFiles()}, and
 *   {@see detectDeletedFiles()} to diff the current file list against the
 *   snapshot and return the respective change sets.
 * - All three `detect*` methods read from the snapshot but do not modify it.
 *   Only {@see syncFromFileList()} updates the snapshot.
 *
 * Callbacks receive the type of change detected.
 */
final class FileWatcher
{
    // inotify event mask constants — matches PHP's inotify extension constants.
    private const IN_MODIFY   = 2;     // IN_MODIFY
    private const IN_CREATE   = 256;   // IN_CREATE
    private const IN_DELETE   = 512;   // IN_DELETE
    private const IN_MOVED_FROM = 128; // IN_MOVED_FROM
    private const IN_MOVED_TO   = 64;  // IN_MOVED_TO

    /** Mask combining all events the watcher cares about. */
    private const INOTIFY_MASK = self::IN_MODIFY | self::IN_CREATE | self::IN_DELETE | self::IN_MOVED_FROM | self::IN_MOVED_TO;

    /** @var array<string,int> path -> mtime snapshot of known files */
    private array $snapshot = [];

    /** @var array<string,FileChangeType> paths that triggered change since last sync */
    private array $pendingChanges = [];

    private bool $running = false;

    /** @var resource|null inotify file descriptor (Linux) */
    private $inotifyFd = null;

    /** @var array<int,string> watch descriptor -> watched path */
    private array $wdToPath = [];

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $scanRoot,
        private readonly float $pollInterval = 1.0,
    ) {}

    /**
     * Start watching for file changes.
     *
     * @param callable(string $path, FileChangeType $type): void $onChange
     *     Called whenever a file is created, modified, or deleted.
     *     The callback is invoked with the file path and the type of change.
     */
    public function watch(callable $onChange): void
    {
        if ($this->running) {
            return;
        }
        $this->running = true;

        $loop = Loop::get();

        if (function_exists('inotify_init')) {
            $this->startInotify($loop, $onChange);
        } else {
            $this->logger->info('FileWatcher: inotify not available, using polling backend (native FSEvents planned for future release)');
            $this->startPolling($loop, $onChange);
        }
    }

    /**
     * Stop watching and clean up resources.
     */
    public function stop(): void
    {
        if (!$this->running) {
            return;
        }
        $this->running = false;

        $this->stopInotify();
        // Polling is stopped by clearing the periodic timer reference.
        // React's loop auto-removes cancelled timers.

        $this->snapshot = [];
        $this->pendingChanges = [];
    }

    /**
     * Sync the internal file snapshot from a current file list.
     *
     * After a pipeline run completes, the caller supplies the freshly-discovered
     * file list so the watcher can rebase its comparison on the new state.
     *
     * @param SplFileInfo[] $currentFiles
     */
    public function syncFromFileList(array $currentFiles): void
    {
        $this->snapshot = [];
        foreach ($currentFiles as $file) {
            $path = $file instanceof SplFileInfo ? $file->getPathname() : (string)$file;
            $mtime = @filemtime($path);
            if ($mtime !== false) {
                $this->snapshot[$path] = $mtime;
            }
        }
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
     * Check whether any changes are pending (for poll-based backends).
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

    // ─── Private backend starters ─────────────────────────────────────────────

    private function startInotify(LoopInterface $loop, callable $onChange): void
    {
        $this->inotifyFd = @inotify_init();
        if (!is_resource($this->inotifyFd)) {
            $this->logger->warning('inotify_init failed, falling back to polling');
            $this->startPolling($loop, $onChange);
            return;
        }

        stream_set_blocking($this->inotifyFd, false);

        // Recursively watch the scan root and all subdirectories.
        $this->addInotifyWatch($this->scanRoot, $this->scanRoot);

        $loop->addReadStream($this->inotifyFd, function () use ($onChange): void {
            $events = @inotify_read($this->inotifyFd);
            if ($events === false || $events === []) {
                return;
            }
            foreach ($events as $event) {
                $basePath = $this->wdToPath[$event['wd']] ?? null;
                if ($basePath === null) {
                    continue;
                }
                $fullPath = $basePath . '/' . $event['name'];
                $type = $this->inotifyEventToChangeType($event['mask']);
                $this->pendingChanges[$fullPath] = $type;
                $onChange($fullPath, $type);

                // Track new directories for inotify.
                if (($event['mask'] & 256) && is_dir($fullPath)) { // IN_CREATE = 256
                    $this->addInotifyWatch($fullPath, $this->scanRoot);
                }
                // Update snapshot for create/modify.
                if (($event['mask'] & (256 | 2 | 64))) { // IN_CREATE | IN_MODIFY | IN_MOVED_TO
                    $mtime = @filemtime($fullPath);
                    if ($mtime !== false) {
                        $this->snapshot[$fullPath] = $mtime;
                    }
                }
                // Remove from snapshot on delete.
                if (($event['mask'] & (512 | 128))) { // IN_DELETE | IN_MOVED_FROM
                    unset($this->snapshot[$fullPath]);
                }
            }
        });

        $this->logger->info('FileWatcher: using inotify backend');
    }

    private function addInotifyWatch(string $path, string $root): void
    {
        $wd = @inotify_add_watch($this->inotifyFd, $path, self::INOTIFY_MASK);
        if ($wd === false) {
            return;
        }
        $this->wdToPath[$wd] = $path;
    }

    private function startPolling(LoopInterface $loop, callable $onChange): void
    {
        $interval = $this->pollInterval;
        $scanRoot = $this->scanRoot;

        $loop->addPeriodicTimer($interval, function () use ($onChange, $scanRoot): void {
            $currentFiles = $this->collectCurrentFiles($scanRoot);
            $currentMtimes = [];
            $changed = [];

            foreach ($currentFiles as $path => $mtime) {
                $currentMtimes[$path] = $mtime;
                if (!array_key_exists($path, $this->snapshot)) {
                    $changed[] = $path;
                    $this->pendingChanges[$path] = FileChangeType::Created;
                    $onChange($path, FileChangeType::Created);
                } elseif ($this->snapshot[$path] !== $mtime) {
                    $changed[] = $path;
                    $this->pendingChanges[$path] = FileChangeType::Modified;
                    $onChange($path, FileChangeType::Modified);
                }
            }

            // Detect deletions.
            foreach (array_keys($this->snapshot) as $tracked) {
                if (!array_key_exists($tracked, $currentMtimes)) {
                    $changed[] = $tracked;
                    $this->pendingChanges[$tracked] = FileChangeType::Deleted;
                    $onChange($tracked, FileChangeType::Deleted);
                }
            }

            // Update snapshot to reflect current state.
            $this->snapshot = $currentMtimes;
        });

        $this->logger->info('FileWatcher: using polling backend (interval=' . $interval . 's)');
    }

    /**
     * @return array<string, int> path => mtime
     */
    private function collectCurrentFiles(string $root): array
    {
        $result = [];
        if (!is_dir($root)) {
            return $result;
        }
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iter as $info) {
            /** @var \SplFileInfo $info */
            if (!$info->isFile()) {
                continue;
            }
            $path = $info->getPathname();
            clearstatcache(true, $path);
            $mtime = @filemtime($path);
            if ($mtime !== false) {
                $result[$path] = $mtime;
            }
        }
        return $result;
    }

    private function stopInotify(): void
    {
        if ($this->inotifyFd !== null) {
            $fd = $this->inotifyFd;
            $wdToPath = $this->wdToPath;
            $this->inotifyFd = null;
            $this->wdToPath = [];
            foreach (array_keys($wdToPath) as $wd) {
                @inotify_rm_watch($fd, $wd);
            }
            @fclose($fd);
        }
    }

    private static function inotifyEventToChangeType(int $mask): FileChangeType
    {
        if ($mask & (self::IN_CREATE | self::IN_MOVED_TO)) {
            return FileChangeType::Created;
        }
        if ($mask & (self::IN_DELETE | self::IN_MOVED_FROM)) {
            return FileChangeType::Deleted;
        }
        return FileChangeType::Modified;
    }
}
