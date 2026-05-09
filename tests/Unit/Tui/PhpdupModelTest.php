<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Tui;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\Stage;
use Phpdup\Tui\PhpdupModel;
use Phpdup\Tui\TuiRunner;
use Phpdup\Tui\ViewState;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\QuitMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\View;

final class PhpdupModelTest extends TestCase
{
    public function testInitReturnsCmd(): void
    {
        $cmd = $this->buildModel()->init();
        $this->assertInstanceOf(\Closure::class, $cmd);
    }

    public function testWindowSizeMsgUpdatesViewState(): void
    {
        $model = $this->buildModel();

        [$next, $cmd] = $model->update(new WindowSizeMsg(120, 40));

        $this->assertSame($model, $next);
        $this->assertNull($cmd);
        $this->assertSame(120, $model->viewState->cols);
        $this->assertSame(40, $model->viewState->rows);
    }

    public function testQKeyReturnsQuitCmd(): void
    {
        $model = $this->buildModel();
        [$_, $cmd] = $model->update(new KeyMsg(KeyType::Char, rune: 'q'));
        $this->assertInstanceOf(\Closure::class, $cmd);
        $this->assertInstanceOf(QuitMsg::class, $cmd());
    }

    public function testCtrlCReturnsQuitCmd(): void
    {
        $model = $this->buildModel();
        [$_, $cmd] = $model->update(new KeyMsg(KeyType::Char, rune: 'c', ctrl: true));
        $this->assertInstanceOf(\Closure::class, $cmd);
        $this->assertInstanceOf(QuitMsg::class, $cmd());
    }

    public function testArrowKeysCyclePaneFocus(): void
    {
        $model = $this->buildModel();
        $this->assertSame(0, $model->viewState->focusedPaneIndex);

        $model->update(new KeyMsg(KeyType::Down));
        $this->assertSame(1, $model->viewState->focusedPaneIndex);

        $model->update(new KeyMsg(KeyType::Down));
        $this->assertSame(2, $model->viewState->focusedPaneIndex);

        $model->update(new KeyMsg(KeyType::Up));
        $this->assertSame(1, $model->viewState->focusedPaneIndex);
    }

    public function testTKeyTogglesSortMode(): void
    {
        $model = $this->buildModel();
        $this->assertSame(ViewState::SORT_IMPACT, $model->viewState->sortMode);
        $model->update(new KeyMsg(KeyType::Char, rune: 't'));
        $this->assertSame(ViewState::SORT_SIMILARITY, $model->viewState->sortMode);
    }

    public function testHKeyTogglesHelpExpanded(): void
    {
        $model = $this->buildModel();
        $this->assertFalse($model->viewState->helpExpanded);
        $model->update(new KeyMsg(KeyType::Char, rune: 'h'));
        $this->assertTrue($model->viewState->helpExpanded);
        $model->update(new KeyMsg(KeyType::Char, rune: 'h'));
        $this->assertFalse($model->viewState->helpExpanded);
    }

    public function testEscapeDismissesDetail(): void
    {
        $model = $this->buildModel();
        $model->viewState->detailClusterId = 'C123';
        $model->update(new KeyMsg(KeyType::Escape));
        $this->assertNull($model->viewState->detailClusterId);
    }

    public function testViewReturnsViewObjectWithBody(): void
    {
        $model = $this->buildModel();
        $view = $model->view();
        $this->assertInstanceOf(View::class, $view);
        $this->assertNotEmpty($view->body);
    }

    public function testViewAttachesTaskbarProgress(): void
    {
        $model = $this->buildModel();
        $view  = $model->view();
        $this->assertNotNull($view->progressBar);
        $this->assertSame(0, $view->progressBar->percent);

        $model->viewState->analysisComplete = true;
        $this->assertSame(100, $model->view()->progressBar->percent);
    }

    public function testIsAProgressListener(): void
    {
        $model = $this->buildModel();
        $this->assertInstanceOf(\Phpdup\Pipeline\ProgressListener::class, $model);

        $model->onStageStart(\Phpdup\Pipeline\Stage::Scanning);
        $this->assertSame(\Phpdup\Pipeline\Stage::Scanning, $model->state->stage);

        $model->onFileScanned(7, 12);
        $this->assertSame(7, $model->state->scannedFiles);
        $this->assertSame(12, $model->state->totalFiles);
    }

    public function testLeftRightCycleClusterDetailWhenOpen(): void
    {
        $model = $this->buildModel();

        $cluster1 = $this->fakeCluster('C1');
        $cluster2 = $this->fakeCluster('C2');
        $cluster3 = $this->fakeCluster('C3');
        $model->state->clusters = [$cluster1, $cluster2, $cluster3];

        $model->viewState->detailClusterId = 'C1';

        $model->update(new KeyMsg(KeyType::Right));
        $this->assertSame('C2', $model->viewState->detailClusterId);

        $model->update(new KeyMsg(KeyType::Right));
        $this->assertSame('C3', $model->viewState->detailClusterId);

        $model->update(new KeyMsg(KeyType::Right));
        $this->assertSame('C1', $model->viewState->detailClusterId, 'wraps to first');

        $model->update(new KeyMsg(KeyType::Left));
        $this->assertSame('C3', $model->viewState->detailClusterId, 'wraps backwards');
    }

    public function testLeftRightDoNothingWhenDetailClosed(): void
    {
        $model = $this->buildModel();
        $model->state->clusters = [$this->fakeCluster('C1')];
        $this->assertNull($model->viewState->detailClusterId);

        $model->update(new KeyMsg(KeyType::Right));
        $this->assertNull($model->viewState->detailClusterId);
    }

