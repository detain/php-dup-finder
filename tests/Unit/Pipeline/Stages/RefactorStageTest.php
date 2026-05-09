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
use Phpdup\Pipeline\Stages\ScanningStage;
use Symfony\Component\Console\Output\NullOutput;

final class RefactorStageTest extends TestCase
{
    public function testEmptyClustersIsNoOp(): void
    {
        $state = new PipelineState(Config::defaults([__DIR__]));

        (new RefactorStage(useCache: false))->run($state, new NullOutput());

        $this->assertSame([], $state->clusters);
        $this->assertSame(0.0, $state->timings['refactor']);
    }

    public function testEnrichesClustersWithSignatureAndPatterns(): void
    {
        $config = new Config(
            paths: [__DIR__ . '/../../../Fixtures/sql'],
            exclude: Config::defaults([])->exclude,
            lazyAst: false,
        );
        $state = new PipelineState($config);

        (new ScanningStage())->run($state, new NullOutput());
        (new PreprocessStage(useCache: false))->run($state, new NullOutput());
        (new ClusterStage(exactOnly: true))->run($state, new NullOutput());
        (new RefactorStage(useCache: false))->run($state, new NullOutput());

        $this->assertNotEmpty($state->clusters);
        $this->assertGreaterThan(0.0, $state->timings['refactor']);

        $cluster = $state->clusters[0];
        $this->assertNotNull($cluster->signature, 'SignatureBuilder should populate signature');
    }

    public function testReportsName(): void
    {
        $this->assertSame(
            Stage::Refactoring,
            (new RefactorStage(useCache: false))->name(),
        );
    }
}
