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
