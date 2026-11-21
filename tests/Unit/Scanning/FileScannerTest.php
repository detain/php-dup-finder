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

    // ── shell-030: coalesce adjacent globstar-slash groups (ReDoS) ─────────

    /**
     * Equivalence table for the shell-030 coalescing: every globstar-slash
     * token once compiled a nested-quantifier group, so adjacent runs blew
     * up combinatorially. The coalesced compiler must emit ONE group per
     * maximal run and accept EXACTLY the language the legacy form accepted
     * (checked path-by-path against {@see globstarCorpus()}), while the
     * negative-control rows pin that nothing else changed either: a run
     * separated by a literal slash, or a trailing double-star, keeps its
     * legacy bytes untouched.
     *
     * @return array<string, array{string, string, string, list<string>, list<string>}>
     */
    public static function globstarEquivalenceProvider(): array
    {
        return [
            'single run (identity)' => [
                '**/x', '#^(?:[^/]*/)*x$#', '#^(?:[^/]*/)*x$#',
                ['x', 'a/x', '//x', str_repeat('level/', 8) . 'x'], ['ax', 'a/b'],
            ],
            'double run coalesces' => [
                '**/**/x', '#^(?:[^/]*/)*(?:[^/]*/)*x$#', '#^(?:[^/]*/)*x$#',
                ['x', 'a/x', 'a/b/x', '//x', 'a//x', str_repeat('level/', 8) . 'x'], ['ax', 'a/b'],
            ],
            'quad run collapses to one group' => [
                '**/**/**/**/x',
                '#^(?:[^/]*/)*(?:[^/]*/)*(?:[^/]*/)*(?:[^/]*/)*x$#', '#^(?:[^/]*/)*x$#',
                ['x', 'a/x', 'a/b/c/d/e/f/g/h/x', '//x'], ['ax'],
            ],
            'mid-pattern pair' => [
                'a/**/**/b', '#^a/(?:[^/]*/)*(?:[^/]*/)*b$#', '#^a/(?:[^/]*/)*b$#',
                ['a/b', 'a/c/b', 'a/c/d/b', 'a//b'], ['a/b/c', 'x/a/b'],
            ],
            'deep vendor chain' => [
                '**/**/vendor/**', '#^(?:[^/]*/)*(?:[^/]*/)*vendor/.*$#', '#^(?:[^/]*/)*vendor/.*$#',
                ['vendor/x.php', 'a/b/vendor/c/x.php', 'vendor/'],
                ['myvendor/x.php', 'vendorx/y.php', 'notvendor/'],
            ],
            'trailing run is not a segment group (identity)' => [
                '**/**', '#^(?:[^/]*/)*.*$#', '#^(?:[^/]*/)*.*$#',
                ['x', 'a/b', '', 'a/'], [],
            ],
            'empty-segment sandwich keeps BOTH groups (identity)' => [
                '**//**/x', '#^(?:[^/]*/)*/(?:[^/]*/)*x$#', '#^(?:[^/]*/)*/(?:[^/]*/)*x$#',
                ['//x', 'a//x', 'a/b//x', '/x'], ['a/x', '//', 'x'],
            ],
            'root-relative (identity)' => [
                '/vendor/**', '#^vendor/.*$#', '#^vendor/.*$#',
                ['vendor/x.php', 'vendor/'], ['a/vendor/x.php'],
            ],
            'bare segment glob (identity)' => [
                '**/', '#^(?:[^/]*/)*$#', '#^(?:[^/]*/)*$#',
                ['', 'a/', 'a/b/'], ['a', 'a/b', 'a/b/c.php'],
            ],
            'metacharacter dot after coalesced run' => [
                '**/**/x.y', '#^(?:[^/]*/)*(?:[^/]*/)*x\.y$#', '#^(?:[^/]*/)*x\.y$#',
                ['x.y', 'a/b/x.y'], ['xzy', 'a/b/xy'],
            ],
            'prefixed triple chain' => [
                'lib/**/**/**/old',
                '#^lib/(?:[^/]*/)*(?:[^/]*/)*(?:[^/]*/)*old$#', '#^lib/(?:[^/]*/)*old$#',
                ['lib/old', 'lib/a/old', 'lib/a/b/c/old'], ['lib/a/new', 'alib/old'],
            ],
            'triple-star run folds with the next' => [
                '***/**/x', '#^(?:[^/]*/)*(?:[^/]*/)*x$#', '#^(?:[^/]*/)*x$#',
                ['x', 'a/b/x'], ['ax'],
            ],
        ];
    }

    /**
     * @param array{string, string, string, list<string>, list<string>} $case
     *        [glob, legacy compiled, coalesced compiled, must-match, must-not-match]
     * @dataProvider globstarEquivalenceProvider
     */
    public function testCoalescedCompileIsLanguageEquivalentToLegacy(
        string $glob,
        string $legacy,
        string $coalesced,
        array $mustMatch,
        array $mustNotMatch
    ): void {
        $compiled = $this->compileGlob($glob);
        $this->assertSame($coalesced, $compiled, "compiled shape for {$glob}");

        foreach (self::globstarCorpus() as $path) {
            $before = preg_match($legacy, $path);
            $after = preg_match($compiled, $path);
            // Both forms must stay analyzable: an int verdict, never a
            // silent false from a backtrack-limit blowout.
            $this->assertIsInt($before, "legacy returned false on {$path} for {$glob}");
            $this->assertIsInt($after, "coalesced returned false on {$path} for {$glob}");
            $this->assertSame(PREG_NO_ERROR, preg_last_error(), "preg error after {$path} for {$glob}");
            $this->assertSame($before, $after, "pre/post compile disagree on {$path} for {$glob}");
        }
        foreach ($mustMatch as $path) {
            $this->assertSame(1, preg_match($compiled, $path), "{$glob} must still match {$path}");
        }
        foreach ($mustNotMatch as $path) {
            $this->assertSame(0, preg_match($compiled, $path), "{$glob} must still reject {$path}");
        }
    }

    /**
     * shell-030 acceptance: a 45-level adversarial path checked through
     * isExcluded() against a 15-deep globstar-slash chain must return a real
     * verdict — int from preg_match, PREG_NO_ERROR behind it — inside a
     * CI-safe time box. Before the coalescing this combination exhausted
     * pcre.backtrack_limit (FUZZ: seconds per file, then silent false =
     * the exclude stops matching entirely). The near-miss arm is the sharp
     * edge: PCRE auto-possessifies adjacent identical groups on plainly
     * foreign tails (those stay linear), but a final segment that shares
     * its leading bytes with the literal tail re-opens the split search
     * across all 15 groups — measured 38.6ms + PREG_BACKTRACK_LIMIT_ERROR
     * on the legacy compile, microseconds on the coalesced one.
     */
    public function testAdversarialGlobstarChainIsBoundedAndNeverSilent(): void
    {
        $deep = str_repeat('level/', 45) . 'file.php';
        $nearMissPath = str_repeat('level/', 45) . 'level-xyz.php';
        $chain = str_repeat('**/', 15);

        $miss = new FileScanner([$chain . 'never-matches'], warnOnSkip: false);
        $near = new FileScanner([$chain . 'level-matchy.php'], warnOnSkip: false);
        $hit = new FileScanner([$chain . 'file.php'], warnOnSkip: false);

        $t0 = hrtime(true);
        $missVerdict = $miss->isExcluded($deep);
        $nearVerdict = $near->isExcluded($nearMissPath);
        $hitVerdict = $hit->isExcluded($deep);
        $elapsedMs = (hrtime(true) - $t0) / 1e6;

        $this->assertFalse($missVerdict, 'chain + foreign tail must not match');
        $this->assertFalse($nearVerdict, 'chain + near-miss tail must not match');
        $this->assertTrue($hitVerdict, 'chain + real tail must exclude (coalescing kept the language)');
        $this->assertSame(
            PREG_NO_ERROR,
            preg_last_error(),
            'no backtrack-limit blowout behind the verdicts (legacy compile returned silent false here)'
        );
        $this->assertLessThan(10.0, $elapsedMs, "three 45-level checks against a 15-group chain took {$elapsedMs}ms (budget: 10ms)");
    }

    /**
     * Paths used for the pre/post equivalence sweep. Segment counts stay ≤ 8
     * so even the legacy combinatorial form walks the table in microseconds;
     * the adversarial depth is pinned separately, on the coalesced form only.
     *
     * @return list<string>
     */
    private static function globstarCorpus(): array
    {
        return [
            '', 'x', 'ax', 'a/x', 'a/b/x', 'a/b/c/d/e/f/g/h/x',
            '//x', 'a//x', 'a/b//c/x', '/x', 'a/', 'a/b/',
            'vendor/', 'vendor/x.php', 'a/b/vendor/c/x.php', 'myvendor/x.php', 'vendorx/y.php',
            'x.y', 'a/b/x.y', 'xzy', 'lib/old', 'lib/a/old', 'lib/a/b/c/old', 'alib/old',
            'mod/a/b/legacy', 'mod/a/b/legacy.php',
            str_repeat('level/', 8) . 'x', str_repeat('level/', 8) . 'old',
        ];
    }

    /**
     * Reach the private compiler the same way the shell-S20 yard-stick pin
     * reaches escapeReason(): reflection on the gate, no production seam.
     */
    private function compileGlob(string $glob): string
    {
        $scanner = new FileScanner([], warnOnSkip: false);
        $compile = new \ReflectionMethod(FileScanner::class, 'globToRegex');
        return $compile->invoke($scanner, $glob);
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
     * LOW shell-S20-r1 #1: when the real scan root is the filesystem root,
     * realpath('/') returns "/" itself, so a raw `$rootReal . '/'` yard-stick
     * became "//" and falsely refused EVERY in-root symlink as escaping.
     * Scanning a real "/" root in CI is impractical (it walks the whole
     * filesystem), so this pins the rtrim normalization directly on the
     * private gate: with $rootReal === '/', any resolving link is by
     * definition in-root and must pass, while the self-loop equality (a
     * link whose realpath IS '/') still fires — the equality arm is checked
     * before the prefix arm, and a "/" prefix would otherwise swallow it.
     */
    public function testFileSystemRootYardStickDoesNotFalselyRefuseInRootLinks(): void
    {
        $t = $this->symlinkTree();
        $gate = new \ReflectionMethod(FileScanner::class, 'escapeReason');
        $scanner = new FileScanner([], warnOnSkip: false);

        // An ordinary in-root file link under a "/"-style real root: passes.
        $this->assertNull(
            $gate->invoke($scanner, new \SplFileInfo($t['root'] . '/alias.php'), '/'),
            'under a "/" real root no resolving link may be refused as escaping'
        );

        // A link that re-enters the "/" root (its realpath is exactly "/"):
        // still pruned as a self-loop, not accepted by the now-normalized
        // "/" prefix check.
        $rootLoop = $t['root'] . '/root-loop';
        if (!is_link($rootLoop)) {
            symlink('/', $rootLoop);
        }
        clearstatcache();
        $this->assertSame(
            'target re-enters the scan root',
            $gate->invoke($scanner, new \SplFileInfo($rootLoop), '/')
        );
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
