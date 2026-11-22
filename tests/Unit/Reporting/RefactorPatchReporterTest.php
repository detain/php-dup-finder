<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Reporting;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Parsing\AstParser;
use Phpdup\Refactor\Hole;
use Phpdup\Reporting\RefactorPatchReporter;
use Phpdup\Reporting\RefactorTestReporter;
use Phpdup\Reporting\Report;
use Phpdup\Reporting\SourceTextStore;
use Phpdup\Reporting\WritesReportFile;
use Phpdup\Util\LineRange;

final class RefactorPatchReporterTest extends TestCase
{
    public function testPatchHeaderCarriesClusterMetadata(): void
    {
        $cluster = $this->cluster();
        $cluster->signature = "function f(int \$x): mixed";
        $patch = (new RefactorPatchReporter())->buildPatch($cluster);
        $this->assertStringContainsString("cluster {$cluster->id}", $patch);
        $this->assertStringContainsString("members: 2", $patch);
    }

    public function testPatchEmitsAddedAbstractionFile(): void
    {
        $cluster = $this->cluster();
        $cluster->signature = "function f(int \$x): mixed";
        $patch = (new RefactorPatchReporter())->buildPatch($cluster);
        $this->assertStringContainsString("Refactored/{$cluster->id}.php", $patch);
        $this->assertStringContainsString("new file mode 100644", $patch);
        $this->assertStringContainsString('TODO: implement', $patch);
    }

    public function testPatchSkipsWhenSignatureMissing(): void
    {
        $cluster = $this->cluster();
        $patch = (new RefactorPatchReporter())->buildPatch($cluster);
        $this->assertStringContainsString('No signature synthesised', $patch);
    }

    // ── output-035 (S6): metadata rows defuse, patch bytes stay raw ───────────

    public function testMetadataRowsDefuseFormulaTriggersAndPatchBytesStayRaw(): void
    {
        $cluster = new Cluster("=cmd|'/C calc'", [$this->blk('=evil.php'), $this->blk('clean.php')], 1.0, false);
        $cluster->signature = 'function f(int $x): mixed';
        $patch = (new RefactorPatchReporter())->buildPatch($cluster);

        // Guarded metadata: cluster name and the member-hint paths.
        $this->assertStringContainsString("# phpdup refactor patch — cluster '=cmd|'/C calc'", $patch);
        $this->assertStringContainsString("# member[0] @ '=evil.php:1-1", $patch);
        $this->assertStringContainsString('# member[1] @ clean.php:1-1', $patch);

        // Carve-out: the synthetic hunk payload stays byte-faithful — the
        // structural `+` lines must not pick up a defuse prefix.
        $this->assertMatchesRegularExpression('/^\+function f\(int \$x\): mixed$/m', $patch);
        $this->assertStringContainsString("+<?php\n+declare(strict_types=1);", $patch);
        $this->assertDoesNotMatchRegularExpression("/^'[-+@]/m", $patch, 'no defuse prefix may reach patch body line starts');
        $this->assertStringContainsString('+++ b/Refactored/', $patch);
    }

    public function testApplySummaryMetadataRowsAreDefused(): void
    {
        // F1b arm: the "# cluster {id}: ..." skip rows are metadata.
        // output-022: the summary lands as apply-summary.md — its own name,
        // never masquerading under the dry branch's apply.diff.
        $dir = sys_get_temp_dir() . '/phpdup-patch-apply-defuse-' . uniqid('', true);
        $cluster = new Cluster('=SUM(A1)', [$this->blk('=evil.php'), $this->blk('clean.php')], 1.0, false);
        $report = new Report(
            files: 2, blocks: 2, parseErrors: 0, clusters: [$cluster], config: Config::defaults([]),
        );
        try {
            (new RefactorPatchReporter())->writeTo($report, $dir, apply: true, dryRun: false);
            $summaryPath = $dir . DIRECTORY_SEPARATOR . RefactorPatchReporter::APPLY_SUMMARY_FILE;
            $this->assertFileExists($summaryPath);
            $this->assertFileDoesNotExist(
                $dir . DIRECTORY_SEPARATOR . RefactorPatchReporter::APPLY_DIFF_FILE,
                'apply mode must not write anything under the diff name',
            );
            $summary = (string)file_get_contents($summaryPath);
            $this->assertStringStartsWith('# phpdup apply summary', $summary);
            $this->assertStringContainsString("# cluster '=SUM(A1): no signature", $summary);
        } finally {
            $this->rrmdir($dir);
        }
    }

