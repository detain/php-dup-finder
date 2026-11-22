<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Scanning;

use PHPUnit\Framework\TestCase;
use Phpdup\Scanning\FileScanner;
use Phpdup\Util\PathCanonical;
use Phpdup\Cli\Command;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

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

    // ── once-095: bounded exclude matching (sugar-ignore UP-01 port) ───────

    /**
     * Parity table for the bounded compiler + automaton port. Every row here
     * compiles to a pattern PCRE may not be trusted with (more than two
     * wildcards, or more than one segment-crossing — {@see testHostileAnd
     * OverlapGlobsAreDecidedByTheAutomaton} pins that routing), so its
     * verdicts must come out of the interned DFA, byte-for-byte the language
     * of the compiled regex. Expectations were taken from the regex-only
     * implementation, exactly as upstream sugar-ignore ports its
     * BacktrackingTest; the two explosive-tail rows carry the subjects that
     * historically returned a silent false (backtrack limit exhausted) from
     * preg_match at default limits — there the hand expectation IS the fix.
     *
     * @return array<string, array{0: string, 1: list<array{string, bool}>}>
     */
    public static function boundedGlobParityProvider(): array
    {
        return [
            'shipped vendor prune (SEG + trailing ANY)' => ['**/vendor/**', [
                ['vendor/x.php', true], ['a/b/vendor/c/d.php', true], ['vendor/', true],
                ['vendor/x.php' . "\n", true], ['vendor/.' . "\n", true],   // PCRE trailing-newline $ quirk
                ['myvendor/x.php', false], ['a/b/vendorlib/y.php', false],
                ['vend', false], ['a/b/vendor', false],
            ]],
            'shaped fixtures chain (three crossings)' => ['**/tests/**/**/fixtures/**', [
                ['tests/fixtures/a.php', true], ['a/tests/b/fixtures/c/d.php', true], ['tests/fixtures/', true],
                ['mytests/fixtures/a.php', false], ['tests/fixturex/a.php', false],
            ]],
            'empty-segment sandwich (the deliberate divergence)' => ['**//**/x', [
                ['//x', true], ['a//x', true], ['a/b//x', true], ['/x', true],
                ['a/x', false], ['x', false], ['a/b', false],
            ]],
            'classic (a+) style chain, explosive tail' => [str_repeat('*a', 14) . '*c', [
                ['x' . str_repeat('a', 14) . 'c', true],
                [str_repeat('a', 20) . 'c', true],
                ['c' . str_repeat('a', 40) . 'c', true],
                ['x' . str_repeat('a', 13) . 'c', false],
                ['c' . str_repeat('a', 40) . '7', false],   // silent false pre-fix
                ['short.txt', false],
                [str_repeat('a', 30) . '/c', false],        // silent false pre-fix
            ]],
            'unbounded globstar chain separated by literals' => [str_repeat('a/**/', 10) . 'ab', [
                [str_repeat('a/', 45) . 'ab', true],        // SEG swallows the surplus levels
                [str_repeat('a/', 10) . 'ab', true],
                [str_repeat('a/', 12) . 'ab', true],
                [str_repeat('a/', 45) . 'abab', false],     // silent false pre-fix
                [str_repeat('a/', 9) . 'ab', false],
                ['ab', false],
                [str_repeat('a/', 45) . 'a', false],
            ]],
            'residual intra-segment single-star chain' => ['src/*a*b*.*x.php', [
                ['src/aabzb.qx.php', true],
                ['src/xx.php', false], ['src/aa/b.php', false], ['src/zz.php', false],
            ]],
        ];
    }

    /**
     * @param list<array{string, bool}> $verdicts
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('boundedGlobParityProvider')]
    public function testHostileAndOverlapGlobsAreDecidedByTheAutomaton(string $glob, array $verdicts): void
    {
        $record = $this->compileRecord($glob);
        $this->assertFalse($record['pcre'], "compiler must route {$glob} away from raw PCRE");

        $scanner = new FileScanner([$glob], warnOnSkip: false);
        foreach ($verdicts as [$path, $expected]) {
            $this->assertSame(
                $expected,
                $scanner->isExcluded($path),
                "verdict for {$glob} vs " . str_replace("\n", '\n', $path)
            );
        }
        $this->assertSame([], $scanner->skipNotes(), 'everything above is decidable inside budget');
    }

    /**
     * The automaton must not merely be fast — it must answer the SAME question
     * as the compiled regex (upstream's "language equality" leg). Subjects on
     * which even PCRE is decisive at default limits must agree; the ones it
     * cannot decide are exactly the rows the fix exists for, pinned by hand
     * above.
     */
    /**
     * @param list<array{string, bool}> $verdicts
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('boundedGlobParityProvider')]
    public function testAutomatonVerdictsAgreeWithTheCompiledRegexLanguage(string $glob, array $verdicts): void
    {
        $regex = $this->compileGlob($glob);
        foreach ($verdicts as [$path, $expected]) {
            $byRegex = preg_match($regex, $path);
            if ($byRegex === 1 || $byRegex === 0) {
                $this->assertSame($expected, $byRegex === 1, "regex-language disagrees with table for {$glob}");
            }
        }
    }

    /**
     * Upstream's starved-pcre twin leg, verbatim in spirit: "which engine
     * answers must never change the answer". With pcre.backtrack_limit at 1
     * EVERY regex match fails (returns false), including the safe PCRE-routed
     * rows — the fall-through in matchesPattern() must keep all verdicts
     * identical and stay silent, no note, no flip.
     */
    public function testVerdictsAreTheSameWhenEveryRegexErrors(): void
    {
        $rows = self::boundedGlobParityProvider();
        // Plus PCRE-routed (backtracks-safely) rows: under starvation these
        // too must fall through to the automaton and keep the same answers.
        $rows['safe: root php glob'] = ['*.php', [['main.php', true], ['deep/main.php', false]]];
        $rows['safe: leading run'] = ['**/x', [['x', true], ['a/x', true], ['ax', false]]];
        $rows['safe: dir prune'] = ['vendor/**', [['vendor/a', true], ['myvendor/a', false]]];

        $previous = ini_set('pcre.backtrack_limit', '1');
        $this->assertNotSame(false, $previous, 'cannot starve PCRE on this build');
        try {
            foreach ($rows as $row) {
                [$glob, $verdicts] = $row;
                $scanner = new FileScanner([$glob], warnOnSkip: false);
                foreach ($verdicts as [$path, $expected]) {
                    $this->assertSame(
                        $expected,
                        $scanner->isExcluded($path),
                        "starved PCRE changed the answer for {$glob} (engine must be verdict-neutral)"
                    );
                }
                $this->assertSame([], $scanner->skipNotes());
            }
        } finally {
            ini_set('pcre.backtrack_limit', $previous);
        }
    }

    /**
     * The acceptance probe, one level up from isExcluded(): 2000 adversarial
     * subjects against the classic chain — every one decisive, the whole
     * table inside two seconds, zero notes. Pre-fix this shape cost
     * milliseconds each and answered a silent false.
     */
    public function testHostileIntraSegmentChainIsDecidedExactlyAndInsideBudget(): void
    {
        $scanner = new FileScanner([str_repeat('*a', 14) . '*c'], warnOnSkip: false);

        $t0 = hrtime(true);
        for ($i = 0; $i < 2000; $i++) {
            $this->assertFalse($scanner->isExcluded('c' . str_repeat('a', 40) . $i), 'non-matching tail stays excluded-free');
        }
        $this->assertTrue($scanner->isExcluded('c' . str_repeat('a', 40) . 'c'));
        $elapsed = (hrtime(true) - $t0) / 1e9;

        $this->assertLessThan(2.0, $elapsed, "2000 hostile checks took {$elapsed}s (budget: 2s)");
        $this->assertSame(PREG_NO_ERROR, preg_last_error());
        $this->assertSame([], $scanner->skipNotes());
    }

    /**
     * The unbounded-`**`-chain half of the acceptance probe: the 45-level
     * near-miss used to detonate the coalesced-but-still-PCRE engine too;
     * through the automaton the whole three-verdict set stays sub-frame and
     * exact (hit true, lookalike false, dangling segment false).
     */
    public function testDeepGlobstarChainSeparatedByLiteralsIsDecided(): void
    {
        $scanner = new FileScanner([str_repeat('a/**/', 10) . 'ab'], warnOnSkip: false);

        $t0 = hrtime(true);
        $this->assertFalse($scanner->isExcluded(str_repeat('a/', 45) . 'abab'), 'one byte off the tail is not a match');
        $elapsedMs = (hrtime(true) - $t0) / 1e6;
        $this->assertTrue($scanner->isExcluded(str_repeat('a/', 45) . 'ab'));
        $this->assertFalse($scanner->isExcluded(str_repeat('a/', 45) . 'a'));

        $this->assertLessThan(10.0, $elapsedMs, "45-level near-miss took {$elapsedMs}ms (budget: 10ms)");
        $this->assertSame([], $scanner->skipNotes());
    }

    /**
     * Over budget means "refuse to answer", never "assume no": the monster
     * pattern plus a 615-byte path exceeds MATCH_BUDGET
     * (n × (tokens+1) > 1_000_000), so the path is treated as EXCLUDED
     * (fail-closed, a hostile pattern can never smuggle files back in), one
     * loud skip note is recorded per pattern (deduped across paths), and
     * short paths beside it are still decided exactly.
     */
    public function testOverBudgetHostilePatternFailsClosedWithOneNote(): void
    {
        $scanner = new FileScanner([str_repeat('*a', 1000) . '*c'], warnOnSkip: false);

        $this->assertTrue($scanner->isExcluded(str_repeat('x', 300) . '/' . str_repeat('y', 300) . '/one.php'));
        $this->assertTrue($scanner->isExcluded(str_repeat('x', 300) . '/' . str_repeat('y', 300) . '/two.php'));
        $this->assertFalse($scanner->isExcluded('short.txt'), 'decidable paths are still decided exactly');

        $notes = $scanner->skipNotes();
        $this->assertCount(1, $notes, 'one note per pattern, deduped across paths');
        $this->assertStringContainsString('too costly to evaluate', $notes[0]);
        $this->assertStringContainsString('treated as excluded', $notes[0]);
        $this->assertStringContainsString('… is too costly', $notes[0], 'note carries the clipped pattern');
    }

    /**
     * End-to-end scanner leg of the acceptance check: a FLAT scratch tree of
     * 150 files whose names are the explosive classic-chain subject ("c" +
     * 40 a's + digits + ".php"), scanned with BOTH hostile chains plus a
     * routine prune in the exclude list. The flat layout is load-bearing:
     * the relPath then IS the explosive shape, so on the pre-fix compiler
     * every file paid a backtrack blowout and a silent false (parent leg:
     * ~65 ms per check — this pin runs red on it). Nesting the files one
     * segment down lets both chains fail early on the slash-bounded
     * leading segment, decides in microseconds, and blinds the timer — the
     * r1 review caught the old sub-dir shape claiming exactly what it
     * could not measure. Now the whole scan finishes well inside the
     * five-second box with nothing excluded and nothing noted.
     */
    public function testScanCompletesUnderBudgetWithHostileExcludes(): void
    {
        $dir = sys_get_temp_dir() . '/phpdup-redos-' . getmypid() . '-' . spl_object_id($this);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        for ($i = 0; $i < 150; $i++) {
            file_put_contents($dir . '/c' . str_repeat('a', 40) . $i . '.php', "<?php\nfunction p{$i}() { return {$i}; }\n");
        }

        $scanner = new FileScanner([
            str_repeat('*a', 14) . '*c',
            str_repeat('a/**/', 10) . 'ab',
            '**/never-match/**',
        ], warnOnSkip: false);

        $t0 = hrtime(true);
        $files = iterator_to_array($scanner->scan($dir), false);
        $elapsed = (hrtime(true) - $t0) / 1e9;

        $this->assertCount(150, $files, 'hostile excludes must neither wedge the scan nor swallow files');
        $this->assertLessThan(5.0, $elapsed, "hostile scan took {$elapsed}s (budget: 5s)");
        $this->assertSame([], $scanner->skipNotes());
    }

    /**
     * @return array{source: string, regex: string, tokens: list<array{0: int, 1: string}>, pcre: bool}
     */
    private function compileRecord(string $glob): array
    {
        $scanner = new FileScanner([], warnOnSkip: false);
        $compile = new \ReflectionMethod(FileScanner::class, 'compilePattern');
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
        foreach (glob(sys_get_temp_dir() . '/phpdup-redos-' . getmypid() . '-*') ?: [] as $dir) {
            self::purgeTree($dir);
        }
        // once-S34: tmp git repos built by the diff-cohort tests.
        foreach ($this->s34Dirs as $dir) {
            if (is_dir($dir)) {
                self::purgeTree($dir);
            }
        }
        $this->s34Dirs = [];
        // once-S40: separator-probe scratch trees.
        foreach ($this->s40Dirs as $dir) {
            if (is_dir($dir)) {
                self::purgeTree($dir);
            }
        }
        $this->s40Dirs = [];
    }

    /** scratch dirs built by the once-S34 cohort tests */
    private array $s34Dirs = [];

    private int $s34Seq = 0;

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

    // ── once-S34 — php-dup-finder-once-038: --diff-base named roots ──────
    //    cohort mapping against the real repo toplevel, core.quotePath
    //    unicode, honest exit-2 on an empty changed cohort. Unit pins hit
    //    FileScanner::resolveDiffCohort directly; the acceptance shapes
    //    (scoped run, exit 2) ride the analyze command end-to-end.

    public function testS34NamedRootCohortIsUnderThatRootOnly(): void
    {
        $repo = $this->s34Repo();
        $this->s34Commit($repo, [
            'code/c1.php' => "<?php\nfunction c1() { return 1; }\n",
            'code/c2.php' => "<?php\nfunction c2() { return 2; }\n",
            'other/o.php' => "<?php\nfunction o() { return 3; }\n",
        ]);

        $result = FileScanner::resolveDiffCohort([$repo . '/code'], 'HEAD~1');

        $this->assertNull($result['error']);
        $this->assertSame(
            [realpath($repo . '/code/c1.php'), realpath($repo . '/code/c2.php')],
            $result['paths'],
            'cohort = the changed files under the named root, sorted, nothing from sibling roots'
        );
        $this->assertSame([], $result['unmatched']);
    }

    public function testS34FileRootCohortIsTheChangedFileItself(): void
    {
        $repo = $this->s34Repo();
        $this->s34Commit($repo, [
            'code/c1.php' => "<?php\nfunction c1() { return 1; }\n",
            'code/c2.php' => "<?php\nfunction c2() { return 2; }\n",
        ]);

        $result = FileScanner::resolveDiffCohort([$repo . '/code/c1.php'], 'HEAD~1');

        $this->assertNull($result['error']);
        $this->assertSame([realpath($repo . '/code/c1.php')], $result['paths'],
            'a file root takes part in the cohort only via itself');
        $this->assertSame([], $result['unmatched']);
    }

    public function testS34UnicodeChangedPathMapsRawBytes(): void
    {
        $repo = $this->s34Repo();
        $name = 'café.php'; // raw UTF-8: git would quote this path without core.quotePath=false
        $this->s34Commit($repo, [$name => "<?php\nfunction cafe() { return 1; }\n"]);

        // Premise pin: THIS git really quotes the name by default, so the
        // pre-fix posture (parse default-format output) dropped it.
        $quoted = $this->s34Git($repo, ['diff', '--name-only', '--no-ext-diff', '--end-of-options', 'HEAD~1..HEAD', '--']);
        $this->assertStringContainsString('\\303\\251', $quoted[0],
            'git quotes the non-ASCII name by default — the test would pass vacuously otherwise');

        $result = FileScanner::resolveDiffCohort([$repo], 'HEAD~1');

        $this->assertNull($result['error']);
        $this->assertSame([realpath($repo . '/' . $name)], $result['paths']);
        $this->assertStringContainsString('café.php', $result['paths'][0],
            'the cohort carries the raw on-disk bytes, never an octal-escape form');
        $this->assertSame([], $result['unmatched']);
    }

    public function testS34UnchangedRangeYieldsEmptyCohort(): void
    {
        $repo = $this->s34Repo();
        $this->s34Commit($repo, ['a.php' => "<?php\nfunction a() { return 1; }\n"]);

        $result = FileScanner::resolveDiffCohort([$repo], 'HEAD'); // HEAD..HEAD: clean range

        $this->assertNull($result['error'], 'a well-formed empty range is not a git failure');
        $this->assertSame([], $result['paths']);
        $this->assertSame([], $result['unmatched']);
    }

    public function testS34ChangesOutsideNamedRootYieldEmptyCohort(): void
    {
        $repo = $this->s34Repo();
        $this->s34Commit($repo, ['code/stable.php' => "<?php\nfunction stable() { return 1; }\n"], 'seed');
        $this->s34Commit($repo, ['other/o.php' => "<?php\nfunction o() { return 3; }\n"]);

        $result = FileScanner::resolveDiffCohort([$repo . '/code'], 'HEAD~1');

        $this->assertNull($result['error']);
        $this->assertSame([], $result['paths'], 'out-of-root changes are a scope decision, not a miss');
        $this->assertSame([], $result['unmatched'], 'the QA-H4 misconfiguration shape: empty, and the CLI must refuse it');
    }

    public function testS34DeletedInRangeReportedUnmatched(): void
    {
        $repo = $this->s34Repo();
        $this->s34Commit($repo, [
            'code/gone.php' => "<?php\nfunction gone() { return 1; }\n",
            'code/keep.php' => "<?php\nfunction keep() { return 1; }\n",
        ], 'seed');
        $this->s34Commit($repo, [
            'code/gone.php' => null,
            'code/keep.php' => "<?php\nfunction keep() { return 2; }\n",
        ], 'change');

        $result = FileScanner::resolveDiffCohort([$repo], 'HEAD~1');

        $this->assertNull($result['error']);
        $this->assertSame([realpath($repo . '/code/keep.php')], $result['paths']);
        $this->assertSame(['code/gone.php'], $result['unmatched'],
            'XP-03 leg: changed-but-absent paths under a root are reported, never guessed at');
    }

    public function testS34NonRepoRootFailsHonestly(): void
    {
        $dir = sys_get_temp_dir() . '/phpdup-s34-nonrepo-' . (++$this->s34Seq) . '-' . getmypid();
        mkdir($dir, 0777, true);
        $this->s34Dirs[] = $dir;
        file_put_contents($dir . '/seen.php', "<?php\nfunction seen() { return 1; }\n");

        $result = FileScanner::resolveDiffCohort([$dir], 'HEAD~1');

        $this->assertNotNull($result['error'], 'a non-repo root must abort, not pretend');
        $this->assertStringContainsString('--diff-base git diff failed in ' . $dir, $result['error']);
        $this->assertSame([], $result['paths']);
        $this->assertSame([], $result['unmatched']);
    }

    public function testS34HostileRefIsRefusedByGitAndWritesNothing(): void
    {
        $repo = $this->s34Repo();
        $this->s34Commit($repo, ['m1.php' => "<?php\nfunction m1() { return 1; }\n"]);

        // Same shell-003 payload shell-S23 pinned at stage level, now
        // against this resolver: --end-of-options pins the token into the
        // rev position, git refuses it, nothing is written.
        $hostile = '--output=' . $repo . '/PWNED';
        $result  = FileScanner::resolveDiffCohort([$repo], $hostile);

        $this->assertNotNull($result['error'], 'git must refuse the option-shaped ref');
        $this->assertStringContainsString('--diff-base git diff failed in ' . $repo, $result['error']);
        clearstatcache();
        $this->assertFileDoesNotExist($repo . '/PWNED');
        $this->assertFileDoesNotExist($repo . '/PWNED..HEAD');
    }

    public function testS34AmbiguousRefStderrNeverBecomesFilenames(): void
    {
        $repo = $this->s34Repo();
        $this->s34Git($repo, ['tag', 'amb']);
        $this->s34Git($repo, ['branch', 'amb']);
        $this->s34Commit($repo, ['amb1.php' => "<?php\nfunction amb1() { return 1; }\n"]);

        // M-2 posture: a REAL untracked file named exactly the warning
        // line git prints on stderr. The resolver parses only stdout, so
        // the decoy can never enter the cohort through a file_exists gate.
        $decoy = $repo . "/warning: refname 'amb' is ambiguous.";
        $this->assertNotFalse(file_put_contents($decoy, ''));

        $result = FileScanner::resolveDiffCohort([$repo], 'amb');

        $this->assertNull($result['error'], 'a warning-bearing ref must not fail the run');
        $this->assertContains(realpath($repo . '/amb1.php'), $result['paths']);
        foreach ($result['paths'] as $f) {
            $this->assertStringNotContainsString('warning', $f);
            $this->assertStringNotContainsString('ambiguous', $f);
        }
        clearstatcache();
        $this->assertNotContains(realpath($decoy), $result['paths']);
    }

    public function testS34DuplicateRootsCollapseAndMissingRootIsNoted(): void
    {
        $repo = $this->s34Repo();
        $this->s34Commit($repo, ['code/c1.php' => "<?php\nfunction c1() { return 1; }\n"]);

        $result = FileScanner::resolveDiffCohort(
            [$repo, $repo . '//', $repo . '/nope'],
            'HEAD~1'
        );

        $this->assertNull($result['error']);
        $this->assertSame([realpath($repo . '/code/c1.php')], $result['paths'],
            'realpath dedup across roots: one changed file yields one cohort entry');
        $this->assertSame(
            ['scan root ' . $repo . '/nope does not exist'],
            $result['unmatched'],
            'a missing root is surfaced, not silently empty'
        );
    }

    // ── end-to-end acceptance (analyze command) ───────────────────────────

    public function testS34CommandNamedRootRunsScopedNotFullScan(): void
    {
        $repo = $this->s34Repo();
        $clone = "<?php\nfunction twinBody() { \$a = 1; \$b = \$a + 41; return \$b; }\n";
        $this->s34Commit($repo, [
            'code/a1.php' => $clone,
            'code/a2.php' => $clone,
            'other/uniq.php' => "<?php\nfunction loneHatter(string \$s): array\n{\n    \$out = [];\n    foreach (explode('|', \$s) as \$k => \$v) {\n        switch (strlen(\$v)) {\n            case 0:\n                \$out[] = \$k;\n                break;\n            default:\n                \$out[\$k] = trim(\$v, \"\\0\");\n        }\n    }\n    return array_filter(\$out);\n}\n",
        ]);

        $tester = $this->s34CommandTester();
        $exit   = $tester->execute(['paths' => [$repo . '/code'], '--diff-base' => 'HEAD~1']);

        $display = $tester->getDisplay();
        $this->assertSame(0, $exit, 'scoped named-root run exits 0 (findings ride 0); display was: ' . $display);
        $this->assertStringContainsString(
            'diff-base HEAD~1: 2 changed file(s) from git diff --name-only HEAD~1..HEAD',
            $display, 'authoritative admission line — was 0 under the broken join'
        );
        $this->assertStringContainsString(
            'clone cohort: 2 changed + 0 related = 2 files total',
            $display, 'ClusterStage must SEE the cohort — its absence was the silent FULL-scan tell'
        );
        $this->assertMatchesRegularExpression(
            '/diff-base HEAD~1: 2 changed file\(s\).*\n.*scanning/s',
            $display, 'cohort line keeps its shell-S23 position ahead of discovery'
        );
    }

    public function testS34CommandRepoRootHappyPathStillGreen(): void
    {
        // Regression pin for the takeover: what used to be ScanningStage's
        // command-path output must still appear, byte-same in shape.
        $repo = $this->s34Repo();
        $this->s34Commit($repo, ['hp2.php' => "<?php\nfunction hp2() { return 2; }\n"]);

        $tester = $this->s34CommandTester();
        $exit   = $tester->execute(['paths' => [$repo], '--diff-base' => 'HEAD~1']);

        $this->assertSame(0, $exit, 'display was: ' . $tester->getDisplay());
        $this->assertStringContainsString(
            'diff-base HEAD~1: 1 changed file(s) from git diff --name-only HEAD~1..HEAD',
            $tester->getDisplay()
        );
        $this->assertStringContainsString('clone cohort: 1 changed', $tester->getDisplay());
    }

    public function testS34CommandUnicodeCohortEndToEnd(): void
    {
        $repo = $this->s34Repo();
        $this->s34Commit($repo, ['sub/naïve.php' => "<?php\nfunction naiveOne() { return 1; }\n"]);

        $tester = $this->s34CommandTester();
        $exit   = $tester->execute(['paths' => [$repo . '/sub'], '--diff-base' => 'HEAD~1']);

        $display = $tester->getDisplay();
        $this->assertSame(0, $exit, 'display was: ' . $display);
        $this->assertStringContainsString('diff-base HEAD~1: 1 changed file(s)', $display,
            'the quotePath-escaped name mapped into the cohort end-to-end');
        $this->assertStringContainsString('clone cohort: 1 changed', $display);
    }

    public function testS34CommandEmptyCohortExitsTwoWithMessage(): void
    {
        $repo = $this->s34Repo();
        $this->s34Commit($repo, ['code/stable.php' => "<?php\nfunction stable() { return 1; }\n"], 'seed');
        $this->s34Commit($repo, ['other/o.php' => "<?php\nfunction o() { return 3; }\n"]);

        $tester = $this->s34CommandTester();
        $exit   = $tester->execute(
            ['paths' => [$repo . '/code'], '--diff-base' => 'HEAD~1'],
            ['capture_stderr_separately' => true],
        );

        $combined = $tester->getErrorOutput() . $tester->getDisplay();
        $this->assertSame(2, $exit, 'empty changed cohort is exit 2 (docs contract: invalid invocation); ' . $combined);
        $this->assertStringContainsString('no changed files match the named scan root(s)', $combined);
        $this->assertStringNotContainsString('scanning ', $combined, 'the refusal precedes any pipeline output');
        $this->assertStringNotContainsString('clone cohort', $combined);
    }

    public function testS34CommandGitFailureExitsTwoWithoutContinuation(): void
    {
        $dir = sys_get_temp_dir() . '/phpdup-s34-nonrepo-' . (++$this->s34Seq) . '-' . getmypid();
        mkdir($dir, 0777, true);
        $this->s34Dirs[] = $dir;
        file_put_contents($dir . '/seen.php', "<?php\nfunction seen() { return 1; }\n");

        $tester = $this->s34CommandTester();
        $exit   = $tester->execute(
            ['paths' => [$dir], '--diff-base' => 'HEAD~1'],
            ['capture_stderr_separately' => true],
        );

        $combined = $tester->getErrorOutput() . $tester->getDisplay();
        $this->assertSame(2, $exit, 'display was: ' . $combined);
        $this->assertStringContainsString('--diff-base git diff failed in ' . $dir, $combined);
        // shell-S23 no-continuation posture, now at admission timing:
        $this->assertStringNotContainsString('scanning ', $combined);
        $this->assertStringNotContainsString('changed file(s)', $combined);
        $this->assertStringNotContainsString('block kinds', $combined);
        $this->assertStringNotContainsString('clusters', $combined);
        $this->assertStringNotContainsString('reused', $combined);
    }

    // ── once-S34 git fixture helpers ──────────────────────────────────────

    /** init an empty-history git repo in a fresh /tmp scratch dir */
    private function s34Repo(): string
    {
        $dir = sys_get_temp_dir() . '/phpdup-s34-' . (++$this->s34Seq) . '-' . getmypid() . '-' . spl_object_id($this);
        $this->assertNotFalse(mkdir($dir, 0777, true));
        $this->s34Dirs[] = $dir;
        $this->s34Git($dir, ['init', '-q', '-b', 'main']);
        $this->s34Git($dir, ['commit', '-q', '--allow-empty', '-m', 'init']);

        return $dir;
    }

    /**
     * Stage + commit one HEAD delta.
     *
     * @param array<string,string|null> $changes rel path => contents, null to delete
     */
    private function s34Commit(string $dir, array $changes, string $msg = 'change'): void
    {
        foreach ($changes as $rel => $contents) {
            if ($contents === null) {
                $this->s34Git($dir, ['rm', '-q', '--', $rel]);
                continue;
            }
            $abs = $dir . '/' . $rel;
            if (!is_dir(dirname($abs))) {
                $this->assertNotFalse(mkdir(dirname($abs), 0777, true));
            }
            $this->assertNotFalse(file_put_contents($abs, $contents));
            $this->s34Git($dir, ['add', '--', $rel]);
        }
        $this->s34Git($dir, ['commit', '-q', '-m', $msg]);
    }

    /**
     * Run git inside $dir with the test identity pinned.
     *
     * @param list<string> $args
     * @return array{0: string, 1: int} merged output, exit code
     */
    private function s34Git(string $dir, array $args): array
    {
        $argv = ['git', '-C', $dir,
            '-c', 'user.email=test@phpdup.invalid',
            '-c', 'user.name=phpdup-test',
            '-c', 'commit.gpgsign=false',
            '-c', 'core.hooksPath=/dev/null',
            ...$args,
        ];
        $cmd = implode(' ', array_map('escapeshellarg', $argv)) . ' 2>&1';
        $out = [];
        $code = 0;
        exec($cmd, $out, $code);
        $this->assertSame(0, $code, 'git ' . implode(' ', $args) . ' failed: ' . implode("\n", $out));

        return [implode("\n", $out), $code];
    }

    private function s34CommandTester(): CommandTester
    {
        $app = new Application('phpdup');
        $app->add(new Command());

        return new CommandTester($app->find('analyze'));
    }

    // ── once-S40 — php-dup-finder-once-044: Windows path portability ──────
    //
    // No Windows runner exists in this fleet, so the behaviour is proven
    // by injecting the exact byte-shapes a Windows walk hands the
    // boundary (native "\" joins, drive-prefixed roots) — not by
    // claims. The MANUAL Windows confirmation row lives in the step
    // report for the owner's box.

    /** scratch dirs built by the once-S40 separator probes */
    private array $s40Dirs = [];

    private int $s40Seq = 0;

    /**
     * Drive one string pair through the private relpath() boundary —
     * the join shape RecursiveDirectoryIterator produces on the host
     * platform, fabricated here in the other platform's dialect.
     */
    private function s40Relpath(string $abs, string $root): string
    {
        $scanner = new FileScanner([], warnOnSkip: false);
        $relpath = new \ReflectionMethod(FileScanner::class, 'relpath');

        return $relpath->invoke($scanner, $abs, $root);
    }

    public function testS40RelpathMapsBackslashAndDriveForms(): void
    {
        $this->assertSame('vendor/x.php', $this->s40Relpath('src\\vendor\\x.php', 'src'));
        $this->assertSame('src/Foo.php', $this->s40Relpath('C:\\Users\\proj\\src\\Foo.php', 'C:\\Users\\proj'));
        // A root quoted with its trailing native separator still strips.
        $this->assertSame('a.php', $this->s40Relpath('C:\\Users\\proj\\a.php', 'C:\\Users\\proj\\'));
        // Case-folded root spelling strips; the remainder keeps its own bytes.
        $this->assertSame('Src/Foo.php', $this->s40Relpath('C:\\Users\\PROJ\\Src\\Foo.php', 'c:\\users\\proj'));
        // The entry IS the root: the walk's own '' gate survives folding.
        $this->assertSame('', $this->s40Relpath('C:\\Users\\Proj', 'c:\\users\\proj'));
        // Slash-form walks are fixed points (the once-S34 leg included).
        $this->assertSame('vendor/x.php', $this->s40Relpath('/proj/src/vendor/x.php', '/proj/src'));
    }

    public function testS40IsExcludedDecidesInjectedSeparators(): void
    {
        $scanner = new FileScanner(['**/vendor/**'], warnOnSkip: false);
        // Pre-fix this was FALSE forever: the "\" never built a "/"
        // segment, so an anchored vendor exclude could not see a
        // Windows vendor path and the tree was silently SCANNED.
        $this->assertTrue($scanner->isExcluded('C:\\Users\\proj\\vendor\\y.php'));
        $this->assertTrue($scanner->isExcluded('src\\vendor\\x.php'));
        $this->assertTrue($scanner->isExcluded('vendor\\'), 'directory-prune form in native spelling');
        $this->assertFalse($scanner->isExcluded('a\\myvendor\\x.php'), 'the segment-safe guard survives folding');
    }

    public function testS40ExclusionMatchingStaysCaseSensitive(): void
    {
        // once-S40 r1 LOW-4: the fold law is prose in the PathCanonical
        // header — this is its pin. If a future change ever folds the
        // isExcluded SUBJECT by case (separators() -> fold()), the
        // Vendor/ assertions below flip to TRUE and this test goes RED.
        $scanner = new FileScanner(['**/vendor/**'], warnOnSkip: false);
        $this->assertTrue($scanner->isExcluded('vendor/x.php'), 'the lowercase tree is excluded');
        $this->assertFalse($scanner->isExcluded('Vendor/x.php'), 'matching stays case-sensitive: Vendor/ is a distinct tree');
        $this->assertFalse($scanner->isExcluded('SRC/Vendor/x.php'), 'the guard holds at depth too');
    }

    public function testS40BackslashSpelledGlobsCompileCanonical(): void
    {
        $scanner = new FileScanner(['**\\vendor\\**'], warnOnSkip: false);
        $this->assertTrue($scanner->isExcluded('vendor/x.php'), 'a Windows-authored exclude guards a slash-form walk');
        $this->assertTrue($scanner->isExcluded('C:\\repo\\vendor\\x.php'));
        $this->assertFalse($scanner->isExcluded('myvendor/x.php'), 'coalescing/segment safety survives normalization');
    }

    public function testS40UnderRootsNormalizesSeparatorsAndCase(): void
    {
        $this->assertSame(
            'src/Foo.php',
            PathCanonical::underRoots('C:\\Users\\proj\\src\\Foo.php', ['C:\\Users\\proj'])
        );
        $this->assertSame(
            'Util/a.php',
            PathCanonical::underRoots('/Proj/Src/Util/a.php', ['/proj/src']),
            'case-folded root spelling strips'
        );
        // Trailing-separator spellings of one root all agree.
        foreach (['/proj', '/proj/', '/proj//'] as $root) {
            $this->assertSame('a.php', PathCanonical::underRoots('/proj/a.php', [$root]));
        }
        // Longest folded match still wins.
        $this->assertSame('a.php', PathCanonical::underRoots('C:\\p\\q\\a.php', ['C:\\p', 'C:\\p\\q']));
        // A single-file root in drive form reduces to its basename.
        $this->assertSame('k.php', PathCanonical::underRoots('D:\\proj\\k.php', ['D:\\proj\\k.php']));
        // The no-match fallback emits the separator-folded form: raw
        // bytes can no longer leak "\" into ids and baselines.
        $this->assertSame('C:/x/y.php', PathCanonical::underRoots('C:\\x\\y.php', ['/proj']));
        // Slash-form behaviour is untouched (the NumPathTest contract).
        $this->assertSame('Util/a.php', PathCanonical::underRoots('/proj/src/Util/a.php', ['/proj', '/proj/src']));
        $this->assertSame('bare.php', PathCanonical::underRoots('bare.php', []));
        $this->assertSame('/elsewhere/x/Foo.php', PathCanonical::underRoots('/elsewhere/x/Foo.php', ['/proj']));
    }

    public function testS40FabricatedSeparatorTreeKeepsVendorExcluded(): void
    {
        $base = sys_get_temp_dir() . '/phpdup-s40-sep-' . (++$this->s40Seq) . '-' . getmypid();
        $this->s40Dirs[] = $base;
        $this->assertNotFalse(mkdir($base, 0777, true));
        $this->assertNotFalse(mkdir($base . '/vendor', 0777, true));
        $this->assertNotFalse(file_put_contents($base . '/vendor/a.php', "<?php\nreturn 1;\n"));
        $this->assertNotFalse(file_put_contents($base . '/top.php', "<?php\nreturn 2;\n"));
        // A Linux-legal filename containing a literal backslash: under
        // the fold law it is DECIDED in folded shape, but the walk must
        // still yield it with native bytes.
        $this->assertNotFalse(file_put_contents($base . '/weird\\name.php', "<?php\nreturn 3;\n"));

        $excludes = ['**/vendor/**'];
        $scanner = new FileScanner($excludes, warnOnSkip: false);
        $files = iterator_to_array($scanner->scan($base), false);
        $basenames = array_map('basename', $files);
        sort($basenames);

        $this->assertSame(
            ['top.php', 'weird\\name.php'],
            $basenames,
            'the vendor branch never re-enters; the backslash-named file stays native'
        );
        $this->assertSame([], $scanner->skipNotes());

        // A root quoted with a trailing separator scans identically.
        $again = iterator_to_array((new FileScanner($excludes, warnOnSkip: false))->scan($base . '/'), false);
        $this->assertSame($files, $again);
    }
}
