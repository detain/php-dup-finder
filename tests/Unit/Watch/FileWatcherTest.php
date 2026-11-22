<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Watch;

use PHPUnit\Framework\TestCase;
use Phpdup\Watch\FileChangeType;
use Phpdup\Watch\FileWatcher;
use Psr\Log\NullLogger;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use SplFileInfo;
use Symfony\Component\Console\Output\NullOutput;

/**
 * @covers \Phpdup\Watch\FileWatcher
 */
final class FileWatcherTest extends TestCase
{
    private string $tmpDir;

    /** @var list<string> extra trees (symlink targets outside the watched root) */
    private array $extraDirs = [];

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/phpdup_watch_test_' . uniqid('', true);
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
        foreach ($this->extraDirs as $dir) {
            $this->removeDir($dir);
        }
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iter as $path) {
            if ($path->isLink()) {
                // Check before isDir(): SplFileInfo::isDir() follows links,
                // so a directory symlink would otherwise reach rmdir().
                @unlink((string) $path);
            } elseif ($path->isDir()) {
                @rmdir((string) $path);
            } else {
                @unlink((string) $path);
            }
        }
        @rmdir($dir);
    }

    private function createFile(string $path, string $content = 'test'): void
    {
        file_put_contents($this->tmpDir . '/' . $path, $content);
    }

    /** @return SplFileInfo[] */
    private function splFilesFromPaths(array $paths): array
    {
        return array_map(fn(string $p) => new SplFileInfo($p), $paths);
    }

    public function testDetectNewFilesFindsNewlyAddedFiles(): void
    {
        $this->createFile('a.php');
        $this->createFile('b.php');

        $logger = new NullLogger();
        $watcher = new FileWatcher($logger, $this->tmpDir, 1.0);

        // Initial snapshot has files a.php and b.php
        $initialFiles = $this->splFilesFromPaths([
            $this->tmpDir . '/a.php',
            $this->tmpDir . '/b.php',
        ]);
        $watcher->syncFromFileList($initialFiles);

        // Add a new file c.php
        $this->createFile('c.php');
        $currentFiles = $this->splFilesFromPaths([
            $this->tmpDir . '/a.php',
            $this->tmpDir . '/b.php',
            $this->tmpDir . '/c.php',
        ]);

        $newFiles = $watcher->detectNewFiles($currentFiles);

        $this->assertCount(1, $newFiles);
        $this->assertStringEndsWith('c.php', $newFiles[0]->getPathname());
    }

    public function testDetectNewFilesReturnsEmptyWhenNoNewFiles(): void
    {
        $this->createFile('a.php');
        $this->createFile('b.php');

        $logger = new NullLogger();
        $watcher = new FileWatcher($logger, $this->tmpDir, 1.0);

        $files = $this->splFilesFromPaths([
            $this->tmpDir . '/a.php',
            $this->tmpDir . '/b.php',
        ]);
        $watcher->syncFromFileList($files);

        // Same files, no new ones
        $newFiles = $watcher->detectNewFiles($files);

        $this->assertCount(0, $newFiles);
    }

    public function testDetectDeletedFilesFindsRemovedFiles(): void
    {
        $this->createFile('a.php');
        $this->createFile('b.php');
        $this->createFile('c.php');

        $logger = new NullLogger();
        $watcher = new FileWatcher($logger, $this->tmpDir, 1.0);

        $allFiles = $this->splFilesFromPaths([
            $this->tmpDir . '/a.php',
            $this->tmpDir . '/b.php',
            $this->tmpDir . '/c.php',
        ]);
        $watcher->syncFromFileList($allFiles);

        // Delete c.php
        unlink($this->tmpDir . '/c.php');
        $currentFiles = $this->splFilesFromPaths([
            $this->tmpDir . '/a.php',
            $this->tmpDir . '/b.php',
        ]);

        $deleted = $watcher->detectDeletedFiles($currentFiles);

        $this->assertCount(1, $deleted);
        $this->assertStringEndsWith('c.php', $deleted[0]);
    }

    public function testDetectDeletedFilesReturnsEmptyWhenNoDeletions(): void
    {
        $this->createFile('a.php');
        $this->createFile('b.php');

        $logger = new NullLogger();
        $watcher = new FileWatcher($logger, $this->tmpDir, 1.0);

        $files = $this->splFilesFromPaths([
            $this->tmpDir . '/a.php',
            $this->tmpDir . '/b.php',
        ]);
        $watcher->syncFromFileList($files);

        // Same files, no deletions
        $deleted = $watcher->detectDeletedFiles($files);

        $this->assertCount(0, $deleted);
    }

    public function testDetectModifiedFilesFindsChangedMtime(): void
    {
        $this->createFile('a.php', 'original content');
        clearstatcache(true, $this->tmpDir . '/a.php');

        $logger = new NullLogger();
        $watcher = new FileWatcher($logger, $this->tmpDir, 1.0);

        $filePath = $this->tmpDir . '/a.php';
        $files = $this->splFilesFromPaths([$filePath]);
        $watcher->syncFromFileList($files);

        // Wait for next second to ensure mtime changes (filesystem has 1s precision)
        sleep(1);
        clearstatcache(true, $filePath);

        // Touch the file to update its mtime
        touch($filePath);
        clearstatcache(true, $filePath);

        $modified = $watcher->detectModifiedFiles($files);

        $this->assertCount(1, $modified);
        $this->assertStringEndsWith('a.php', $modified[0]);
    }

    public function testDetectModifiedFilesReturnsEmptyWhenUnchanged(): void
    {
        $this->createFile('a.php', 'content');
        clearstatcache();

        $logger = new NullLogger();
        $watcher = new FileWatcher($logger, $this->tmpDir, 1.0);

        $filePath = $this->tmpDir . '/a.php';
        $files = $this->splFilesFromPaths([$filePath]);

        // Read mtime before syncing
        $mtimeBefore = filemtime($filePath);
        $watcher->syncFromFileList($files);

        // Immediately check - mtime shouldn't have changed
        $modified = $watcher->detectModifiedFiles($files);

        $this->assertCount(0, $modified);
    }

    public function testSyncFromFileListUpdatesSnapshot(): void
    {
        $this->createFile('a.php');
        $this->createFile('b.php');
        $this->createFile('c.php');

        $logger = new NullLogger();
        $watcher = new FileWatcher($logger, $this->tmpDir, 1.0);

        // Initial sync with a.php and b.php
        $initialFiles = $this->splFilesFromPaths([
            $this->tmpDir . '/a.php',
            $this->tmpDir . '/b.php',
        ]);
        $watcher->syncFromFileList($initialFiles);

        // New file list includes all three
        $newFiles = $this->splFilesFromPaths([
            $this->tmpDir . '/a.php',
            $this->tmpDir . '/b.php',
            $this->tmpDir . '/c.php',
        ]);

        // Before re-sync, detectNewFiles finds c.php
        $new = $watcher->detectNewFiles($newFiles);
        $this->assertCount(1, $new);

        // After re-sync, snapshot is updated
        $watcher->syncFromFileList($newFiles);

        // Now detectNewFiles finds nothing
        $newAfterSync = $watcher->detectNewFiles($newFiles);
        $this->assertCount(0, $newAfterSync);
    }

    public function testStopSetsRunningToFalse(): void
    {
        $logger = new NullLogger();
        $watcher = new FileWatcher($logger, $this->tmpDir, 1.0);

        $this->assertFalse($watcher->isRunning());

        // Cannot directly start watch() in unit test without event loop
        // But we can call stop() which should be safe even when not running
        $watcher->stop();

        $this->assertFalse($watcher->isRunning());
    }

    public function testStopIsIdempotent(): void
    {
        $logger = new NullLogger();
        $watcher = new FileWatcher($logger, $this->tmpDir, 1.0);

        // Calling stop() multiple times should not cause issues
        $watcher->stop();
        $watcher->stop(); // Should not throw
        $watcher->stop(); // Should not throw

        $this->assertFalse($watcher->isRunning());
    }

    public function testPendingChangesReturnsCurrentPendingChanges(): void
    {
        $logger = new NullLogger();
        $watcher = new FileWatcher($logger, $this->tmpDir, 1.0);

        // Initially empty
        $this->assertSame([], $watcher->pendingChanges());

        // After sync, still empty (syncFromFileList clears pending changes)
        $files = $this->splFilesFromPaths([
            $this->tmpDir . '/a.php',
        ]);
        $watcher->syncFromFileList($files);
        $this->assertSame([], $watcher->pendingChanges());
    }

    public function testClearPendingChangesEmptiesTheQueue(): void
    {
        $logger = new NullLogger();
        $watcher = new FileWatcher($logger, $this->tmpDir, 1.0);

        $watcher->clearPendingChanges();
        $this->assertSame([], $watcher->pendingChanges());
    }

    public function testDetectNewFilesWithEmptySnapshotFindsAllCurrentFiles(): void
    {
        $this->createFile('a.php');
        $this->createFile('b.php');

        $logger = new NullLogger();
        $watcher = new FileWatcher($logger, $this->tmpDir, 1.0);

        // No snapshot yet
        $currentFiles = $this->splFilesFromPaths([
            $this->tmpDir . '/a.php',
            $this->tmpDir . '/b.php',
        ]);

        $newFiles = $watcher->detectNewFiles($currentFiles);

        $this->assertCount(2, $newFiles);
    }

    public function testDetectDeletedFilesWithEmptyCurrentFilesFindsAllSnapshotFiles(): void
    {
        $this->createFile('a.php');
        $this->createFile('b.php');

        $logger = new NullLogger();
        $watcher = new FileWatcher($logger, $this->tmpDir, 1.0);

        // Snapshot has files but current list is empty
        $snapshotFiles = $this->splFilesFromPaths([
            $this->tmpDir . '/a.php',
            $this->tmpDir . '/b.php',
        ]);
        $watcher->syncFromFileList($snapshotFiles);

        $deleted = $watcher->detectDeletedFiles([]);

        $this->assertCount(2, $deleted);
    }

    // ─── shell-S33: debounce coalescing, PHP filtering, no symlink follow ─────

    /**
     * Build a poll-forced watcher on an injected deterministic loop; every
     * consumer dispatch is captured into $dispatches as
     * [representativePath, representativeType, batchMap].
     *
     * @param list<array{0:string,1:FileChangeType,2:array<string,FileChangeType>}> $dispatches
     * @param list<string> $excludeGlobs the watcher's exclude view (once-S47)
     * @return array{0:FileWatcher,1:WatchTestLoop}
     */
    private function pollWatcher(array &$dispatches, ?string $root = null, array $excludeGlobs = []): array
    {
        $loop = new WatchTestLoop();
        $watcher = new FileWatcher(
            new NullLogger(),
            $root ?? $this->tmpDir,
            1.0,
            $loop,
            FileWatcher::BACKEND_POLL,
            FileWatcher::DEBOUNCE_SECONDS,
            $excludeGlobs,
        );
        $watcher->watch(function (string $path, FileChangeType $type, array $batch = []) use (&$dispatches): void {
            $dispatches[] = [$path, $type, $batch];
        });
        return [$watcher, $loop];
    }

    /**
     * Acceptance (shell-024): touch of a .txt/.md file must NOT trigger a
     * re-analysis. The flush diff enumerates through FileScanner (its
     * looksLikePhp predicate — reused, not forked), so non-PHP bytes never
     * enter the snapshot and a non-PHP burst dispatches nothing at all.
     */
    public function testNonPhpTouchesDispatchNothing(): void
    {
        $dispatches = [];
        [$watcher, $loop] = $this->pollWatcher($dispatches);

        $this->createFile('notes.txt', 'hello');
        $this->createFile('readme.md', '# hi');

        $loop->firePeriodic(); // hint only
        $loop->fireTimers();   // debounced flush: PHP-empty diff -> silent

        $this->assertSame([], $dispatches, 'non-PHP touches must not re-analyze');
        $this->assertSame([], $watcher->pendingChanges());
    }

    /**
     * Acceptance (shell-024): 5 .php files created inside one debounce
     * window produce EXACTLY ONE dispatch carrying all five (the event-
     * coalescing proof, fake backend, zero wall-clock).
     */
    public function testPhpBurstInsideWindowCoalescesIntoOneDispatch(): void
    {
        $dispatches = [];
        [, $loop] = $this->pollWatcher($dispatches);

        foreach (['a.php', 'b.php', 'c.php', 'd.php', 'e.php'] as $f) {
            $this->createFile($f, "<?php\nclass X { }\n");
        }

        $loop->firePeriodic();
        $loop->fireTimers();

        $this->assertCount(1, $dispatches);
        [$repPath, $repType, $batch] = $dispatches[0];
        $this->assertCount(5, $batch);
        $this->assertSame(FileChangeType::Created, $repType);
        $this->assertArrayHasKey($repPath, $batch);

        $paths = array_keys($batch);
        sort($paths);
        $expected = [$this->tmpDir . '/a.php', $this->tmpDir . '/b.php', $this->tmpDir . '/c.php',
                     $this->tmpDir . '/d.php', $this->tmpDir . '/e.php'];
        $this->assertSame($expected, $paths);
    }

    /**
     * After a flush, the window re-arms on the next hint: a second burst
     * is its own single dispatch (no "watch once and go deaf").
     */
    public function testFlushReArmsAndNextBurstDispatchesAgain(): void
    {
        $dispatches = [];
        [, $loop] = $this->pollWatcher($dispatches);

        $this->createFile('a.php');
        $loop->firePeriodic();
        $loop->fireTimers();
        $this->assertCount(1, $dispatches);

        $this->createFile('f.php');
        $loop->firePeriodic();
        $loop->fireTimers();

        $this->assertCount(2, $dispatches);
        $this->assertSame([$this->tmpDir . '/f.php'], array_keys($dispatches[1][2]));
    }

    /**
     * Modified and deleted files ride the same coalesced batch with their
     * own change types (mtime nudged explicitly — no sleep, CI-deterministic).
     */
    public function testModifyAndDeleteSurfaceInBatchWithTypes(): void
    {
        $this->createFile('a.php');
        $this->createFile('gone.php');

        $dispatches = [];
        [$watcher, $loop] = $this->pollWatcher($dispatches);

        $loop->firePeriodic();
        $loop->fireTimers();
        $this->assertCount(2, $dispatches[0][2]); // initial Created batch

        touch($this->tmpDir . '/a.php', time() + 10);
        unlink($this->tmpDir . '/gone.php');

        $loop->firePeriodic();
        $loop->fireTimers();

        $this->assertCount(2, $dispatches);
        $batch = $dispatches[1][2];
        $this->assertSame(FileChangeType::Modified, $batch[$this->tmpDir . '/a.php']);
        $this->assertSame(FileChangeType::Deleted, $batch[$this->tmpDir . '/gone.php']);
        $this->assertCount(2, $batch);

        // The ledger accumulates across flushes — only an external
        // sync/resync clears it (a.php's Created entry was overwritten by
        // its later Modified, which is exactly the last-known-state shape
        // the old per-event backend produced).
        $pending = $watcher->pendingChanges();
        $keys = array_keys($pending);
        sort($keys);
        $this->assertSame(
            [$this->tmpDir . '/a.php', $this->tmpDir . '/gone.php'],
            $keys,
        );
        $this->assertSame(FileChangeType::Modified, $pending[$this->tmpDir . '/a.php']);
        $this->assertSame(FileChangeType::Deleted, $pending[$this->tmpDir . '/gone.php']);
    }

    /**
     * Acceptance (shell-024): the poll backend must not follow out-root
     * symlinks. Enumeration runs through FileScanner::scan, inheriting the
     * S20 containment posture (out-of-root links pruned before descent,
     * root-re-entry pruned, in-root links allowed — no regression, no fork).
     */
    public function testPollBackendDoesNotFollowOutRootSymlinks(): void
    {
        if (!function_exists('symlink')) {
            $this->markTestSkipped('symlink() unavailable');
        }
        $outside = $this->tmpDir . '_outside';
        mkdir($outside, 0777, true);
        $this->extraDirs[] = $outside;
        file_put_contents($outside . '/victim.php', "<?php\n");

        $this->createFile('a.php');
        symlink($outside, $this->tmpDir . '/linkdir');

        $dispatches = [];
        [, $loop] = $this->pollWatcher($dispatches);

        $loop->firePeriodic();
        $loop->fireTimers();

        $this->assertCount(1, $dispatches);
        $paths = array_keys($dispatches[0][2]);
        $this->assertSame([$this->tmpDir . '/a.php'], $paths);

        // A real change under the linked tree must stay invisible: the
        // out-of-root link is pruned, so victim.php is never enumerated.
        touch($outside . '/victim.php', time() + 10);
        $loop->firePeriodic();
        $loop->fireTimers();
        $this->assertCount(1, $dispatches);

        // In-root symlink to an in-root file still surfaces (S20 posture).
        symlink($this->tmpDir . '/a.php', $this->tmpDir . '/mirror.php');
        $loop->firePeriodic();
        $loop->fireTimers();
        $this->assertCount(2, $dispatches);
        $this->assertSame([$this->tmpDir . '/mirror.php'], array_keys($dispatches[1][2]));
    }

    /**
     * resync() rebases on the watcher's OWN enumeration, so a caller-side
     * list shaped differently (WatchRunner's excluded pipeline list) can
     * no longer poison the snapshot into re-firing phantom Created events.
     */
    public function testResyncUsesWatcherEnumeration(): void
    {
        $this->createFile('a.php');

        $dispatches = [];
        [$watcher, $loop] = $this->pollWatcher($dispatches);

        // Poison: a caller-shaped sync forgets a.php entirely…
        $watcher->syncFromFileList([]);

        // …but resync() re-adopts the watcher's own walk: a.php is known,
        // the next flush sees no change and stays silent.
        $watcher->resync();
        $loop->firePeriodic();
        $loop->fireTimers();

        $this->assertSame([], $dispatches);
    }

    /**
     * stop() cancels the armed debounce timer and neuters the consumer:
     * a hint in flight never dispatches into a torn-down watcher.
     */
    public function testStopCancelsArmedFlush(): void
    {
        $dispatches = [];
        [$watcher, $loop] = $this->pollWatcher($dispatches);

        $this->createFile('a.php');
        $loop->firePeriodic(); // arms the flush timer

        $watcher->stop();

        $loop->fireTimers(); // cancelled -> must not fire
        $this->assertSame([], $dispatches);
        $this->assertFalse($watcher->isRunning());
    }

    public function testUnknownBackendIsRefusedAtConstruction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FileWatcher(new NullLogger(), $this->tmpDir, 1.0, null, 'kitty');
    }

    public function testDebounceConstantLandsInTheSuggestedBand(): void
    {
        // shell-024 named 150–500 ms; the chosen tunable must stay inside.
        $this->assertGreaterThanOrEqual(0.15, FileWatcher::DEBOUNCE_SECONDS);
        $this->assertLessThanOrEqual(0.5, FileWatcher::DEBOUNCE_SECONDS);
    }

    // ─── shell-S33 fix r1: mask membership + arm-once window pins (L-1, N-1) ──

    /**
     * L-1: INOTIFY_MASK membership must be behaviorally pinned, not ride on
     * phpstan's orphaned-const lint (the 77cd9a2 regression — IN_DELETE
     * dropping out of the mask — measured GREEN in the review battery).
     * ext-inotify is not loadable in this environment, so the pin covers
     * the two environment-independent halves of the contract: (1) the
     * class-local wake bits hold their canonical Linux inotify values and
     * the mask admits every one of them (a missing bit means that event
     * class can never wake the debounce), with a live-extension
     * cross-check when one happens to be loaded; (2) the hint an
     * IN_DELETE wake would feed produces — through the same hint → flush →
     * diff chain the inotify backend runs — a Deleted classification.
     */
    public function testInotifyMaskWakesOnDeleteAndFlushClassifiesDeleted(): void
    {
        $ref = new \ReflectionClass(FileWatcher::class);
        $mask = $ref->getReflectionConstant('INOTIFY_MASK')->getValue();
        $this->assertIsInt($mask);

        $canonical = [
            'IN_MODIFY' => 2,
            'IN_MOVED_TO' => 64,
            'IN_MOVED_FROM' => 128,
            'IN_CREATE' => 256,
            'IN_DELETE' => 512,
            // once-S47 (php-dup-finder-once-094): the self-destroy pair —
            // without either bit in the mask the wd map could never drain.
            'IN_DELETE_SELF' => 1024,
            'IN_IGNORED' => 32768,
        ];
        foreach ($canonical as $name => $value) {
            $bit = $ref->getReflectionConstant($name)->getValue();
            $this->assertSame($value, $bit, "FileWatcher::{$name} must hold the canonical inotify bit");
            $this->assertNotSame(0, $mask & $bit, "INOTIFY_MASK must admit {$name} or that event class never wakes the watcher");
            if (defined($name)) {
                $this->assertSame(constant($name), $bit, "class-local {$name} drifted from the loaded inotify extension");
            }
        }

        // Behavioural half: an rm of a watched PHP file, hinted exactly as
        // an IN_DELETE wake would hint, classifies as Deleted at flush.
        $this->createFile('a.php');
        $this->createFile('gone.php');

        $dispatches = [];
        [, $loop] = $this->pollWatcher($dispatches);
        $loop->firePeriodic();
        $loop->fireTimers(); // seed snapshot with both files

        unlink($this->tmpDir . '/gone.php');
        $loop->firePeriodic(); // the hint an IN_DELETE wake produces
        $loop->fireTimers();   // flush decides the truth

        $this->assertCount(2, $dispatches);
        $batch = $dispatches[1][2];
        $this->assertSame([$this->tmpDir . '/gone.php'], array_keys($batch));
        $this->assertSame(FileChangeType::Deleted, $batch[$this->tmpDir . '/gone.php']);
    }

    /**
     * N-1: the debounce window is ONE armed one-shot per burst. The
     * production cost guarantee ("thousands of events → one walk per
     * window, not one per event") rests on noteChangeHint()'s
     * flushTimer-exists short-circuit; before this pin, a dispatch-at-hint
     * mutation tripped only testStopCancelsArmedFlush incidentally. No test
     * fired 2+ hints before firing timers — now one does, and asserts the
     * pending-timer count directly.
     */
    public function testSecondHintInsideWindowDoesNotArmSecondTimer(): void
    {
        $dispatches = [];
        [, $loop] = $this->pollWatcher($dispatches);

        $this->createFile('a.php');
        $loop->firePeriodic(); // arms the window
        $loop->firePeriodic(); // merges into the open window …
        $loop->firePeriodic(); // … however many hints arrive

        $this->assertSame(1, $loop->countPendingOneShots(), 'hints inside an open window must not stack timers');

        $loop->fireTimers();
        $this->assertCount(1, $dispatches, 'one window = one dispatch');
        $this->assertSame(0, $loop->countPendingOneShots());

        // After expiry the window is re-armable, still singly.
        $loop->firePeriodic();
        $this->assertSame(1, $loop->countPendingOneShots());
    }

    // ─── once-S47: size-aware poll key, exclude-honoring view, recursive setup ──

    /**
     * once-094 (A-06/XP-06): the poll key carries SIZE, not just mtime.
     * A rewrite that lands within the same second but moves bytes
     * (exFAT/FAT cards, editor fsync races — mtime re-pinned to the exact
     * original to force the resolution edge deterministically, zero
     * sleep) must classify as Modified. Mutation: drop the size term from
     * statKey() and this dispatch vanishes.
     */
    public function testSameSecondResizeCountsAsModified(): void
    {
        $this->createFile('a.php', 'short');
        $mtime = filemtime($this->tmpDir . '/a.php');

        $dispatches = [];
        [, $loop] = $this->pollWatcher($dispatches);

        $loop->firePeriodic();
        $loop->fireTimers(); // seed snapshot at 'short' bytes
        $this->assertCount(1, $dispatches);

        $this->createFile('a.php', 'much longer content now');
        touch($this->tmpDir . '/a.php', (int)$mtime);
        clearstatcache();

        $loop->firePeriodic();
        $loop->fireTimers();

        $this->assertCount(2, $dispatches);
        $batch = $dispatches[1][2];
        $this->assertSame([$this->tmpDir . '/a.php'], array_keys($batch));
        $this->assertSame(FileChangeType::Modified, $batch[$this->tmpDir . '/a.php']);
    }

    /**
     * once-086: the poll backend enumerates under Config::exclude. An
     * excluded tree enters neither the Created batch nor later diffs —
     * churn under vendor/ must never re-analyze.
     */
    public function testPollBackendHonorsExcludes(): void
    {
        mkdir($this->tmpDir . '/vendor/pkg', 0777, true);
        $this->createFile('a.php');
        file_put_contents($this->tmpDir . '/vendor/pkg/loaded.php', "<?php\n");

        $dispatches = [];
        [, $loop] = $this->pollWatcher($dispatches, null, ['**/vendor/**']);

        $loop->firePeriodic();
        $loop->fireTimers();

        $this->assertCount(1, $dispatches);
        $this->assertSame([$this->tmpDir . '/a.php'], array_keys($dispatches[0][2]),
            'the excluded tree must not enter the Created batch');

        touch($this->tmpDir . '/vendor/pkg/loaded.php', time() + 10);
        $loop->firePeriodic();
        $loop->fireTimers();
        $this->assertCount(1, $dispatches, 'an edit under an excluded dir must not wake a dispatch');
    }

    /**
     * once-S47: a mid-life view swap (WatchRunner's per-dispatch
     * applyExcludes posture) rebases the snapshot on the NEW view, so the
     * next flush dispatches nothing — a stale vendor key left behind would
     * otherwise surface as a phantom Deleted batch.
     */
    public function testApplyExcludesRebasesWithoutPhantomDeletions(): void
    {
        mkdir($this->tmpDir . '/vendor', 0777, true);
        $this->createFile('a.php');
        file_put_contents($this->tmpDir . '/vendor/v.php', "<?php\n");

        $dispatches = [];
        [$watcher, $loop] = $this->pollWatcher($dispatches); // glob-free start

        $loop->firePeriodic();
        $loop->fireTimers();
        $this->assertCount(2, $dispatches[0][2]); // both files known

        $watcher->applyExcludes(['**/vendor/**']);

        $loop->firePeriodic();
        $loop->fireTimers();
        $this->assertCount(1, $dispatches,
            'the excludes swap itself must fabricate no Deleted for the just-excluded file');
        // applyExcludes rebases through resync(): the tracked view AND the
        // accumulated ledger speak the new exclude view from that moment.
        $this->assertSame([], $watcher->pendingChanges());
    }

    /**
     * once-051: the inotify wake set is RECURSIVE. The setup walk returns
     * every existing watchable directory — including pre-existing empty
     * ones (an edit inside them never fires an event unless the directory
     * itself is watched: the finding's core asymmetry) — while honoring
     * the exclude view and never descending a symlink (shell-006 posture;
     * the ancestor-pointing link pair doubles as the once-094 symlink-loop
     * fixture: with link descent the walk would not terminate).
     */
    public function testCollectInotifySetupDirsIsRecursiveExcludeAwareAndLinkSafe(): void
    {
        mkdir($this->tmpDir . '/src/Deep/Deeper', 0777, true);
        mkdir($this->tmpDir . '/src/Empty');
        mkdir($this->tmpDir . '/vendor/pkg', 0777, true);
        $this->createFile('src/Deep/Deeper/leaf.php');
        file_put_contents($this->tmpDir . '/vendor/pkg/loaded.php', "<?php\n");

        $outside = $this->tmpDir . '_loopoutside';
        mkdir($outside . '/inner', 0777, true);
        $this->extraDirs[] = $outside;
        symlink($this->tmpDir, $outside . '/inner/back'); // ancestor-pointing
        symlink($outside, $this->tmpDir . '/looplink');   // link into the loop

        $watcher = new FileWatcher(
            new NullLogger(), $this->tmpDir, 1.0, null,
            FileWatcher::BACKEND_POLL, FileWatcher::DEBOUNCE_SECONDS, ['**/vendor/**'],
        );
        $collect = new \ReflectionMethod(FileWatcher::class, 'collectInotifySetupDirs');

        /** @var list<string> $dirs */
        $dirs = $collect->invoke($watcher);
        $rel = array_map(fn(string $d): string => substr($d, strlen($this->tmpDir) + 1), $dirs);
        sort($rel);

        $this->assertContains('src', $rel);
        $this->assertContains('src/Deep', $rel);
        $this->assertContains('src/Deep/Deeper', $rel, 'a nested directory holding a watched file must be watched (once-051)');
        $this->assertContains('src/Empty', $rel, 'a pre-existing EMPTY directory must be watched too — its first new file has no other wake source');
        $this->assertNotContains('vendor', $rel);
        $this->assertNotContains('vendor/pkg', $rel);
        $this->assertNotContains('looplink', $rel, 'never watch through a symlink');
        foreach ($rel as $r) {
            $this->assertStringNotContainsString('inner', $r, 'the symlink loop must never be descended');
        }
    }

    /**
     * once-094: the setup walk catches UnexpectedValueException PER
     * DIRECTORY (the scanner's fail-soft posture): a branch that cannot
     * be listed is not watchable and loses its own subtree, the walk
     * continues, nothing escapes. Mutation: delete the catch and this
     * test dies with the raw SPL throw.
     */
    public function testUnreadableBranchNeverKillsSetupWalk(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root bypasses DAC: a chmod-000 directory stays readable');
        }
        mkdir($this->tmpDir . '/locked/hidden', 0777, true);
        mkdir($this->tmpDir . '/ok');
        $this->createFile('ok/fine.php');
        chmod($this->tmpDir . '/locked', 0000);

        $watcher = new FileWatcher(new NullLogger(), $this->tmpDir, 1.0);
        $collect = new \ReflectionMethod(FileWatcher::class, 'collectInotifySetupDirs');
        try {
            /** @var list<string> $dirs */
            $dirs = $collect->invoke($watcher); // must NOT throw
            $this->assertContains($this->tmpDir . '/ok', $dirs, 'the walk continues past the unreadable branch');
            $this->assertNotContains($this->tmpDir . '/locked', $dirs);
            $this->assertNotContains($this->tmpDir . '/locked/hidden', $dirs);
        } finally {
            chmod($this->tmpDir . '/locked', 0755);
        }
    }

    /**
     * once-094 (A-07): the wd bookkeeping DRAINS on the kernel's
     * self-destroy events instead of growing across rm -rf churn.
     * Extension-independent: the drain logic is driven through the
     * reflection seam with a synthetic batch (the plan's "assert count").
     */
    public function testWdMapDrainsOnSelfDestroyEvents(): void
    {
        $ref = new \ReflectionClass(FileWatcher::class);
        $deleteSelf = $ref->getReflectionConstant('IN_DELETE_SELF')->getValue();
        $ignored = $ref->getReflectionConstant('IN_IGNORED')->getValue();
        $modify = $ref->getReflectionConstant('IN_MODIFY')->getValue();

        $watcher = new FileWatcher(new NullLogger(), $this->tmpDir, 1.0, new WatchTestLoop(), FileWatcher::BACKEND_POLL);
        $map = $ref->getProperty('wdToPath');
        $map->setValue($watcher, [5 => $this->tmpDir, 7 => $this->tmpDir . '/gone', 9 => $this->tmpDir . '/src']);

        $process = new \ReflectionMethod(FileWatcher::class, 'processInotifyEvents');
        $process->invoke($watcher, [
            ['wd' => 5, 'mask' => $modify, 'name' => 'keep.php'],   // ordinary wake: untouched
            ['wd' => 7, 'mask' => $deleteSelf, 'name' => ''],        // rm -rf: dir self-destroyed
            ['wd' => 7, 'mask' => $ignored, 'name' => ''],           // kernel goodbye for that wd
        ]);

        $this->assertSame(
            [5 => $this->tmpDir, 9 => $this->tmpDir . '/src'],
            $map->getValue($watcher),
            'IN_DELETE_SELF/IN_IGNORED must retire the map entry the kernel retired',
        );

        // A repeated (already-drained) ignored event is clean, not fatal.
        $process->invoke($watcher, [['wd' => 7, 'mask' => $ignored, 'name' => '']]);
        $this->assertCount(2, $map->getValue($watcher));
    }

    /**
     * once-086: the new-directory watch decision honors BOTH the exclude
     * view and the no-symlink posture (the IN_CREATE arm of
     * processInotifyEvents funnels through this predicate).
     */
    public function testNewDirectoryWatchHonorsExcludesAndLinks(): void
    {
        mkdir($this->tmpDir . '/newok');
        mkdir($this->tmpDir . '/vendorX');
        $outside = $this->tmpDir . '_ext';
        mkdir($outside, 0777, true);
        $this->extraDirs[] = $outside;
        symlink($outside, $this->tmpDir . '/linked');

        $watcher = new FileWatcher(
            new NullLogger(), $this->tmpDir, 1.0, null,
            FileWatcher::BACKEND_POLL, FileWatcher::DEBOUNCE_SECONDS, ['**/vendor*/**'],
        );
        $child = new \ReflectionMethod(FileWatcher::class, 'inotifyWatchableChild');

        $this->assertTrue((bool)$child->invoke($watcher, $this->tmpDir, 'newok'));
        $this->assertFalse((bool)$child->invoke($watcher, $this->tmpDir, 'vendorX'),
            'a new dir under the exclude view must never earn a watch');
        $this->assertFalse((bool)$child->invoke($watcher, $this->tmpDir, 'linked'),
            'never watch through a symlink (shell-006 posture)');
        $this->assertFalse((bool)$child->invoke($watcher, $this->tmpDir, 'nosuch'));
        $this->assertFalse((bool)$child->invoke($watcher, $this->tmpDir, ''));
    }

    /**
     * Shared-resource fence from the plan ("fd-leak fence in test
     * teardown", once-086 A-05): twenty real inotify start/stop cycles
     * must leave the descriptor table exactly as they found it. Measured
     * only where the extension exists (the drain logic itself is pinned
     * extension-independently above).
     */
    public function testInotifyStartStopLeavesNoFileDescriptorBehind(): void
    {
        if (!function_exists('inotify_init')) {
            $this->markTestSkipped('ext-inotify unavailable in this build');
        }
        mkdir($this->tmpDir . '/sub', 0777, true);
        $this->createFile('sub/a.php');

        $before = $this->countOpenFds();
        for ($i = 0; $i < 20; $i++) {
            $watcher = new FileWatcher(new NullLogger(), $this->tmpDir, 1.0, new WatchTestLoop(), FileWatcher::BACKEND_INOTIFY);
            $watcher->watch(static function (): void {});
            $watcher->stop();
        }

        $this->assertSame($before, $this->countOpenFds(),
            'every fd acquired by the inotify start path must be released by stop()');
    }

    private function countOpenFds(): int
    {
        $fds = glob('/proc/self/fd/*') ?: [];
        clearstatcache();
        return count($fds);
    }
}

