<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Reporting;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Parsing\AstParser;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\Stages\ClusterStage;
use Phpdup\Pipeline\Stages\PreprocessStage;
use Phpdup\Pipeline\Stages\RefactorStage;
use Phpdup\Pipeline\Stages\ScanningStage;
use Phpdup\Reporting\DiffReporter;
use Phpdup\Reporting\Ranker;
use Phpdup\Reporting\Report;
use Phpdup\Util\LineRange;
use Symfony\Component\Console\Output\NullOutput;

final class DiffReporterTest extends TestCase
{
    public function testWriteDirCreatesOneFilePerCluster(): void
    {
        $report = $this->buildReport();
        $dir    = sys_get_temp_dir() . '/phpdup-diff-' . uniqid();
        try {
            (new DiffReporter())->writeDir($report, $dir);

            $this->assertDirectoryExists($dir);
            $expected = 0;
            foreach ($report->clusters as $c) {
                if (count($c->members) >= 2) $expected++;
            }
            $this->assertGreaterThan(0, $expected, 'sql fixture should have multi-member clusters');

            $files = glob($dir . '/*.diff');
            $this->assertCount($expected, $files);

            foreach ($files as $f) {
                $body = (string)file_get_contents($f);
                $this->assertStringContainsString('# phpdup cluster', $body);
                $this->assertStringContainsString('# Suggested abstraction:', $body);
            }
        } finally {
            $this->rrmdir($dir);
        }
    }

    public function testWritePatchProducesSingleFile(): void
    {
        $report = $this->buildReport();
        $tmp    = sys_get_temp_dir() . '/phpdup-patch-' . uniqid() . '.patch';
        try {
            (new DiffReporter())->writePatch($report, $tmp);
            $this->assertFileExists($tmp);
            $body = (string)file_get_contents($tmp);
            $this->assertStringContainsString('# phpdup cluster', $body);
        } finally {
            @unlink($tmp);
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
        $body = '';
        foreach ($this->buildReport()->clusters as $c) {
            if (count($c->members) >= 2) {
                $body .= $this->renderCluster($c);
            }
        }
        $this->assertNotSame('', $body, 'sql fixture must yield at least one diff');

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
            // third member has no source on disk so its diff payload is the
            // structural "<source unavailable>" line — raw on purpose.
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
            $this->assertStringContainsString('<source unavailable: =evil.php>', $body);
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

    private function buildReport(): Report
    {
        $config = new Config(
            paths: [__DIR__ . '/../../Fixtures/sql'],
            exclude: Config::defaults([])->exclude,
            lazyAst: false,
        );
        $state = new PipelineState($config);
        $out   = new NullOutput();
        (new ScanningStage())->run($state, $out);
        (new PreprocessStage(useCache: false))->run($state, $out);
        (new ClusterStage(exactOnly: true))->run($state, $out);
        (new RefactorStage(useCache: false))->run($state, $out);

        return new Report(
            files: count($state->files),
            blocks: count($state->blocks),
            parseErrors: $state->parseErrors,
            clusters: (new Ranker($config->minClusterImpact))->rank($state->clusters),
            config: $config,
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
