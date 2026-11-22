<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Pipeline\Stages;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\Stage;
use Phpdup\Pipeline\Stages\ClusterStage;
use Phpdup\Pipeline\Stages\PreprocessStage;
use Phpdup\Pipeline\Stages\RefactorStage;
use Phpdup\Pipeline\Stages\ReportStage;
use Phpdup\Pipeline\Stages\ScanningStage;
use Phpdup\Reporting\JsonReporter;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\NullOutput;

final class ReportStageTest extends TestCase
{
    public function testEmptyBlocksProducesNoReport(): void
    {
        $state = new PipelineState(Config::defaults([__DIR__]));

        (new ReportStage(limit: 50, showStats: false))->run($state, new NullOutput());

        $this->assertNull($state->report);
    }

    /**
     * Item 4.3 wiring: ReportStage runs the DeclaredTwinAnalyzer over
     * the block index, lands the claim-vs-reality table on the Report
     * (JSON top-level `declared_twins`), and stamps drifted claimant
     * clusters with the warning Finding.
     */
    public function testReportStageRunsDeclaredTwinPassAndReportsDrift(): void
    {
        $tmp = sys_get_temp_dir() . '/phpdup-stage-twin-' . uniqid();
        mkdir($tmp . '/cache', 0o775, true);
        try {
            file_put_contents($tmp . '/a.php', "<?php\nfunction shared_a(): int\n{\n    \$x = 1;\n    \$y = 2;\n    \$z = 3;\n    return \$x + \$y + \$z;\n}\n");
            file_put_contents($tmp . '/b.php', "<?php\n/**\n * Mirror of a.php - keep in sync.\n */\nfunction drifted_b(): string\n{\n    \$a = 'completely';\n    \$b = 'different';\n    \$c = 'body';\n    return \$a . \$b . \$c . strrev('no-overlap');\n}\n");

            $config = new Config(
                paths: [$tmp],
                exclude: [],
                minBlockSize: 1,
                cacheDir: $tmp . '/cache',
            );
            $state = new PipelineState($config);
            $out   = new NullOutput();
            (new ScanningStage())->run($state, $out);
            (new PreprocessStage(useCache: false))->run($state, $out);
            (new ClusterStage(exactOnly: false, useClusterCache: false))->run($state, $out);
            $this->assertNotNull($state->index, 'ClusterStage leaves the index the twin pass measures');

            (new ReportStage(limit: 50, showStats: false))->run($state, $out);

            $this->assertNotNull($state->report);
            $rows = $state->report->declaredTwins;
            $this->assertNotEmpty($rows, 'the Mirror-of claim must reach the report through the stage');
            $verdicts = array_column($rows, 'verdict');
            $this->assertContains('drifted', $verdicts,
                "b.php's body no longer resembles a.php — the FALSE claim must be flagged");

            $decoded = (new JsonReporter())->build($state->report);
            $this->assertSame($rows, $decoded['declared_twins'],
                'the JSON top-level declared_twins key rides the stage-built report');
        } finally {
            @unlink($tmp . '/a.php');
            @unlink($tmp . '/b.php');
            foreach (glob($tmp . '/cache/*') ?: [] as $c) {
                @unlink($c);
            }
            @rmdir($tmp . '/cache');
            @rmdir($tmp);
        }
    }

    public function testFullPipelineProducesReportAndCliOutput(): void
    {
        $jsonFile = sys_get_temp_dir() . '/phpdup-' . uniqid() . '.json';
        try {
            $config = new Config(
                paths: [__DIR__ . '/../../../Fixtures/sql'],
                exclude: Config::defaults([])->exclude,
                jsonReportFile: $jsonFile,
                lazyAst: false,
            );
            $state  = new PipelineState($config);
            $output = new BufferedOutput();

            (new ScanningStage())->run($state, $output);
            (new PreprocessStage(useCache: false))->run($state, $output);
            (new ClusterStage(exactOnly: true))->run($state, $output);
            (new RefactorStage(useCache: false))->run($state, $output);
            (new ReportStage(limit: 50, showStats: false))->run($state, $output);

            $this->assertNotNull($state->report, 'ReportStage should populate $state->report');
            $this->assertFileExists($jsonFile);
            $decoded = json_decode((string)file_get_contents($jsonFile), true);
            $this->assertIsArray($decoded);
            $this->assertArrayHasKey('clusters', $decoded);

            $rendered = $output->fetch();
            $this->assertStringContainsString('phpdup', $rendered);
        } finally {
            @unlink($jsonFile);
        }
    }

