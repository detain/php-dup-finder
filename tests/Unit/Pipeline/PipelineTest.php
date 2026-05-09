<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Pipeline;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Pipeline\Pipeline;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\Stage;
use Phpdup\Pipeline\StageInterface;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;

final class PipelineTest extends TestCase
{
    public function testRunsStagesInOrderAndAdvancesState(): void
    {
        $observed = [];
        $stages = [
            $this->recordingStage(Stage::Scanning, $observed),
            $this->recordingStage(Stage::Preprocessing, $observed),
            $this->recordingStage(Stage::Clustering, $observed),
        ];
        $state = new PipelineState(Config::defaults([__DIR__]));

        (new Pipeline($stages))->run($state, new NullOutput());

        $this->assertSame(
            ['scanning', 'preprocessing', 'clustering'],
            array_map(fn(Stage $s) => $s->value, $observed),
        );
        $this->assertSame(Stage::Clustering, $state->stage);
        $this->assertSame(1.0, $state->stageProgress);
    }

    public function testStopAfterHaltsAtBoundary(): void
    {
        $observed = [];
        $stages = [
            $this->recordingStage(Stage::Scanning, $observed),
            $this->recordingStage(Stage::Preprocessing, $observed),
            $this->recordingStage(Stage::Clustering, $observed),
        ];
        $state = new PipelineState(Config::defaults([__DIR__]));

        (new Pipeline($stages, stopAfter: Stage::Preprocessing))->run($state, new NullOutput());

        $this->assertSame(
            ['scanning', 'preprocessing'],
            array_map(fn(Stage $s) => $s->value, $observed),
            'Clustering must not run when stopAfter=preprocessing',
        );
        $this->assertSame(Stage::Preprocessing, $state->stage);
    }

    public function testStageProgressIsResetBetweenStages(): void
    {
        $progressSeenAtStart = [];
        $stages = [
            new class($progressSeenAtStart, Stage::Scanning) implements StageInterface {
                public function __construct(private array &$record, private Stage $name) {}
                public function name(): Stage { return $this->name; }
                public function run(PipelineState $state, OutputInterface $output): void
                {
                    $this->record[$this->name->value] = $state->stageProgress;
                    $state->stageProgress = 0.42; // simulate mid-stage progress
                }
            },
            new class($progressSeenAtStart, Stage::Preprocessing) implements StageInterface {
                public function __construct(private array &$record, private Stage $name) {}
                public function name(): Stage { return $this->name; }
                public function run(PipelineState $state, OutputInterface $output): void
                {
                    $this->record[$this->name->value] = $state->stageProgress;
                }
            },
        ];
        $state = new PipelineState(Config::defaults([__DIR__]));

        (new Pipeline($stages))->run($state, new NullOutput());

        $this->assertSame(0.0, $progressSeenAtStart['scanning']);
        $this->assertSame(0.0, $progressSeenAtStart['preprocessing']);
    }

    public function testIterYieldsTwiceForSynchronousStage(): void
    {
        $observed = [];
        $stages = [$this->recordingStage(Stage::Scanning, $observed)];
        $state  = new PipelineState(Config::defaults([__DIR__]));

        $iter = (new Pipeline($stages))->iter($state, new NullOutput());
        $yields = iterator_to_array($iter, false);

        // Pre-stage frame + post-stage frame = 2 yields per synchronous stage.
        $this->assertCount(2, $yields);
        $this->assertSame(Stage::Scanning, $yields[0]);
        $this->assertSame(Stage::Scanning, $yields[1]);
    }

    public function testIterDriveSynchronousStagesInOrder(): void
    {
        $observed = [];
        $stages = [
            $this->recordingStage(Stage::Scanning, $observed),
            $this->recordingStage(Stage::Preprocessing, $observed),
            $this->recordingStage(Stage::Clustering, $observed),
        ];
        $state = new PipelineState(Config::defaults([__DIR__]));

        $yields = iterator_to_array(
            (new Pipeline($stages))->iter($state, new NullOutput()),
            false,
        );

        $this->assertSame(
            [Stage::Scanning, Stage::Scanning, Stage::Preprocessing, Stage::Preprocessing, Stage::Clustering, Stage::Clustering],
            $yields,
        );
    }

    public function testIterYieldsMidStageForCooperativeStages(): void
    {
        $stage = new class implements \Phpdup\Pipeline\CooperativeStageInterface {
            public function name(): Stage { return Stage::Scanning; }
            public function run(PipelineState $s, OutputInterface $o): void { foreach ($this->iter($s, $o) as $_) {} }
            public function iter(PipelineState $state, OutputInterface $output): \Generator
            {
                // Three mid-stage yields.
                yield Stage::Scanning;
                yield Stage::Scanning;
                yield Stage::Scanning;
            }
        };

        $state = new PipelineState(Config::defaults([__DIR__]));
        $yields = iterator_to_array(
            (new Pipeline([$stage]))->iter($state, new NullOutput()),
            false,
        );

        // Pre + 3 mid + post = 5
        $this->assertCount(5, $yields);
    }

    public function testRunDrainsIterAndReturnsState(): void
    {
        $observed = [];
        $stages = [$this->recordingStage(Stage::Scanning, $observed)];
        $state  = new PipelineState(Config::defaults([__DIR__]));

        $returned = (new Pipeline($stages))->run($state, new NullOutput());

        $this->assertSame($state, $returned);
        $this->assertSame(Stage::Scanning, $state->stage);
    }

    /** @param list<Stage> $observed */
    private function recordingStage(Stage $name, array &$observed): StageInterface
    {
        return new class($name, $observed) implements StageInterface {
            public function __construct(private Stage $stage, private array &$observed) {}
            public function name(): Stage { return $this->stage; }
            public function run(PipelineState $state, OutputInterface $output): void
            {
                $this->observed[] = $state->stage;
            }
        };
    }
}
