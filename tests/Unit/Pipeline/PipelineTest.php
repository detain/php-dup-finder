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
