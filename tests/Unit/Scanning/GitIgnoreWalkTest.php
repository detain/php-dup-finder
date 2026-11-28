<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Scanning;

use PHPUnit\Framework\TestCase;
use Phpdup\Scanning\FileScanner;

/**
 * Item 5.2's .gitignore leg (W2a) — walk-level pins against the committed
 * fixture family tests/Fixtures/gitignore/walk. The family is the nested
 * precedence TRUTH TABLE in file form: dir-only pruning, anchored leading
 * and interior slashes, basename-any-depth, in-file negation (last line
 * wins), deeper-file-wins, DEAD negations (inside a pruned directory and
 * under a path-excluded subtree), escaped metabytes, trailing-space trim
 * and its escaped exception, CRLF bodies, bracket classes, empty file.
 *
 * The OFF posture is pinned too: the historical walk yields the complete
 * php-extension set with zero gitignore notes — the flag must be inert
 * until asked (goldens depend on it).
 */
final class GitIgnoreWalkTest extends TestCase
{
    /** @var list<string> */
    private array $tmpDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpDirs as $dir) {
            foreach (new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_PATHNAME),
                \RecursiveIteratorIterator::CHILD_FIRST,
            ) as $entry) {
                // Links first (rmdir(2) refuses a symlink-to-dir), then
                // dirs, then plain files. All @-quiet: best-effort cleanup.
                if (is_link($entry) || is_file($entry)) {
                    @unlink($entry);
                } elseif (is_dir($entry)) {
                    @rmdir($entry);
                }
            }
            @rmdir($dir);
        }
    }

    private static function fixture(): string
    {
        return __DIR__ . '/../../Fixtures/gitignore/walk';
    }

    /** @return list<string> scan yielded paths, root-relative, sorted */
    private function walk(bool $honor, ?FileScanner &$scanner = null): array
    {
        $scanner = new FileScanner([], false, [], $honor);
        $root = self::fixture();
        $found = [];
        foreach ($scanner->scan($root) as $path) {
            $found[] = str_replace($root . '/', '', $path);
        }
        sort($found);
        return $found;
    }

    /**
     * The default-OFF posture: every php-extension file in the family, no
     * gitignore channel activity whatsoever (zero bytes for goldens).
     */
    public function testFlagOffWalkIsByteIdenticalToHistory(): void
    {
        $scanner = null;
        $found = $this->walk(false, $scanner);
        $this->assertContains('build/app.php', $found, 'OFF must ignore nothing');
        $this->assertContains('sub/deep/keep.txt.php', $found);
        $this->assertContains('src/vendor/v.php', $found);
        $this->assertCount(23, $found);
        $this->assertSame(0, $scanner->gitignoreSkippedCount());
        foreach ($scanner->skipNotes() as $note) {
            $this->assertStringNotContainsString('gitignore', $note, 'OFF walk stays silent on the channel');
        }
    }

    /** The full ON truth table for the fixture family. */
    public function testFlagHonorsEveryDialectLeg(): void
    {
        $scanner = null;
        $found = $this->walk(true, $scanner);

        // Kept: negations that are ALIVE, anchored non-matches, deep-file
        // revivals, class non-members, escape discriminators, trimmed-rule
        // survivors, empty files.
        $expected = [
            'blocked.php',
            'crlf/here.php',
            'deep.php',
            'empty/keep.php',
            'esc.php',
            'keepsp.php',
            'mid/other.php',
            'other/rootonly.php',
            'revdir/r.php',
            'secretX.php',
            'src/build/in.php',
            'sub/surface.php',
            'vendor/top.php',
        ];
        sort($expected);
        $this->assertSame($expected, $found);

        // Honest accounting: 4 pruned dirs (build/, excluded/, sub/deep/,
        // src/vendor/) + 8 excluded files (a.log, keep? no—keep.log is
        // negated: excluded are rootonly.php, mid/deep.php, secret1.php,
        // .esc.php, x.tmp, trail.php, crlf/gone.php, a.log) — the pruned
        // dirs' contents are never visited and never counted.
        $this->assertSame(12, $scanner->gitignoreSkippedCount());
        $notes = $scanner->skipNotes();
        $joined = implode("\n", $notes);
        $this->assertStringContainsString('gitignore: 12 paths excluded by .gitignore rules under scan root', $joined);
    }

    public function testPrunedDirectoryNeverSpeaksThroughItsOwnIgnoreFile(): void
    {
        // walk/excluded/.gitignore contains '!**' — if the walk ever read a
        // file inside a pruned tree, excluded/hide.php would come back.
        $scanner = null;
        $found = $this->walk(true, $scanner);
        $this->assertNotContains('excluded/hide.php', $found);
        $this->assertNotContains('build/app.php', $found, 'dir-only prune hides the whole subtree');
    }

    public function testDeeperFileWinsAndRootRulesStayScoped(): void
    {
        $scanner = null;
        $found = $this->walk(true, $scanner);
        // src/.gitignore '!build/' revives build dirs UNDER src only…
        $this->assertContains('src/build/in.php', $found);
        // …and its 'vendor/' rule never reaches the root vendor/ directory.
        $this->assertContains('vendor/top.php', $found);
        $this->assertNotContains('src/vendor/v.php', $found);
    }

    public function testSymlinkDirectoryIsGatedByNameNotTarget(): void
    {
        $dir = sys_get_temp_dir() . '/phpdup-gisym-' . bin2hex(random_bytes(5));
        $this->tmpDirs[] = $dir;
        mkdir("$dir/shadow", 0o777, true);
        file_put_contents("$dir/shadow/in.php", "<?php function gisym_target() { return 1; }\n");
        if (!@symlink("$dir/shadow", "$dir/build")) {
            $this->markTestSkipped('symlink() unavailable on this platform');
        }
        file_put_contents("$dir/.gitignore", "build/\n");

        $scanner = new FileScanner([], false, [], true);
        $found = [];
        foreach ($scanner->scan($dir) as $path) {
            $found[] = str_replace($dir . '/', '', $path);
        }
        // 'build' is excluded BY NAME (its target 'shadow' keeps its own
        // contents; git ignores the LINK, never the directory it points at
        // reached by its real name).
        $this->assertContains('shadow/in.php', $found);
        foreach ($found as $f) {
            $this->assertStringStartsNotWith('build/', $f);
        }
        $this->assertSame(1, $scanner->gitignoreSkippedCount(), 'only the link entry itself');
    }

    public function testOverLineBudgetFailsClosedVisibly(): void
    {
        $dir = sys_get_temp_dir() . '/phpdup-gicap-' . bin2hex(random_bytes(5));
        $this->tmpDirs[] = $dir;
        mkdir($dir, 0o777, true);
        $lines = str_repeat("#pad\n", FileScanner::GITIGNORE_LINES_CAP + 1) . "victim.php\n";
        file_put_contents("$dir/.gitignore", $lines);
        file_put_contents("$dir/victim.php", "<?php function gicap_victim() { return 1; }\n");
        file_put_contents("$dir/other.php", "<?php function gicap_other() { return 1; }\n");

        $scanner = new FileScanner([], false, [], true);
        $found = [];
        foreach ($scanner->scan($dir) as $path) {
            $found[] = str_replace($dir . '/', '', $path);
        }
        // FAIL CLOSED: the unreadable-in-budget file excludes EVERYTHING
        // below it — not just the line the budget cut away.
        $this->assertSame([], $found);
        $joined = implode("\n", $scanner->skipNotes());
        $this->assertStringContainsString('-line budget', $joined);
        $this->assertStringContainsString('treated as excluded', $joined);
        $this->assertGreaterThan(0, $scanner->gitignoreSkippedCount());
    }

    public function testOverBytesBudgetSkipsTheReadAndFailsClosed(): void
    {
        $dir = sys_get_temp_dir() . '/phpdup-gibyte-' . bin2hex(random_bytes(5));
        $this->tmpDirs[] = $dir;
        mkdir($dir, 0o777, true);
        $big = str_repeat("#", FileScanner::GITIGNORE_BYTES_CAP + 8);
        file_put_contents("$dir/.gitignore", $big . "\nvictim.php\n");
        file_put_contents("$dir/victim.php", "<?php function gibyte_victim() { return 1; }\n");

        $scanner = new FileScanner([], false, [], true);
        $found = iterator_to_array($scanner->scan($dir), false);
        $this->assertSame([], $found, 'oversize ignore file excludes its subtree without being read');
        $joined = implode("\n", $scanner->skipNotes());
        $this->assertStringContainsString('-byte budget', $joined);
    }

    public function testUnreadableIgnoreFileIsNotedAndTreatedAbsent(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('chmod-based unreadability is meaningless as root');
        }
        $dir = sys_get_temp_dir() . '/phpdup-giperm-' . bin2hex(random_bytes(5));
        $this->tmpDirs[] = $dir;
        mkdir($dir, 0o777, true);
        file_put_contents("$dir/.gitignore", "hidden.php\n");
        chmod("$dir/.gitignore", 0o000);
        file_put_contents("$dir/hidden.php", "<?php function giperm_hidden() { return 1; }\n");

        $scanner = new FileScanner([], false, [], true);
        $found = [];
        foreach ($scanner->scan($dir) as $path) {
            $found[] = str_replace($dir . '/', '', $path);
        }
        // Environmental failure (not hostile input): the file is treated as
        // ABSENT and the honest-scan channel carries a per-path note.
        $this->assertContains('hidden.php', $found);
        $joined = implode("\n", $scanner->skipNotes());
        $this->assertStringContainsString('skipped unreadable path', $joined);
        $this->assertStringContainsString('ignore rules could not be read', $joined);
        $this->assertSame(0, $scanner->gitignoreSkippedCount());
    }
}
