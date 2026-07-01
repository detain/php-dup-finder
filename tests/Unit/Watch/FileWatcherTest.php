<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Watch;

use PHPUnit\Framework\TestCase;
use Phpdup\Watch\FileChangeType;
use Phpdup\Watch\FileWatcher;
use Psr\Log\NullLogger;
use SplFileInfo;
use Symfony\Component\Console\Output\NullOutput;

/**
 * @covers \Phpdup\Watch\FileWatcher
 */
final class FileWatcherTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/phpdup_watch_test_' . uniqid('', true);
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    private function removeDir(string $dir): void
    {
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iter as $path) {
            if ($path->isDir()) {
                rmdir((string) $path);
            } else {
                unlink((string) $path);
            }
        }
        rmdir($dir);
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
}