/**
 * Deterministic LoopInterface double: production code only schedules
 * one-shot timers, periodic timers and stream reads here, and the test
 * fires them by hand — watch mode stays CI-safe with zero wall-clock
 * dependence (plan: "CI-safe tests only via injected clocks").
 */
final class WatchTestTimer implements TimerInterface
{
    public bool $cancelled = false;

    /** @param callable $callback */
    public function __construct(
        private readonly float $interval,
        private $callback,
        private readonly bool $periodic,
    ) {}

    public function getInterval(): float
    {
        return $this->interval;
    }

    /** @return callable */
    public function getCallback()
    {
        return $this->callback;
    }

    public function isPeriodic(): bool
    {
        return $this->periodic;
    }
}

final class WatchTestLoop implements LoopInterface
{
    /** @var list<WatchTestTimer> */
    private array $oneShot = [];

    /** @var list<WatchTestTimer> */
    private array $periodic = [];

    public function firePeriodic(): void
    {
        foreach ($this->periodic as $timer) {
            if (!$timer->cancelled) {
                ($timer->getCallback())();
            }
        }
    }

    /** Fire all armed one-shot timers once (then forget them, like a real loop). */
    public function fireTimers(): void
    {
        $due = $this->oneShot;
        $this->oneShot = [];
        foreach ($due as $timer) {
            if (!$timer->cancelled) {
                ($timer->getCallback())();
            }
        }
    }

