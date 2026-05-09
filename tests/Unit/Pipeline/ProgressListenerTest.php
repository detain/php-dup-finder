<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Pipeline;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Pipeline\Pipeline;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\ProgressListener;
use Phpdup\Pipeline\Stage;
use Phpdup\Pipeline\Stages\PreprocessStage;
use Phpdup\Pipeline\Stages\ScanningStage;
use Symfony\Component\Console\Output\NullOutput;

final class ProgressListenerTest extends TestCase
{
    public function testPipelineNotifiesListenerOfEachStageBoundary(): void
    {
        $listener = new RecordingListener();
        $stages = [new ScanningStage($listener)];
        $pipeline = new Pipeline($stages, listener: $listener);

        $pipeline->run(
            new PipelineState(Config::defaults([__DIR__])),
            new NullOutput(),
        );

        $this->assertContains('start:scanning', $listener->events);
        $this->assertContains('end:scanning', $listener->events);
    }

    public function testScanningStageEmitsPerFileTicks(): void
    {
        $listener = new RecordingListener();
        $state    = new PipelineState(Config::defaults([__DIR__ . '/../../Fixtures/sql']));

        (new ScanningStage($listener))->run($state, new NullOutput());

        $scanEvents = array_filter($listener->events, fn(string $e) => str_starts_with($e, 'scan:'));
        $this->assertNotEmpty($scanEvents);
    }

    public function testPreprocessStageEmitsTicks(): void
    {
        $listener = new RecordingListener();
        $state    = new PipelineState(Config::defaults([__DIR__ . '/../../Fixtures/sql']));
        (new ScanningStage())->run($state, new NullOutput());

        (new PreprocessStage(useCache: false, listener: $listener))
            ->run($state, new NullOutput());

        $preprocessEvents = array_filter($listener->events, fn(string $e) => str_starts_with($e, 'preprocess:'));
        $this->assertNotEmpty($preprocessEvents);
    }

    public function testNoListenerStillWorks(): void
    {
        $state = new PipelineState(Config::defaults([__DIR__ . '/../../Fixtures/sql']));
        (new ScanningStage())->run($state, new NullOutput());
        (new PreprocessStage(useCache: false))->run($state, new NullOutput());
        $this->assertNotEmpty($state->blocks);
    }
}

final class RecordingListener implements ProgressListener
{
    /** @var list<string> */
    public array $events = [];

    public function onStageStart(Stage $stage): void
    {
        $this->events[] = 'start:' . $stage->value;
    }

    public function onStageEnd(Stage $stage): void
    {
        $this->events[] = 'end:' . $stage->value;
    }

    public function onFileScanned(int $scanned, int $total): void
    {
        $this->events[] = "scan:{$scanned}/{$total}";
    }

    public function onFilePreprocessed(int $processed, int $reused, int $errors): void
    {
        $this->events[] = "preprocess:{$processed}|{$reused}|{$errors}";
    }

    public function onPairScored(int $scored, int $total): void
    {
        $this->events[] = "pairs:{$scored}/{$total}";
    }

    public function onClusterRefactored(int $refactored, int $total): void
    {
        $this->events[] = "refactor:{$refactored}/{$total}";
    }
}