    public function testTestSkeletonHasOneRowPerMember(): void
    {
        $cluster = $this->cluster();
        $cluster->holes = [new Hole('__P0', 'literal', ['1', '2'])];
        $skeleton = (new RefactorTestReporter())->buildTest($cluster);
        $this->assertStringContainsString("class Cluster{$cluster->id}Test", $skeleton);
        $this->assertStringContainsString('casesProvider', $skeleton);
        // Two member rows in the provider.
        $this->assertSame(2, substr_count($skeleton, "'member_"));
    }

    public function testGuidanceNamesTheDeclaredFunctionNotAClusterGhost(): void
    {
        // M4: the shipped declaration is the (registry-resolved) signature
        // name; the member hints must point at exactly that name — a human
        // following the guidance verbatim must not call an undefined ghost.
        $a = $this->cluster();
        $a->signature = "function sharedThing(\$v): mixed";
        $b = $this->namedCluster('OTHER');
        $b->signature = "function sharedThing(\$v): mixed";
        (new \Phpdup\Refactor\FunctionNameRegistry())->resolve([$a, $b]);

        $patchA = (new RefactorPatchReporter())->buildPatch($a);
        $patchB = (new RefactorPatchReporter())->buildPatch($b);

        $this->assertSame('sharedThing', $this->declarationName($patchA));
        $this->assertSame('sharedThing_OTHER', $this->declarationName($patchB), 'loser carries its content-id suffix');
        $this->assertCountAtLeast2($this->guidanceNames($patchA));
        $this->assertSame(
            array_fill(0, count($this->guidanceNames($patchA)), $this->declarationName($patchA)),
            $this->guidanceNames($patchA),
        );
        $this->assertSame(
            array_fill(0, count($this->guidanceNames($patchB)), $this->declarationName($patchB)),
            $this->guidanceNames($patchB),
        );
        $this->assertStringNotContainsString('f_' . $b->id, $patchB, 'the f_{clusterId} ghost is gone');
    }

    /** @param list<string> $names */
    private function assertCountAtLeast2(array $names): void
    {
        $this->assertGreaterThanOrEqual(2, count($names), 'one hint per member');
    }

    private function declarationName(string $patch): ?string
    {
        return preg_match('/^\+function ([a-zA-Z_][a-zA-Z0-9_]*)\(/m', $patch, $m) === 1 ? $m[1] : null;
    }

    /** @return list<string> names the guidance tells the human to call */
    private function guidanceNames(string $patch): array
    {
        preg_match_all('/return \\\\Refactored\\\\([a-zA-Z_][a-zA-Z0-9_]*)\(/', $patch, $m);
        return $m[1];
    }

    // ── output-011 / SEC-03: forged stream lines are refused at build time ──

    public function testAdversarialNewlineMemberPathIsRefusedAtBuildTime(): void
    {
        // Tab (a legal filename byte) must NOT trip the guard; the newline
        // in the second member's path must — with a named diagnostic.
        $cluster = new Cluster(
            'EVIL',
            [$this->blk("x\ty.php"), $this->blk("evil\n--- /dev/null\n++ hacked.php")],
            1.0,
            false,
        );
        try {
            (new RefactorPatchReporter())->buildPatch($cluster);
            $this->fail('buildPatch must refuse a newline-bearing member path');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('RefactorPatchReporter', $e->getMessage());
            $this->assertStringContainsString('member file path', $e->getMessage());
            $this->assertStringContainsString('newline (0x0A)', $e->getMessage());
            $this->assertStringNotContainsString("\n", $e->getMessage(), 'the diagnostic renders the byte visibly');
        }
    }