    /** One-shot timers currently armed (not yet fired, not cancelled). */
    public function countPendingOneShots(): int
    {
        return count($this->oneShot);
    }

    public function addTimer($interval, $callback): TimerInterface
    {
        $timer = new WatchTestTimer((float)$interval, $callback, false);
        $this->oneShot[] = $timer;
        return $timer;
    }

    public function addPeriodicTimer($interval, $callback): TimerInterface
    {
        $timer = new WatchTestTimer((float)$interval, $callback, true);
        $this->periodic[] = $timer;
        return $timer;
    }

    public function cancelTimer(TimerInterface $timer): void
    {
        if ($timer instanceof WatchTestTimer) {
            $timer->cancelled = true;
        }
        $this->oneShot = array_values(array_filter(
            $this->oneShot,
            static fn(WatchTestTimer $t): bool => $t !== $timer,
        ));
        $this->periodic = array_values(array_filter(
            $this->periodic,
            static fn(WatchTestTimer $t): bool => $t !== $timer,
        ));
    }

    public function addReadStream($stream, $listener): void {}
    public function addWriteStream($stream, $listener): void {}
    public function removeReadStream($stream): void {}
    public function removeWriteStream($stream): void {}
    public function futureTick($listener): void {}
    public function addSignal($signal, $listener): void {}
    public function removeSignal($signal, $listener): void {}
    public function run(): void {}
    public function stop(): void {}
}