    public function testReportsName(): void
    {
        $this->assertSame(
            Stage::Reporting,
            (new ReportStage(limit: 50, showStats: false))->name(),
        );
    }

    /**
     * shell-S40 (php-dup-finder-shell-051): a zero-cluster run over a cache
     * dir that does not exist yet records nothing and is unchanged, so
     * writeClusterIdCache must neither create the dir nor write an empty
     * clusters.list. Hand-driven single-stage state (files, no blocks, no
     * clusters) isolates the report-stage leg: PreprocessStage mints
     * parser caches in the same dir regardless, which is outside this step.
     */
    public function testZeroClusterRunCreatesNoCacheDirAndNoClusterList(): void
    {
        $cacheDir = sys_get_temp_dir() . '/phpdup_s40_zero_' . uniqid() . '/cache';
        try {
            $state = $this->zeroClusterState($cacheDir);

            (new ReportStage(limit: 50, showStats: false))->run($state, new NullOutput());

            $this->assertNotNull($state->report, 'the zero-cluster run still builds a report');
            $this->assertSame([], $state->report->clusters);
            $this->assertDirectoryDoesNotExist($cacheDir, 'a zero-cluster run must not mint the cache dir');
            $this->assertFileDoesNotExist($cacheDir . '/clusters.list');
        } finally {
            foreach (glob($cacheDir . '/*') ?: [] as $c) {
                @unlink($c);
            }
            @rmdir($cacheDir);
            @rmdir(dirname($cacheDir));
        }
    }

    /**
     * shell-S40: "stale ids age out automatically" survives the content-skip —
     * a zero-cluster run over an existing NON-empty list is a content change
     * and must land as a truncate (not be silently skipped).
     */
    public function testZeroClusterRunAgesOutStaleClusterList(): void
    {
        $base     = sys_get_temp_dir() . '/phpdup_s40_stale_' . uniqid();
        $cacheDir = $base . '/cache';
        mkdir($cacheDir, 0o775, true);
        try {
            file_put_contents($cacheDir . '/clusters.list', "STALE-OLD-ID\n");
            $state = $this->zeroClusterState($cacheDir);

            (new ReportStage(limit: 50, showStats: false))->run($state, new NullOutput());

            $this->assertNotNull($state->report);
            $this->assertSame([], $state->report->clusters);
            $this->assertFileExists($cacheDir . '/clusters.list', 'the stale list is refreshed, not left dangling');
            $this->assertSame('', (string) file_get_contents($cacheDir . '/clusters.list'), 'aged out to empty');
        } finally {
            foreach (glob($cacheDir . '/*') ?: [] as $c) {
                @unlink($c);
            }
            @rmdir($cacheDir);
            @rmdir($base);
        }
    }

    /**
     * Files present, nothing comparable: the report path runs to the end
     * with zero clusters (the 0.6 "all skipped still reports" posture),
     * so the cluster-id cache leg is exercised without any earlier stage
     * touching the cache dir.
     */
    private function zeroClusterState(string $cacheDir): PipelineState
    {
        $config = new Config(
            paths:    [sys_get_temp_dir()],
            exclude:  [],
            cacheDir: $cacheDir,
        );
        $state = new PipelineState($config);
        $state->files = [sys_get_temp_dir() . '/phpdup-s40-noop.php'];
        return $state;
    }

    /**
     * shell-S40: an identical id list must not be rewritten. The list file
     * gets a future mtime after the first run; an unchanged second run that
     * still called file_put_contents would stamp mtime back to "now" — the
     * surviving future stamp is the content-skip proof.
     */
    public function testIdenticalClusterListIsContentSkipped(): void
    {
        $cacheDir = sys_get_temp_dir() . '/phpdup_s40_list_' . uniqid();
        mkdir($cacheDir, 0o775, true);
        try {
            $state = $this->runPipelineToReport(
                __DIR__ . '/../../../Fixtures/sql',
                $cacheDir,
                Config::defaults([])->exclude,
                exactOnly: true,
            );
            $this->assertNotCount(0, $state->report->clusters, 'sql fixture yields clusters to cache');

            $listPath = $cacheDir . '/clusters.list';
            $this->assertFileExists($listPath, 'a non-zero id list is recorded');
            $first = (string) file_get_contents($listPath);
            touch($listPath, time() + 30);

            // Same state, same ranked clusters → byte-identical payload.
            (new ReportStage(limit: 50, showStats: false))->run($state, new NullOutput());

            $this->assertSame($first, (string) file_get_contents($listPath), 'content unchanged');
            $this->assertGreaterThan(
                time() + 5,
                (int) filemtime($listPath),
                'identical payload must skip the rewrite (future stamp survives)',
            );
        } finally {
            foreach (glob($cacheDir . '/*') ?: [] as $c) {
                @unlink($c);
            }
            @rmdir($cacheDir);
        }
    }