    public function testPatchStreamCannotBeForgedIntoFileCreation(): void
    {
        // SEC-03 regression: the original proof made `patch -p1` CREATE an
        // attacker-named file from a newline-in-dirname member path. Under
        // the refusal arm the forged stream never exists, so the write
        // primitive is structurally impossible — and nothing is created
        // anywhere before the refusal.
        $attackerFile = 'attacker-created-' . uniqid('', true) . '.php';
        $evilPath     = sys_get_temp_dir() . "/phpdup-x\n" . $attackerFile;
        $report       = new Report(
            files: 2,
            blocks: 2,
            parseErrors: 0,
            clusters: [new Cluster('EVIL', [$this->blk($evilPath), $this->blk('other.php')], 1.0, false)],
            config: Config::defaults([]),
        );
        $outDir = sys_get_temp_dir() . '/phpdup-patch-refuse-' . uniqid('', true);

        try {
            (new RefactorPatchReporter())->writeTo($report, $outDir);
            $this->fail('writeTo must refuse the forged member path');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('RefactorPatchReporter', $e->getMessage());
        }

        $this->assertDirectoryDoesNotExist($outDir, 'refusal happens before mkdir');
        $this->assertSame(
            [],
            glob(sys_get_temp_dir() . '/' . $attackerFile) ?: [],
            'the attacker-named file can exist neither in the tree nor on disk',
        );
    }

    // ── output-038 / -043: memoized verdict + confined .patch surface ────────

    public function testUnsafeVerdictIsComputedOncePerClusterOnTheApplySurface(): void
    {
        $dir = sys_get_temp_dir() . '/phpdup-patch-memo-' . uniqid('', true);
        mkdir($dir, 0o775, true);
        $a = $dir . '/a.php';
        $b = $dir . '/b.php';
        file_put_contents($a, "<?php\nfunction m1(\$x) { return \$this->dep(\$x); }\n");
        file_put_contents($b, "<?php\nfunction m2(\$x) { return \$this->dep(\$x); }\n");
        $out = $dir . '/apply';
        try {
            $report = $this->reportWith([$this->realBlk($a), $this->realBlk($b)], 'MEMO');
            $r = new RefactorPatchReporter(new SourceTextStore([$dir]));
            $r->writeTo($report, $out, apply: true, dryRun: false);

            // output-043: the F1b gate computed the verdict; the unsafe
            // fallback's buildPatch reused it. Two calls, one computation.
            $this->assertSame(1, $r->detectUnsafeComputations());
            $summary = (string)file_get_contents($out . '/' . RefactorPatchReporter::APPLY_SUMMARY_FILE);
            $this->assertStringContainsString('member uses $this', $summary);
        } finally {
            $this->rrmdir($dir);
        }
    }

    public function testPlantedSymlinkEscapeEmbedsNoOutRootContentInPatches(): void
    {
        $dir     = sys_get_temp_dir() . '/phpdup-patch-escape-' . uniqid('', true);
        $outside = $dir . '-outside';
        mkdir($dir, 0o775, true);
        mkdir($outside, 0o775, true);
        $secret = $outside . '/secret.php';
        file_put_contents($secret, "<?php\nfunction m2() { return 'TOP-SECRET-OUT-OF-ROOT'; }\n");
        $a = $dir . '/a.php';
        file_put_contents($a, "<?php\nfunction m1() { return 1; }\n");
        $link = $dir . '/link.php';
        symlink($secret, $link);
        $out = $dir . '/patches';
        try {
            $report = $this->reportWith([$this->realBlk($a), $this->realBlk($link)], 'ESCP');
            $store  = new SourceTextStore([$dir], warnOnSkip: false);
            (new RefactorPatchReporter($store))->writeTo($report, $out);

            $bodies = implode('', array_map(
                static fn(string $f): string => (string)file_get_contents($f),
                glob($out . '/*.patch') ?: [],
            ));
            $this->assertNotSame('', $bodies, 'the cluster still gets its patch — minus the refused bytes');
            $this->assertStringNotContainsString('TOP-SECRET-OUT-OF-ROOT', $bodies);
            $this->assertSame(["skipped symlinked path {$link} (target escapes the scan root)"], $store->skipNotes());
        } finally {
            $this->rrmdir($dir);
            $this->rrmdir($outside);
        }
    }

