<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Pipeline\Stages;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\Stage;
use Phpdup\Pipeline\Stages\PreprocessStage;
use Phpdup\Pipeline\Stages\ScanningStage;
use Symfony\Component\Console\Output\NullOutput;

final class PreprocessStageTest extends TestCase
{
    public function testProducesBlocksFromFixtureFiles(): void
    {
        $state = $this->scannedState(__DIR__ . '/../../../Fixtures/sql');

        (new PreprocessStage(useCache: false))->run($state, new NullOutput());

        $this->assertNotEmpty($state->blocks, 'sql fixture should produce duplicate-eligible blocks');
        foreach ($state->blocks as $b) {
            $this->assertNotSame('', $b->id, 'each block must be assigned an id');
        }
        $this->assertGreaterThan(0.0, $state->timings['preprocess']);
        $this->assertSame(0, $state->parseErrors);
    }

    public function testEmptyFileListIsNoOp(): void
    {
        $state = new PipelineState(Config::defaults(['/nonexistent/path/that/does/not/exist']));
        $state->files = [];

        (new PreprocessStage(useCache: false))->run($state, new NullOutput());

        $this->assertSame([], $state->blocks);
        $this->assertSame(0, $state->parseErrors);
        $this->assertSame(0, $state->processedFiles);
    }

    public function testReportsName(): void
    {
        $this->assertSame(
            Stage::Preprocessing,
            (new PreprocessStage(useCache: false))->name(),
        );
    }

    private function scannedState(string $path): PipelineState
    {
        $state = new PipelineState(Config::defaults([$path]));
        (new ScanningStage())->run($state, new NullOutput());
        return $state;
    }
}
