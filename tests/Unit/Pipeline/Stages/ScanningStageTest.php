<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Pipeline\Stages;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\Stage;
use Phpdup\Pipeline\Stages\ScanningStage;
use Symfony\Component\Console\Output\NullOutput;

final class ScanningStageTest extends TestCase
{
    public function testPopulatesFilesAndCounters(): void
    {
        $config = Config::defaults([__DIR__ . '/../../../Fixtures/sql']);
        $state  = new PipelineState($config);

        (new ScanningStage())->run($state, new NullOutput());

        $this->assertNotEmpty($state->files);
        $this->assertSame(count($state->files), $state->totalFiles);
        $this->assertSame(count($state->files), $state->scannedFiles);
        foreach ($state->files as $f) {
            $this->assertStringEndsWith('.php', $f);
        }
    }

    public function testFilesAreSorted(): void
    {
        $config = Config::defaults([__DIR__ . '/../../../Fixtures']);
        $state  = new PipelineState($config);

        (new ScanningStage())->run($state, new NullOutput());

        $sorted = $state->files;
        sort($sorted);
        $this->assertSame($sorted, $state->files);
    }

    public function testReportsName(): void
    {
        $this->assertSame(Stage::Scanning, (new ScanningStage())->name());
    }
}
