<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Reporting;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Refactor\Hole;
use Phpdup\Reporting\RefactorPatchReporter;
use Phpdup\Reporting\RefactorTestReporter;
use Phpdup\Reporting\Report;
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
        $dir = sys_get_temp_dir() . '/phpdup-patch-apply-defuse-' . uniqid('', true);
        $cluster = new Cluster('=SUM(A1)', [$this->blk('=evil.php'), $this->blk('clean.php')], 1.0, false);
        $report = new Report(
            files: 2, blocks: 2, parseErrors: 0, clusters: [$cluster], config: Config::defaults([]),
        );
        try {
            (new RefactorPatchReporter())->writeTo($report, $dir, apply: true, dryRun: false);
            $summary = (string)file_get_contents($dir . DIRECTORY_SEPARATOR . 'apply.diff');
            $this->assertStringContainsString("# cluster '=SUM(A1): no signature", $summary);
        } finally {
            @unlink($dir . DIRECTORY_SEPARATOR . 'apply.diff');
            @rmdir($dir);
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
}