    /**
     * Drives Scanning→Preprocess→Cluster→Report over $scanDir with an explicit
     * cache dir, mirroring how the real pipeline reaches the cluster-id cache.
     */
    private function runPipelineToReport(
        string $scanDir,
        string $cacheDir,
        ?array $exclude = [],
        bool $exactOnly = false,
    ): PipelineState {
        $config = new Config(
            paths: [$scanDir],
            exclude: $exclude ?? Config::defaults([])->exclude,
            minBlockSize: 1,
            cacheDir: $cacheDir,
            lazyAst: false,
        );
        $state  = new PipelineState($config);
        $output = new NullOutput();

        (new ScanningStage())->run($state, $output);
        (new PreprocessStage(useCache: false))->run($state, $output);
        (new ClusterStage(exactOnly: $exactOnly, useClusterCache: false))->run($state, $output);
        (new RefactorStage(useCache: false))->run($state, $output);
        (new ReportStage(limit: 50, showStats: false))->run($state, $output);

        return $state;
    }

    /**
     * 0.6 masking kill: the old guard `if (!$state->blocks) return;` meant
     * an all-skipped corpus (files scanned, zero comparable blocks) never
     * constructed a Report, never rendered a verdict and never wrote the
     * --json file at all. Any scanned file must now reach the full
     * reporting surface, with the buckets labelled.
     */
    public function testAllSkippedCorpusStillProducesReportAndJson(): void
    {
        $jsonFile = sys_get_temp_dir() . '/phpdup-' . uniqid() . '.json';
        $tmpDir   = sys_get_temp_dir() . '/phpdup_skips_' . uniqid();
        mkdir($tmpDir, 0o775, true);
        try {
            file_put_contents($tmpDir . '/x.php', "<?php\n// parses, extracts nothing\n");
            file_put_contents($tmpDir . '/y.php', "<?php\n\n");
            $config = new Config(
                paths: [$tmpDir],
                exclude: [],
                minBlockSize: 1,
                jsonReportFile: $jsonFile,
                lazyAst: false,
            );
            $state  = new PipelineState($config);
            $output = new BufferedOutput();

            (new ScanningStage())->run($state, $output);
            (new PreprocessStage(useCache: false))->run($state, $output);
            (new ClusterStage(exactOnly: true))->run($state, $output);
            (new RefactorStage(useCache: false))->run($state, $output);
            (new ReportStage(limit: 50, showStats: false))->run($state, $output);

            $this->assertNotNull($state->report, 'zero blocks must no longer short-circuit reporting');
            $this->assertSame(2, $state->report->skippedFiles);
            $this->assertSame(0, $state->report->processedFiles);
            $this->assertSame(2, $state->report->files);

            $this->assertFileExists($jsonFile, '--json must be written even when nothing is comparable');
            $decoded = json_decode((string) file_get_contents($jsonFile), true);
            $this->assertIsArray($decoded);
            $this->assertSame(2, $decoded['summary']['files_without_blocks']);
            $this->assertSame(0, $decoded['summary']['files_processed']);
            $this->assertSame(0, $decoded['summary']['clusters']);

            $rendered = $output->fetch();
            $this->assertStringContainsString(
                'nothing comparable',
                $rendered,
                'a bare "clean." verdict was a lie by omission when files never entered the comparison',
            );
            $this->assertStringContainsString('files  2 scanned · 0 reused · 0 processed · 2 no blocks', $rendered);
        } finally {
            @unlink($jsonFile);
            @unlink($tmpDir . '/x.php');
            @unlink($tmpDir . '/y.php');
            @rmdir($tmpDir);
        }
    }
}
