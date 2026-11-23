<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Reporting;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Parsing\AstParser;
use Phpdup\Reporting\DiffReporter;
use Phpdup\Reporting\Report;
use Phpdup\Reporting\SourceTextStore;
use Phpdup\Util\LineRange;

final class DiffReporterTest extends TestCase
{
    /**
     * output-S56b synthetic carrier for the retired sql-fixture pipeline
     * report: two 2-member clusters over freshly written temp sources —
     * member bodies differ, so every rendered .diff carries real hunk
     * bytes — plus one 1-member cluster that states the <2-member skip
     * rule by construction. The caller rrmdirs the returned root.
     *
     * @return array{0: Report, 1: string}
     */
    private function syntheticTree(): array
    {
        $root = sys_get_temp_dir() . '/phpdup-diff-syn-' . uniqid('', true);
        mkdir($root, 0o775, true);
        $mk = function (string $name, int $value) use ($root): Block {
            $file = $root . '/' . $name . '.php';
            file_put_contents($file, "<?php\nfunction m() { return {$value}; }\n");
            return $this->realBlock($file);
        };
        $a = new Cluster('SYN-A', [$mk('a1', 1), $mk('a2', 2)], 1.0, true);
        $a->signature = 'function m(): int';
        $b = new Cluster('SYN-B', [$mk('b1', 3), $mk('b2', 4)], 0.9, false);
        $b->signature = 'function m(): int';
        $lone = new Cluster('SYN-LONE', [$mk('lone', 5)], 1.0, true);
        $lone->signature = 'function m(): int';

        return [new Report(
            files: 5, blocks: 5, parseErrors: 0,
            clusters: [$a, $b, $lone], config: Config::defaults([$root]),
        ), $root];
    }

    public function testWriteDirCreatesOneFilePerCluster(): void
    {
        [$report, $root] = $this->syntheticTree();
        $dir = $root . '/out';
        try {
            (new DiffReporter())->writeDir($report, $dir);

            $this->assertDirectoryExists($dir);
            // output-S56b: the fixture era re-derived the count from the
            // report it had just built (a tautology) behind a premise
            // guard; the synthetic tree carries exactly two multi-member
            // clusters and one lone cluster, so 2 is now pinned hard and
            // the skip rule rides the same vector.
            $files = glob($dir . '/*.diff');
            $this->assertCount(2, $files, 'SYN-A and SYN-B written; SYN-LONE skipped');

            foreach ($files as $f) {
                $body = (string)file_get_contents($f);
                $this->assertStringContainsString('# phpdup cluster', $body);
                $this->assertStringContainsString('# Suggested abstraction:', $body);
            }
        } finally {
            $this->rrmdir($root);
        }
    }

    public function testWritePatchProducesSingleFile(): void
    {
        [$report, $root] = $this->syntheticTree();
        $tmp = $root . '/all.patch';
        try {
            (new DiffReporter())->writePatch($report, $tmp);
            $this->assertFileExists($tmp);
            $body = (string)file_get_contents($tmp);
            $this->assertStringContainsString('# phpdup cluster', $body);
            // Strengthened (output-S56b): the patch is exactly the two
            // multi-member clusters concatenated — the lone one skips.
            $this->assertSame(2, substr_count($body, '# phpdup cluster'));
        } finally {
            $this->rrmdir($root);
        }
    }

    // ── output-011: newline-bearing paths are refused, never embedded ───────

