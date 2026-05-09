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

    private function buildModel(): PhpdupModel
    {
        $state = new PipelineState(Config::defaults([__DIR__]));
        return new PhpdupModel($state, new ViewState(), TuiRunner::resolveTheme('plain'));
    }
}