    // ── output-022: computed hunk header, distinct summary artifact, trait writes ──

    public function testHunkHeaderCountMatchesEmittedPayloadSingleLineSignature(): void
    {
        $cluster = $this->cluster();
        $cluster->signature = 'function f(int $x): mixed';
        $patch = (new RefactorPatchReporter())->buildPatch($cluster);
        // 13 was always the truth even for a single-liner (the old literal
        // said 12); multi-line signatures now grow the count too.
        $this->assertHunkCountMatchesPayload($patch, 13);
    }

    public function testHunkHeaderCountMatchesEmittedPayloadMultiLineSignature(): void
    {
        $cluster = $this->cluster();
        $cluster->signature = "function f(\n    int \$x,\n    string \$y\n): mixed";
        $patch = (new RefactorPatchReporter())->buildPatch($cluster);
        $this->assertHunkCountMatchesPayload($patch, 16);
        // Byte-faithful: every signature line rides the hunk prefixed, in order.
        foreach (['+function f(', '+    int $x,', '+    string $y', '+): mixed'] as $line) {
            $this->assertStringContainsString($line . "\n", $patch, "hunk must carry '{$line}' verbatim");
        }
    }

    public function testPerClusterPatchFileIsAcceptedByGnuPatchDryRun(): void
    {
        $probe = [];
        $probeRc = 1;
        exec('command -v patch 2>/dev/null', $probe, $probeRc);
        if ($probeRc !== 0 || $probe === []) {
            $this->markTestSkipped('GNU patch unavailable on this box');
        }

        $cluster = $this->cluster();
        $cluster->signature = "function f(\n    int \$x\n): mixed";
        $report = new Report(
            files: 2, blocks: 2, parseErrors: 0, clusters: [$cluster], config: Config::defaults([]),
        );
        $dir = sys_get_temp_dir() . '/phpdup-patch-accept-' . uniqid('', true);
        try {
            (new RefactorPatchReporter())->writeTo($report, $dir);
            $patches = glob($dir . '/*.patch') ?: [];
            $this->assertCount(1, $patches, 'single-cluster report yields a single-member patch');
            $out = [];
            $rc = 0;
            exec(
                'patch -p1 --dry-run -d ' . escapeshellarg($dir) . ' -i ' . escapeshellarg($patches[0]) . ' 2>&1 </dev/null',
                $out,
                $rc,
            );
            $this->assertSame(0, $rc, 'GNU patch must accept the emitted diff: ' . implode("\n", $out));
            $this->assertDirectoryDoesNotExist($dir . '/Refactored', '--dry-run writes nothing');
        } finally {
            $this->rrmdir($dir);
        }
    }

    public function testApplyDryRunSurfaceKeepsTheDiffNameAndWritesNoSummary(): void
    {
        $dir = sys_get_temp_dir() . '/phpdup-patch-dryname-' . uniqid('', true);
        $cluster = $this->cluster();
        $cluster->signature = 'function f(int $x): mixed';
        $report = new Report(
            files: 2, blocks: 2, parseErrors: 0, clusters: [$cluster], config: Config::defaults([]),
        );
        try {
            (new RefactorPatchReporter())->writeTo($report, $dir, apply: true, dryRun: true);
            $this->assertFileExists($dir . '/' . RefactorPatchReporter::APPLY_DIFF_FILE);
            $this->assertStringContainsString(
                '@@ -0,0 +1,13 @@',
                (string) file_get_contents($dir . '/' . RefactorPatchReporter::APPLY_DIFF_FILE),
                'the dry-run artifact is the scaffold diff, hunk header computed',
            );
            $this->assertFileDoesNotExist($dir . '/' . RefactorPatchReporter::APPLY_SUMMARY_FILE);
        } finally {
            $this->rrmdir($dir);
        }
    }

