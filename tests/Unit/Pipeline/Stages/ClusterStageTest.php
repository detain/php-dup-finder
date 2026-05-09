<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Pipeline\Stages;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\Stage;
use Phpdup\Pipeline\Stages\ClusterStage;
use Phpdup\Pipeline\Stages\PreprocessStage;
use Phpdup\Pipeline\Stages\ScanningStage;
use Symfony\Component\Console\Output\NullOutput;

final class ClusterStageTest extends TestCase
{
    public function testEmptyBlocksIsNoOp(): void
    {
        $state = new PipelineState(Config::defaults([__DIR__]));

        (new ClusterStage(exactOnly: false))->run($state, new NullOutput());

        $this->assertSame([], $state->clusters);
        $this->assertNull($state->index);
    }

    public function testProducesClustersFromFixtureBlocks(): void
    {
        $state = new PipelineState(Config::defaults([__DIR__ . '/../../../Fixtures/sql']));
        (new ScanningStage())->run($state, new NullOutput());
        (new PreprocessStage(useCache: false))->run($state, new NullOutput());

        (new ClusterStage(exactOnly: true))->run($state, new NullOutput());

        $this->assertNotNull($state->index, 'BlockIndex should be populated');
        $this->assertNotEmpty(
            $state->clusters,
            'sql fixture has identical findById methods that should cluster',
        );
        $this->assertGreaterThanOrEqual(0.0, $state->timings['cluster']);
    }

    public function testReportsName(): void
    {
        $this->assertSame(
            Stage::Clustering,
            (new ClusterStage(exactOnly: false))->name(),
        );
    }
}