    public function testNewlineBearingMemberPathRefusesWriteDirBeforeCreatingAnything(): void
    {
        $dir = sys_get_temp_dir() . '/phpdup-diff-refuse-' . uniqid('', true);
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches('/DiffReporter.*newline \(0x0A\)/');
            (new DiffReporter())->writeDir($this->forgedReport("evil\n--- /dev/null"), $dir);
        } finally {
            $this->assertDirectoryDoesNotExist($dir, 'refusal must happen before any output exists');
        }
    }

    /**
     * Every byte of the forging class is refused on both stream surfaces,
     * and the named diagnostic identifies the byte (tabs — legal filename
     * bytes — are NOT part of the class and must not trip it).
     *
     * @dataProvider forgeByteProvider
     */
    public function testEachForgeByteIsRefusedOnPatchAndDirSurfaces(string $byte, string $hexName): void
    {
        $evil = 'dir' . $byte . '/evil.php';
        $report = $this->forgedReport($evil);
        $patchPath = sys_get_temp_dir() . '/phpdup-forged-' . uniqid('', true) . '.patch';

        foreach ([
            'writeDir'   => static function (DiffReporter $r, Report $rep) use ($evil): void {
                $dir = $evil . '-out'; // path itself must never be created
                $r->writeDir($rep, $dir);
            },
            'writePatch' => static function (DiffReporter $r, Report $rep) use ($patchPath): void {
                $r->writePatch($rep, $patchPath);
            },
        ] as $surface => $call) {
            try {
                $call(new DiffReporter(), $report);
                $this->fail("{$surface} must refuse a member path containing {$hexName}");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('DiffReporter', $e->getMessage());
                $this->assertStringContainsString($hexName, $e->getMessage());
                $this->assertStringContainsString('member file path', $e->getMessage());
                $this->assertFileDoesNotExist($patchPath);
            }
        }
    }

    /** @return array<string,array{string,string}> */
    public static function forgeByteProvider(): array
    {
        return [
            'NUL'       => ["\0", '0x00'],
            'newline'   => ["\n", '0x0A'],
            'carriage'  => ["\r", '0x0D'],
            'escape'    => ["\x1b", '0x1B'],
        ];
    }

    public function testLegalTabAndSpacePathsStillEmit(): void
    {
        $dir  = sys_get_temp_dir() . '/phpdup-diff-tabbed-' . uniqid('', true);
        $tabbed = $dir . '/sub dir/wi th' . chr(9) . 'tab';
        mkdir($tabbed, 0o775, true);
        $src = $tabbed . '/t.php';
        file_put_contents($src, "<?php\nfunction f() { g(1); }\n");
        try {
            $report = $this->forgedReport($src, realSource: true);
            (new DiffReporter())->writeDir($report, $dir);
            $bodies = array_map(
                static fn(string $f): string => (string)file_get_contents($f),
                glob($dir . '/*.diff') ?: [],
            );
            $this->assertNotEmpty($bodies);
            $this->assertStringContainsString('wi th' . chr(9) . 'tab/t.php', implode('', $bodies));
        } finally {
            $this->rrmdir($dir);
        }
    }

    public function testCleanClusterStreamKeepsParseableStructure(): void
    {
        [$report, $root] = $this->syntheticTree();
        try {
            $body = '';
            foreach ($report->clusters as $c) {
                if (count($c->members) >= 2) {
                    $body .= $this->renderCluster($c);
                }
            }
        } finally {
            $this->rrmdir($root);
        }
        $this->assertNotSame('', $body, 'synthetic tree must yield at least one diff');

        // Header pairs and anchors sit at line starts only — the shape a
        // maintainer (or git apply) reads — and no anchor line is bare.
        $this->assertMatchesRegularExpression('/^## member\[\d+\]: \S+:\d+-\d+$/m', $body);
        $this->assertSame(
            substr_count($body, "## member["),
            substr_count($body, "--- a/"),
            'one header pair per member section, no forged extras',
        );
        $this->assertSame(substr_count($body, "--- a/"), substr_count($body, "+++ b/"));
    }

    // ── output-035 (S6): metadata rows defuse, diff bytes stay raw ────────────

    public function testMetadataRowsDefuseFormulaTriggersAndHunkBytesStayRaw(): void
    {
        $dir = sys_get_temp_dir() . '/phpdup-diff-defuse-' . uniqid('', true);
        $src = $dir . '/sub';
        mkdir($src, 0o775, true);
        $fileA = $src . '/a.php';
        $fileB = $src . '/b.php';
        file_put_contents($fileA, "<?php\nfunction m() { return 1; }\n");
        file_put_contents($fileB, "<?php\nfunction m() { return 2; }\n");
        try {
            // Attack-shaped name/signature plus a relative trigger-leading
            // member path (what a scan of `=evil.php` actually yields); the
            // third member has no source on disk, so output-038 states it as
            // an explicit "# source not read (reason)" metadata row instead
            // of the old fabricated "<source unavailable>" hunk bytes.
            $cluster = new Cluster("=cmd|'/C calc'", [
                $this->realBlock($fileA), $this->realBlock($fileB), $this->block('=evil.php'),
            ], 1.0, false);
            $cluster->signature = "=cmd|'/C calc'";
            $body = $this->renderCluster($cluster);

            // Guarded metadata: cluster name and signature rows carry the
            // text prefix; the trigger-leading member path is defused.
            $this->assertStringContainsString("# phpdup cluster '=cmd|'/C calc'", $body);
            $this->assertStringContainsString("#   '=cmd|'/C calc'", $body);
            $this->assertStringContainsString("# Anchor (members[0] fallback): {$fileA}:2-2", $body);
            $this->assertStringContainsString("## member[2]: '=evil.php:1-1", $body);

            // Carve-out: the diff headers and hunk payload are raw bytes —
            // a defused path there would corrupt the patch structure.
            $this->assertStringContainsString('--- a/' . $fileA, $body);
            $this->assertStringNotContainsString("--- a/'", $body);
            $this->assertStringContainsString("#   source not read (file not present)", $body);
            $this->assertStringNotContainsString('<source unavailable', $body);
            $this->assertStringNotContainsString('<unreadable', $body);
            $this->assertMatchesRegularExpression('/^\+function m\(\) \{ return 2; \}$/m', $body);
            $this->assertMatchesRegularExpression('/^-function m\(\) \{ return 1; \}$/m', $body);
            $this->assertDoesNotMatchRegularExpression("/^'[-+@]/m", $body, 'no defuse prefix may reach hunk line starts');
        } finally {
            $this->rrmdir($dir);
        }
    }

    private function renderCluster(Cluster $cluster): string
    {
        // buildClusterDiff is private; exercise it through the file surface.
        $dir = sys_get_temp_dir() . '/phpdup-diff-render-' . uniqid('', true);
        try {
            (new DiffReporter())->writeDir(new Report(
                files: 2, blocks: count($cluster->members), parseErrors: 0,
                clusters: [$cluster], config: Config::defaults([]),
            ), $dir);
            $files = glob($dir . '/*.diff') ?: [];
            return $files === [] ? '' : (string)file_get_contents($files[0]);
        } finally {
            $this->rrmdir($dir);
        }
    }

    // ── output-038: explicit unfetched posture, no fabricated hunks ──────────

    public function testUnfetchedAnchorStatesPostureWithoutFabricatedHunks(): void
    {
        $cluster = new Cluster('ALL-FAKE', [
            $this->block('nope-a.php'), $this->block('nope-b.php'),
        ], 1.0, false);
        $cluster->signature = 'function s(): int';
        $body = $this->renderCluster($cluster);

        $this->assertStringContainsString('# phpdup cluster ALL-FAKE', $body);
        $this->assertStringContainsString('#   source not read (file not present)', $body);
        $this->assertStringNotContainsString('<source unavailable', $body, 'placeholder strings must not ride the stream');
        $this->assertStringNotContainsString('@@', $body, 'an unreadable base leaves no hunk bytes to diff against');
        $this->assertStringNotContainsString('## member[', $body, 'no per-member sections when the anchor itself is unfetched');
    }

    public function testUnfetchedMemberKeepsAnchorButEmitsNoHeaderPair(): void
    {
        $dir  = sys_get_temp_dir() . '/phpdup-diff-posture-' . uniqid('', true);
        $src  = $dir . '/sub';
        mkdir($src, 0o775, true);
        $fileA = $src . '/a.php';
        $fileB = $src . '/b.php';
        file_put_contents($fileA, "<?php\nfunction m() { return 1; }\n");
        file_put_contents($fileB, "<?php\nfunction m() { return 2; }\n");
        try {
            $cluster = new Cluster('MIXED', [
                $this->realBlock($fileA), $this->realBlock($fileB), $this->block('gone.php'),
            ], 1.0, false);
            $cluster->signature = 'function m(): int';
            $body = $this->renderCluster($cluster);

            // The fetched member still gets its diff; the unfetched one gets
            // an anchor + statement row only — pairing law: a `## member[`
            // anchor without content must not be counted as a header pair.
            $this->assertSame(2, substr_count($body, '## member['));
            $this->assertSame(1, substr_count($body, '--- a/'));
            $this->assertStringContainsString("## member[2]: gone.php:1-1\n#   source not read (file not present)\n", $body);
            $this->assertStringNotContainsString('<source unavailable: gone.php>', $body);
        } finally {
            $this->rrmdir($dir);
        }
    }

    public function testPlantedSymlinkEscapeIsRefusedAndNotedOnTheDiffSurface(): void
    {
        $dir    = sys_get_temp_dir() . '/phpdup-diff-escape-' . uniqid('', true);
        $outside = $dir . '-outside';
        mkdir($dir . '/sub', 0o775, true);
        mkdir($outside, 0o775, true);
        $secret = $outside . '/secret.php';
        file_put_contents($secret, "<?php\nfunction m() { return 'TOP-SECRET-OUT-OF-ROOT'; }\n");
        $fileA  = $dir . '/sub/a.php';
        $link   = $dir . '/sub/link.php';
        file_put_contents($fileA, "<?php\nfunction m() { return 1; }\n");
        symlink($secret, $link);
        $store = new SourceTextStore([$dir], warnOnSkip: false);
        try {
            $cluster = new Cluster('ESCAPE', [$this->realBlock($fileA), $this->realBlock($link)], 1.0, false);
            $cluster->signature = 'function m(): int';
            $body = $this->renderClusterWithStore($cluster, $store, $dir);

            $this->assertStringNotContainsString('TOP-SECRET-OUT-OF-ROOT', $body, 'out-of-root content never enters the artifact');
            $this->assertStringNotContainsString('***', $body);
            $this->assertStringContainsString('#   source not read (target escapes the scan root)', $body);
            $this->assertSame(
                ["skipped symlinked path {$link} (target escapes the scan root)"],
                $store->skipNotes(),
            );
        } finally {
            $this->rrmdir($dir);
            $this->rrmdir($outside);
        }
    }

    public function testSharedStoreReadsEachFileOnceAcrossSurfaces(): void
    {
        $dir = sys_get_temp_dir() . '/phpdup-diff-once-' . uniqid('', true);
        mkdir($dir, 0o775, true);
        $fileA = $dir . '/a.php';
        $fileB = $dir . '/b.php';
        file_put_contents($fileA, "<?php\nfunction m() { return 1; }\n");
        file_put_contents($fileB, "<?php\nfunction m() { return 2; }\n");
        try {
            $a = $this->realBlock($fileA);
            $b = $this->realBlock($fileB);
            // One cluster, four member slots over two files; the shared
            // store must serve two whole-file reads, not four (and both
            // surfaces together still two).
            $cluster = new Cluster('SHARED', [$a, $b, $a, $b], 1.0, false);
            $cluster->signature = 'function m(): int';
            $report = new Report(
                files: 2, blocks: 4, parseErrors: 0,
                clusters: [$cluster], config: Config::defaults([$dir]),
            );
            $store = new SourceTextStore([$dir]);
            $outDir = $dir . '/diffs';
            (new DiffReporter($store))->writeDir($report, $outDir);
            (new DiffReporter($store))->writePatch($report, $dir . '/all.patch');

            $this->assertSame(2, $store->readCount(), 'two distinct files = two reads, never member-slots × surfaces');
            $this->assertFileExists($dir . '/all.patch');
        } finally {
            $this->rrmdir($dir);
        }
    }

    private function renderClusterWithStore(Cluster $cluster, SourceTextStore $store, string $root): string
    {
        $dir = sys_get_temp_dir() . '/phpdup-diff-render2-' . uniqid('', true);
        try {
            (new DiffReporter($store))->writeDir(new Report(
                files: 2, blocks: count($cluster->members), parseErrors: 0,
                clusters: [$cluster], config: Config::defaults([$root]),
            ), $dir);
            $files = glob($dir . '/*.diff') ?: [];
            return $files === [] ? '' : (string)file_get_contents($files[0]);
        } finally {
            $this->rrmdir($dir);
        }
    }

    /** Report with one 2-member cluster whose member paths carry the forgery. */
    private function forgedReport(string $evilFile, bool $realSource = false): Report
    {
        $members = $realSource
            ? [$this->realBlock($evilFile), $this->realBlock($evilFile)]
            : [$this->block($evilFile), $this->block($evilFile)];
        $cluster = new Cluster('FORGED', $members, 1.0, false);
        return new Report(
            files: 2, blocks: 2, parseErrors: 0, clusters: [$cluster], config: Config::defaults([]),
        );
    }

    private function block(string $file): Block
    {
        $stmts = (new AstParser())->parseCode('<?php $x;');
        return new Block(
            file: $file,
            range: new LineRange(1, 1),
            kind: 'method',
            namespace: null, class: null, name: null,
            ast: $stmts[0],
        );
    }

    private function realBlock(string $file): Block
    {
        $stmts = (new AstParser())->parseCode((string)file_get_contents($file));
        return new Block(
            file: $file,
            range: new LineRange(2, 2),
            kind: 'function',
            namespace: null, class: null, name: null,
            ast: $stmts[0],
        );
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') continue;
            $p = $dir . '/' . $f;
            is_dir($p) ? $this->rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}
