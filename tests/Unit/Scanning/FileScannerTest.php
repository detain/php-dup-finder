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

    // ── shell-006: symlink containment (canonicalize-compare posture) ──────

    public function testSymlinkedDirPointingOutsideRootIsNotWalked(): void
    {
        $t = $this->symlinkTree();
        $scanner = new FileScanner([], warnOnSkip: false);
        $files = iterator_to_array($scanner->scan($t['root']), false);

        foreach ($files as $f) {
            $this->assertStringNotContainsString('/outside/', $f, 'out-of-root subtree must never be entered');
        }
        $note = $this->noteFor($scanner->skipNotes(), '/escape-dir');
        $this->assertNotNull($note, 'the pruned directory link is noted');
        $this->assertStringContainsString('escapes the scan root', $note);
    }

    public function testSymlinkedFilePointingOutsideRootIsNotYielded(): void
    {
        $t = $this->symlinkTree();
        $scanner = new FileScanner([], warnOnSkip: false);
        $files = iterator_to_array($scanner->scan($t['root']), false);

        $this->assertNotContains($t['root'] . '/escape.php', $files);
        $note = $this->noteFor($scanner->skipNotes(), '/escape.php');
        $this->assertNotNull($note, 'the refused file link is noted');
        $this->assertStringContainsString('escapes the scan root', $note);
    }

    public function testSymlinkToInsideTargetStillYields(): void
    {
        $t = $this->symlinkTree();
        $scanner = new FileScanner([], warnOnSkip: false);
        $files = iterator_to_array($scanner->scan($t['root']), false);

        // File link to an in-root file, and files under a dir link whose
        // realpath stays in-root, are scanned (at their literal paths).
        $rel = $this->relpaths($files, $t['root']);
        $this->assertContains('alias.php', $rel);
        $this->assertContains('inner-alias/inside.php', $rel);
    }

    public function testSelfLoopSymlinkIsPrunedAndNoted(): void
    {
        $t = $this->symlinkTree();
        $scanner = new FileScanner([], warnOnSkip: false);
        // Without pruning, descending `loop -> root` would recurse forever;
        // reaching the assertions at all proves termination.
        $files = iterator_to_array($scanner->scan($t['root']), false);

        foreach ($files as $f) {
            $this->assertStringNotContainsString('/loop/', $f, 'the walker must not descend into the re-entering link');
        }
        $note = $this->noteFor($scanner->skipNotes(), '/loop');
        $this->assertNotNull($note);
        $this->assertStringContainsString('re-enters the scan root', $note);
    }

    public function testDanglingSymlinksAreNotedAndNotYielded(): void
    {
        $t = $this->symlinkTree();
        $scanner = new FileScanner([], warnOnSkip: false);
        $files = iterator_to_array($scanner->scan($t['root']), false);
        $rel = $this->relpaths($files, $t['root']);

        $this->assertNotContains('broken.php', $rel);
        $this->assertNotContains('broken-dir', $rel);
        foreach (['/broken.php', '/broken-dir'] as $needle) {
            $note = $this->noteFor($scanner->skipNotes(), $needle);
            $this->assertNotNull($note, "{$needle} is noted");
            $this->assertStringContainsString('target does not resolve', $note);
        }
    }

    public function testContainmentYieldSetAndNoteSetAreExact(): void
    {
        $t = $this->symlinkTree();
        $scanner = new FileScanner([], warnOnSkip: false);
        $files = iterator_to_array($scanner->scan($t['root']), false);

        $this->assertSame(
            ['alias.php', 'inner-alias/inside.php', 'inner/inside.php', 'keep.php'],
            $this->relpaths($files, $t['root']),
            'exactly the in-root files (literal link paths), nothing outside'
        );
        $notes = $scanner->skipNotes();
        $this->assertCount(5, $notes, 'two escapes, one re-entry, two dangling');
        foreach ($notes as $note) {
            $this->assertStringStartsWith('skipped symlinked path ', $note);
        }
    }

    public function testScanRootThatIsASymlinkStaysContained(): void
    {
        $t = $this->symlinkTree();
        $linkRoot = $t['base'] . '/rootlink';
        if (!is_link($linkRoot)) {
            symlink($t['root'], $linkRoot);
        }

        $scanner = new FileScanner([], warnOnSkip: false);
        $files = iterator_to_array($scanner->scan($linkRoot), false);

        // Canonicalize-compare: the yard-stick is realpath(root), so a root
        // reached through a symlink still yields all its in-tree files…
        $this->assertSame(
            ['alias.php', 'inner-alias/inside.php', 'inner/inside.php', 'keep.php'],
            $this->relpaths($files, $linkRoot)
        );
        foreach ($files as $f) {
            $this->assertStringStartsWith($linkRoot . '/', $f, 'paths stay under the root as given');
        }
        // …and out-of-root links are still refused against the REAL root.
        $this->assertCount(5, $scanner->skipNotes());
    }

    /**
     * Remove every scratch tree this test class built — the unreadable-tree
     * fixture and the shell-006 symlink fixture (see {@see symlinkTree()}) —
     * purging links without ever following them.
     */
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
        foreach (glob(sys_get_temp_dir() . '/phpdup-symlink-' . getmypid() . '-*') ?: [] as $dir) {
            self::purgeTree($dir);
        }
    }

    /** @return array{base: string, root: string} */
    private function symlinkTree(): array
    {
        $base = sys_get_temp_dir() . '/phpdup-symlink-' . getmypid() . '-' . spl_object_id($this);
        $root = $base . '/root';
        if (!is_dir($root)) {
            mkdir($root . '/inner', 0777, true);
            mkdir($base . '/outside', 0777, true);
        }
        $php = "<?php\nfunction probe() { return 1; }\n";
        file_put_contents($root . '/keep.php', $php);
        file_put_contents($root . '/inner/inside.php', $php);
        file_put_contents($base . '/outside/secret.php', "<?php\nfunction exfil() { return 2; }\n");
        file_put_contents($base . '/outside/buried.php', "<?php\nfunction exfil2() { return 3; }\n");
        $links = [
            // escaping (dir + file): targets live outside the scan root
            'escape-dir' => $base . '/outside',
            'escape.php' => $base . '/outside/secret.php',
            // in-root aliases: must keep working (canonicalize-compare)
            'inner-alias' => $root . '/inner',
            'alias.php' => $root . '/keep.php',
            // re-entry loop: must be pruned before descent
            'loop' => $root,
            // dangling links: must be noted, never yielded
            'broken.php' => $base . '/no-such.php',
            'broken-dir' => $base . '/no-such-dir',
        ];
        foreach ($links as $name => $target) {
            $path = $root . '/' . $name;
            if (!is_link($path)) {
                symlink($target, $path);
            }
        }
        clearstatcache();
        return ['base' => $base, 'root' => $root];
    }

    /** @param list<string> $files @return list<string> */
    private function relpaths(array $files, string $root): array
    {
        $rel = array_map(static fn(string $f): string => substr($f, strlen($root) + 1), $files);
        sort($rel);
        return $rel;
    }

    /** @param list<string> $notes */
    private function noteFor(array $notes, string $needle): ?string
    {
        foreach ($notes as $note) {
            if (str_contains($note, $needle)) {
                return $note;
            }
        }
        return null;
    }

    private static function purgeTree(string $path): void
    {
        if (is_link($path)) {
            unlink($path);
            return;
        }
        if (is_dir($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::purgeTree($path . '/' . $entry);
                }
            }
            rmdir($path);
            return;
        }
        if (file_exists($path)) {
            unlink($path);
        }
    }
}