    public function testWritesRouteThroughSharedTraitWithoutRawWritePrimitives(): void
    {
        // Acceptance grep pinned as a test: the class composes the
        // sanctioned WritesReportFile trait and contains neither raw
        // write primitive (mkdir nor file_put_contents) itself.
        $this->assertContains(WritesReportFile::class, class_uses(RefactorPatchReporter::class));
        $source = file_get_contents(dirname(__DIR__, 3) . '/src/Reporting/RefactorPatchReporter.php');
        $this->assertIsString($source);
        $this->assertStringNotContainsString('file_put_contents', $source);
        $this->assertStringNotContainsString('@mkdir', $source);
    }

    /**
     * Parse the emitted `@@ -0,0 +1,N @@` header and count the actual
     * hunk `+` rows beneath it; the two must agree (output-022).
     */
    private function assertHunkCountMatchesPayload(string $patch, int $expected): void
    {
        $this->assertSame(1, preg_match('/^@@ -0,0 \+1,(\d+) @@$/m', $patch, $m));
        $claimed = (int) $m[1];
        $lines = explode("\n", $patch);
        $headerIdx = array_search($m[0], $lines, true);
        $this->assertIsInt($headerIdx, 'the matched header must be a real line');
        $actual = 0;
        for ($i = $headerIdx + 1; $i < count($lines) && str_starts_with($lines[$i], '+'); $i++) {
            $actual++;
        }
        $this->assertSame($expected, $claimed);
        $this->assertSame($claimed, $actual, 'header count must equal the emitted added lines');
    }

    /** @param list<Block> $members */
    private function reportWith(array $members, string $id): Report
    {
        $cluster = new Cluster($id, $members, 1.0, false);
        return new Report(
            files: 2, blocks: 2, parseErrors: 0,
            clusters: [$cluster], config: Config::defaults([dirname($members[0]->file)]),
        );
    }

    private function realBlk(string $file): Block
    {
        $stmts = (new AstParser())->parseCode((string)file_get_contents($file));
        return new Block(
            file: $file, range: new LineRange(2, 2), kind: 'function',
            namespace: null, class: null, name: null, ast: $stmts[0],
        );
    }

    private function namedCluster(string $id): Cluster
    {
        $blocks = [$this->blk('t1.php'), $this->blk('t2.php')];
        return new Cluster($id, $blocks, 1.0, false);
    }

    private function cluster(): Cluster
    {
        $blocks = [$this->blk('test1.php'), $this->blk('test2.php')];
        return new Cluster('TEST', $blocks, 1.0, false);
    }