    private function fakeCluster(string $id): \Phpdup\Clustering\Cluster
    {
        return new \Phpdup\Clustering\Cluster(id: $id, members: [], similarity: 1.0, exact: true);
    }

    public function testInitWithoutFactoryMarksAnalysisCompleteAndReturnsBatchedCmd(): void
    {
        $model = $this->buildModel();
        $cmd = $model->init();
        $this->assertInstanceOf(\Closure::class, $cmd);
        $this->assertTrue($model->viewState->analysisComplete);
    }

    public function testInitWithFactoryDoesNotMarkAnalysisCompleteYet(): void
    {
        $factory = function () {
            $state = new \Phpdup\Pipeline\PipelineState(
                \Phpdup\Cli\Config::defaults([__DIR__ . '/../../Fixtures/sql']),
            );
            $gen = (function () { yield \Phpdup\Pipeline\Stage::Scanning; yield \Phpdup\Pipeline\Stage::Scanning; })();
            return [$gen, $state];
        };
        $model = new \Phpdup\Tui\PhpdupModel(
            new \Phpdup\Pipeline\PipelineState(\Phpdup\Cli\Config::defaults([__DIR__])),
            new \Phpdup\Tui\ViewState(),
            \Phpdup\Tui\TuiRunner::resolveTheme('plain'),
            $factory,
        );
        $cmd = $model->init();
        $this->assertInstanceOf(\Closure::class, $cmd);
        $this->assertFalse($model->viewState->analysisComplete);
    }

    public function testStagePumpedMsgAdvancesGeneratorAndSchedulesNextPump(): void
    {
        $factory = function () {
            $state = new \Phpdup\Pipeline\PipelineState(
                \Phpdup\Cli\Config::defaults([__DIR__]),
            );
            $gen = (function () {
                yield \Phpdup\Pipeline\Stage::Scanning;
                yield \Phpdup\Pipeline\Stage::Preprocessing;
            })();
            return [$gen, $state];
        };
        $model = new \Phpdup\Tui\PhpdupModel(
            new \Phpdup\Pipeline\PipelineState(\Phpdup\Cli\Config::defaults([__DIR__])),
            new \Phpdup\Tui\ViewState(),
            \Phpdup\Tui\TuiRunner::resolveTheme('plain'),
            $factory,
        );
        $model->init();

        // First pump rewinds → generator at first yield. Schedules next pump.
        [$_, $cmd1] = $model->update(new \Phpdup\Tui\Msg\StagePumpedMsg());
        $this->assertInstanceOf(\Closure::class, $cmd1, 'first pump schedules another');
        $this->assertFalse($model->viewState->analysisComplete);

        // Second pump advances to second yield. Still scheduling.
        [$_, $cmd2] = $model->update(new \Phpdup\Tui\Msg\StagePumpedMsg());
        $this->assertInstanceOf(\Closure::class, $cmd2);

        // Third pump exhausts the generator. analysisComplete flips on; no more cmds.
        [$_, $cmd3] = $model->update(new \Phpdup\Tui\Msg\StagePumpedMsg());
        $this->assertNull($cmd3, 'no more pumps once generator is exhausted');
        $this->assertTrue($model->viewState->analysisComplete);
    }

    public function testRestartPipelineMsgRebuildsIteratorAndState(): void
    {
        $callCount = 0;
        $factory = function () use (&$callCount) {
            $callCount++;
            $state = new \Phpdup\Pipeline\PipelineState(
                \Phpdup\Cli\Config::defaults([__DIR__]),
            );
            $gen = (function () { yield \Phpdup\Pipeline\Stage::Scanning; })();
            return [$gen, $state];
        };
        $model = new \Phpdup\Tui\PhpdupModel(
            new \Phpdup\Pipeline\PipelineState(\Phpdup\Cli\Config::defaults([__DIR__])),
            new \Phpdup\Tui\ViewState(),
            \Phpdup\Tui\TuiRunner::resolveTheme('plain'),
            $factory,
        );
        $model->init();
        $this->assertSame(1, $callCount, 'init triggers one factory call');

        // Drain the first pipeline.
        $model->update(new \Phpdup\Tui\Msg\StagePumpedMsg());
        $model->update(new \Phpdup\Tui\Msg\StagePumpedMsg());
        $this->assertTrue($model->viewState->analysisComplete);

        // Restart fires a new factory call and resets analysisComplete.
        [$_, $cmd] = $model->update(new \Phpdup\Tui\Msg\RestartPipelineMsg(reload: 7));
        $this->assertSame(2, $callCount, 'restart triggers a fresh factory call');
        $this->assertSame(7, $model->reloadCount);
        $this->assertFalse($model->viewState->analysisComplete);
        $this->assertInstanceOf(\Closure::class, $cmd);
    }

    public function testRestartPipelineMsgIsNoOpWithoutFactory(): void
    {
        $model = $this->buildModel(); // no factory
        [$_, $cmd] = $model->update(new \Phpdup\Tui\Msg\RestartPipelineMsg(reload: 1));
        $this->assertNull($cmd);
        $this->assertSame(0, $model->reloadCount);
    }

    private function buildModel(): PhpdupModel
    {
        $state = new PipelineState(Config::defaults([__DIR__]));
        return new PhpdupModel($state, new ViewState(), TuiRunner::resolveTheme('plain'));
    }
}
