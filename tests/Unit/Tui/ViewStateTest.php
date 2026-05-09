<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Tui;

use PHPUnit\Framework\TestCase;
use Phpdup\Pipeline\Stage;
use Phpdup\Tui\ViewState;

final class ViewStateTest extends TestCase
{
    public function testFocusableStagesAreTheFourPipelineStages(): void
    {
        $this->assertSame(
            [Stage::Scanning, Stage::Preprocessing, Stage::Clustering, Stage::Refactoring],
            ViewState::focusablePanes(),
        );
    }

    public function testCyclePaneFocusForwardWraps(): void
    {
        $vs = new ViewState();
        for ($i = 0; $i < count(ViewState::focusablePanes()); $i++) {
            $vs->cyclePaneFocus(1);
        }
        $this->assertSame(0, $vs->focusedPaneIndex);
    }

    public function testCyclePaneFocusBackwardFromZero(): void
    {
        $vs = new ViewState();
        $vs->cyclePaneFocus(-1);
        $this->assertSame(count(ViewState::focusablePanes()) - 1, $vs->focusedPaneIndex);
        $this->assertSame(Stage::Refactoring, $vs->focusedStage());
    }

    public function testCycleSortModeRoundtrips(): void
    {
        $vs = new ViewState();
        $this->assertSame(ViewState::SORT_IMPACT, $vs->sortMode);
        $vs->cycleSortMode();
        $this->assertSame(ViewState::SORT_SIMILARITY, $vs->sortMode);
        $vs->cycleSortMode();
        $this->assertSame(ViewState::SORT_NAME, $vs->sortMode);
        $vs->cycleSortMode();
        $this->assertSame(ViewState::SORT_IMPACT, $vs->sortMode);
    }

    public function testFocusedStageReflectsIndex(): void
    {
        $vs = new ViewState();
        $vs->focusedPaneIndex = 2;
        $this->assertSame(Stage::Clustering, $vs->focusedStage());
    }
}
