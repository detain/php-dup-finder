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
}