    private function blk(string $file): Block
    {
        $stmts = (new \Phpdup\Parsing\AstParser())->parseCode('<?php $x;');
        return new Block(
            file: $file,
            range: new LineRange(1, 1),
            kind: 'method',
            namespace: null, class: null, name: null,
            ast: $stmts[0],
        );
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $p = $dir . '/' . $f;
            if (is_link($p)) {
                @unlink($p);
            } elseif (is_dir($p)) {
                $this->rrmdir($p);
            } else {
                @unlink($p);
            }
        }
        @rmdir($dir);
    }

    // ── output-004 (half B): honest F1b tallies — counted == verified ───────

    /** Extract one named function block bound to a real file path. */
    private function s44bBlock(string $file, string $name): Block
    {
        $stmts = (new AstParser())->parseCode((string) file_get_contents($file));
        $this->assertNotNull($stmts);
        foreach ((new \Phpdup\Extraction\BlockExtractor(minSize: 1))->extract($file, $stmts) as $block) {
            if ($block->kind === 'function' && $block->name === $name) {
                return $block;
            }
        }
        $this->fail("fixture: no function {$name} in {$file}");
    }

    /**
     * Cluster primed the way the apply chain primes its real ones:
     * generalizedAst, signature and non-empty hole paths (the extractor's
     * write window opens; hole-less variants blank the paths to take the
     * early-return arm).
     *
     * @param list<Block> $members
     */
    private function s44bCluster(string $id, array $members, bool $withHoles): Cluster
    {
        $cluster = new Cluster($id, $members, 1.0, false);
        $cluster->generalizedAst = $members[0]->ast;
        $cluster->signature = 'function tallyThing($u, $s): mixed';
        $cluster->holePaths = $withHoles ? ['__P0' => ['stmts', 0]] : [];
        return $cluster;
    }

    /** @param list<Cluster> $clusters */
    private function s44bReport(string $root, array $clusters): Report
    {
        return new Report(
            files: 4, blocks: 4, parseErrors: 0,
            clusters: $clusters, config: Config::defaults([$root]),
        );
    }

    public function testF1bSummaryTalliesOnlyVerifiedMemberRewrites(): void
    {
        // Happy path: apply() returned two member files it rewrote AND
        // read back byte-for-byte — the summary states both numbers.
        $dir = sys_get_temp_dir() . '/phpdup-patch-s44b-' . uniqid('', true);
        mkdir($dir, 0o775, true);
        $a = $dir . '/alpha.php';
        $b = $dir . '/beta.php';
        file_put_contents($a, "<?php\nfunction tallyAlpha(\$u, \$s) { return \$s + 1; }\n");
        file_put_contents($b, "<?php\nfunction tallyBeta(\$u, \$s) { return \$s + 2; }\n");
        $out = $dir . '/apply';
        try {
            $members = [$this->s44bBlock($a, 'tallyAlpha'), $this->s44bBlock($b, 'tallyBeta')];
            $report = $this->s44bReport($dir, [$this->s44bCluster('TALLY', $members, true)]);
            (new RefactorPatchReporter(new SourceTextStore([$dir])))->writeTo($report, $out, apply: true, dryRun: false);

            $summary = (string) file_get_contents($out . '/' . RefactorPatchReporter::APPLY_SUMMARY_FILE);
            $this->assertStringContainsString("# Applied: 1 clusters\n", $summary);
            $this->assertStringContainsString(
                "# Verified member rewrites: 2 files (each read back byte-for-byte after the transaction)\n",
                $summary,
                'the member-file line rides apply()s verified list',
            );
            $this->assertStringContainsString('# Files: TALLY.php', $summary);
            $this->assertStringNotContainsString('Skipped:', $summary);
            $this->assertFileExists($out . '/Refactored/TALLY.php');
            $this->assertStringContainsString('Refactored\\tallyThing(', (string) file_get_contents($a));
        } finally {
            $this->rrmdir($dir);
        }
    }

    public function testClusterThatLandsNothingRidesSkippedNotApplied(): void
    {
        // The remaining tally dishonesty half B closes: a hole-less
        // cluster returns from apply() BEFORE the write window opens —
        // zero files change. Counting it Applied (the old behaviour)
        // claimed a refactor the tree never received.
        $dir = sys_get_temp_dir() . '/phpdup-patch-s44b-hole-' . uniqid('', true);
        mkdir($dir, 0o775, true);
        $a = $dir . '/alpha.php';
        $b = $dir . '/beta.php';
        file_put_contents($a, "<?php\nfunction tallyAlpha(\$u, \$s) { return \$s + 1; }\n");
        file_put_contents($b, "<?php\nfunction tallyBeta(\$u, \$s) { return \$s + 2; }\n");
        $srcA = (string) file_get_contents($a);
        $out = $dir . '/apply';
        try {
            $members = [$this->s44bBlock($a, 'tallyAlpha'), $this->s44bBlock($b, 'tallyBeta')];
            $report = $this->s44bReport($dir, [$this->s44bCluster('NOHOLE', $members, false)]);
            (new RefactorPatchReporter(new SourceTextStore([$dir])))->writeTo($report, $out, apply: true, dryRun: false);

            $summary = (string) file_get_contents($out . '/' . RefactorPatchReporter::APPLY_SUMMARY_FILE);
            $this->assertStringContainsString("# Applied: 0 clusters\n", $summary);
            $this->assertStringContainsString("# Verified member rewrites: 0 files", $summary);
            $this->assertStringContainsString("# cluster NOHOLE: nothing applied (no verified member rewrite)", $summary);
            $this->assertStringNotContainsString('# Files:', $summary, 'no Files row for a cluster that landed nothing');
            $this->assertDirectoryDoesNotExist($out . '/Refactored', 'the write window never opened');
            $this->assertSame($srcA, (string) file_get_contents($a));
        } finally {
            $this->rrmdir($dir);
        }
    }

    public function testMidWindowRollbackKeepsTheTallyOnTheCleanClusterOnly(): void
    {
        // One clean cluster + one whose member write refuses mid-window
        // (rollback fires, artifacts vanish): the Applied tally grows
        // ONLY on the clean verified list, the rolled-back cluster rides
        // the error rows carrying its refusal + rollback outcome.
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root bypasses DAC: a chmod-0444 file stays writable');
        }
        $dir = sys_get_temp_dir() . '/phpdup-patch-s44b-mix-' . uniqid('', true);
        mkdir($dir, 0o775, true);
        $a = $dir . '/alpha.php';
        $b = $dir . '/beta.php';
        $y = $dir . '/broken-ok.php';
        $x = $dir . '/broken-blocked.php';
        file_put_contents($a, "<?php\nfunction tallyAlpha(\$u, \$s) { return \$s + 1; }\n");
        file_put_contents($b, "<?php\nfunction tallyBeta(\$u, \$s) { return \$s + 2; }\n");
        file_put_contents($y, "<?php\nfunction tallyBrokenOk(\$u, \$s) { return \$s + 3; }\n");
        file_put_contents($x, "<?php\nfunction tallyBrokenBlocked(\$u, \$s) { return \$s + 4; }\n");
        $srcY = (string) file_get_contents($y);
        $srcX = (string) file_get_contents($x);

        $good = $this->s44bCluster('GOOD', [$this->s44bBlock($a, 'tallyAlpha'), $this->s44bBlock($b, 'tallyBeta')], true);
        // Member ORDER puts the writable file first in the window: its
        // rewrite really lands before the blocked second write refuses —
        // the torn state the rollback must erase.
        $broken = $this->s44bCluster('BROKEN', [$this->s44bBlock($y, 'tallyBrokenOk'), $this->s44bBlock($x, 'tallyBrokenBlocked')], true);
        chmod($x, 0o444);
        $out = $dir . '/apply';
        try {
            $report = $this->s44bReport($dir, [$good, $broken]);
            (new RefactorPatchReporter(new SourceTextStore([$dir])))->writeTo($report, $out, apply: true, dryRun: false);

            $summary = (string) file_get_contents($out . '/' . RefactorPatchReporter::APPLY_SUMMARY_FILE);
            $this->assertStringContainsString("# Applied: 1 clusters\n", $summary);
            $this->assertStringContainsString('# Verified member rewrites: 2 files', $summary);
            $this->assertStringContainsString('# cluster BROKEN: error - ApplyExtractor: refusing cluster BROKEN', $summary);
            $this->assertStringContainsString('writing ' . $x . ' failed', $summary,
                'the refused write rides the error row');
            $this->assertStringContainsString('write window rolled back', $summary,
                'and so does the rollback outcome');
            $this->assertStringNotContainsString('BROKEN.php', explode('# Skipped', $summary)[0],
                'the Files row lists only the clean cluster');
            $this->assertFileDoesNotExist($out . '/Refactored/BROKEN.php',
                'the broken window un-landed its artifact');
            $this->assertSame($srcY, (string) file_get_contents($y),
                'the member rewrite that HAD landed is restored byte-for-byte');
            $this->assertSame($srcX, (string) file_get_contents($x));
            $this->assertStringContainsString('Refactored\\tallyThing(', (string) file_get_contents($a),
                'the clean cluster keeps its verified rewrites');
        } finally {
            chmod($x, 0o644);
            $this->rrmdir($dir);
        }
    }
}
