<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Scanning;

use PHPUnit\Framework\TestCase;
use Phpdup\Scanning\FileScanner;

final class FileScannerTest extends TestCase
{
    public function testScansFixtureDirectory(): void
    {
        $scanner = new FileScanner([]);
        $files = iterator_to_array($scanner->scan(__DIR__ . '/../../Fixtures'), false);
        $this->assertNotEmpty($files);
        foreach ($files as $f) {
            $this->assertStringEndsWith('.php', $f);
        }
    }

    public function testExcludesGlobs(): void
    {
        $scanner = new FileScanner(['**/exact/**']);
        $files = iterator_to_array($scanner->scan(__DIR__ . '/../../Fixtures'), false);
        foreach ($files as $f) {
            $this->assertStringNotContainsString('/exact/', $f);
        }
    }

    public function testIsExcludedMatchesDoubleStar(): void
    {
        $scanner = new FileScanner(['vendor/**', '**/*.tpl.php']);
        $this->assertTrue($scanner->isExcluded('vendor/foo/bar.php'));
        $this->assertTrue($scanner->isExcluded('src/views/header.tpl.php'));
        $this->assertFalse($scanner->isExcluded('src/views/header.php'));
    }

    // ── Item 5.2: segment-safe `**` dialect ────────────────────────────────

    public function testDoubleStarSlashIsSegmentAnchored(): void
    {
        $scanner = new FileScanner(['**/vendor/**']);
        // deep AND root-level vendor trees are excluded…
        $this->assertTrue($scanner->isExcluded('vendor/x.php'));
        $this->assertTrue($scanner->isExcluded('a/vendor/b.php'));
        $this->assertTrue($scanner->isExcluded('a/b/vendor/c/d.php'));
        // …directory-prune form (trailing slash) included…
        $this->assertTrue($scanner->isExcluded('vendor/'));
        $this->assertTrue($scanner->isExcluded('a/vendor/'));
        // …but the old `.*` over-match is gone: no substring hits.
        $this->assertFalse($scanner->isExcluded('myvendor/x.php'));
        $this->assertFalse($scanner->isExcluded('notvendor/x.php'));
        $this->assertFalse($scanner->isExcluded('src/vendorlib/y.php'));
        $this->assertFalse($scanner->isExcluded('myvendor/'));
    }

    public function testSingleStarStillNeverCrossesSlash(): void
    {
        $scanner = new FileScanner(['src/*.php', '*.php', 'a**b.php']);
        $this->assertTrue($scanner->isExcluded('src/a.php'));
        $this->assertFalse($scanner->isExcluded('src/a/b.php'));   // regression pin: pre-existing behavior
        $this->assertTrue($scanner->isExcluded('main.php'));
        $this->assertFalse($scanner->isExcluded('deep/main.php'));  // plain *.php: root-level only (unchanged)
        // `**` mid-segment degenerates to `*` (globstar convention): it must
        // not cross "/" the way the old compiler did.
        $this->assertTrue($scanner->isExcluded('axb.php'));
        $this->assertFalse($scanner->isExcluded('a/x/b.php'));
    }

    public function testDeepDefaultsMatchWholeSegmentsOnly(): void
    {
        $scanner = new FileScanner(['**/cache/**', '**/.phpdup-cache/**']);
        $this->assertTrue($scanner->isExcluded('cache/Widget.php'));
        $this->assertTrue($scanner->isExcluded('app/cache/Widget.php'));
        // segment-precise: `.phpdup-cache` is its OWN segment, hit by its own
        // pattern — and NOT swallowed by the generic `**/cache/**` the way the
        // old `.*cache/.*` regex did.
        $this->assertTrue($scanner->isExcluded('.phpdup-cache/blob.php'));
        $this->assertFalse($scanner->isExcluded('mycache/Widget.php'));
    }

    // ── Item 5.2 / NN2: unreadable trees skip loudly instead of hard-dying ──

    public function testUnreadableScanRootIsSkippedNotFatal(): void
    {
        $dir = $this->lockedTree();
        $locked = $dir . '/locked';

        $scanner = new FileScanner([], warnOnSkip: false);
        $files = iterator_to_array($scanner->scan($locked), false);

        $this->assertSame([], $files, 'an unreadable root yields nothing…');
        $notes = $scanner->skipNotes();
        $this->assertCount(1, $notes, '…and exactly one honest skip note');
        $this->assertStringContainsString('locked', $notes[0]);
    }

    public function testUnreadableNestedDirIsPrunedAndNoted(): void
    {
        $dir = $this->lockedTree();

        $scanner = new FileScanner([], warnOnSkip: false);
        $files = iterator_to_array($scanner->scan($dir), false);

        $basenames = array_map('basename', $files);
        $this->assertContains('readable.php', $basenames, 'the scan continues past the unreadable branch');
        $this->assertNotContains('hidden.php', $basenames);

        $notes = $scanner->skipNotes();
        $this->assertCount(1, $notes, 'one note for the one skipped directory');
        $this->assertStringContainsString('locked', $notes[0]);
    }

    public function testCleanScanRecordsNoSkipNotes(): void
    {
        $dir = $this->lockedTree();
        chmod($dir . '/locked', 0755); // release the lock: full tree readable now

        $scanner = new FileScanner([], warnOnSkip: false);
        $files = iterator_to_array($scanner->scan($dir), false);

        $this->assertEmpty($scanner->skipNotes());
        $this->assertCount(2, $files);
    }

    /**
     * Scratch tree root/{readable.php, locked/hidden.php} with `locked`
     * chmod-000. Skips when the effective user still reads it (root).
     */
    private function lockedTree(): string
    {
        $dir = sys_get_temp_dir() . '/phpdup-unreadable-' . getmypid() . '-' . spl_object_id($this);
        if (!is_dir($dir)) {
            mkdir($dir . '/locked', 0777, true);
        }
        $php = "<?php\nfunction probe() { return 1; }\n";
        file_put_contents($dir . '/readable.php', $php);
        file_put_contents($dir . '/locked/hidden.php', $php);
        chmod($dir . '/locked', 0000);
        clearstatcache(true, $dir . '/locked');
        if (is_readable($dir . '/locked')) {
            chmod($dir . '/locked', 0755);
            $this->markTestSkipped('cannot produce a real permission denial for this user (running as root?)');
        }
        return $dir;
    }

    protected function tearDown(): void
    {
        foreach (glob(sys_get_temp_dir() . '/phpdup-unreadable-' . getmypid() . '-*/locked') ?: [] as $locked) {
            chmod($locked, 0755);
        }
        foreach (glob(sys_get_temp_dir() . '/phpdup-unreadable-' . getmypid() . '-*') ?: [] as $dir) {
            if (is_dir($dir)) {
                foreach ([$dir . '/locked/hidden.php', $dir . '/locked', $dir . '/readable.php'] as $f) {
                    if (is_file($f)) {
                        unlink($f);
                    }
                }
                @rmdir($dir . '/locked');
                @rmdir($dir);
            }
        }
    }
}
