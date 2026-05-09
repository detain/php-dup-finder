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

    public function testCycleSortModeWalksAllKeysAndReturnsToImpact(): void
    {
        $vs = new ViewState();
        $this->assertSame(ViewState::SORT_IMPACT, $vs->sortMode, 'starts at impact');

        $seen = [$vs->sortMode];
        for ($i = 0; $i < count(ViewState::SORT_CYCLE) - 1; $i++) {
            $vs->cycleSortMode();
            $seen[] = $vs->sortMode;
        }
        $this->assertSame(ViewState::SORT_CYCLE, $seen, 'cycles through every key in declared order');

        $vs->cycleSortMode();
        $this->assertSame(ViewState::SORT_IMPACT, $vs->sortMode, 'wraps back to first');
    }

    public function testCycleSortModeRecoversFromOffListValue(): void
    {
        $vs = new ViewState();
        $vs->sortMode = 'totally-not-a-real-key';
        $vs->cycleSortMode();
        $this->assertSame(ViewState::SORT_CYCLE[0], $vs->sortMode);
    }

    public function testToggleSortDirectionAlternatesAscDesc(): void
    {
        $vs = new ViewState();
        $this->assertSame('desc', $vs->sortDirection, 'defaults to desc');
        $vs->toggleSortDirection();
        $this->assertSame('asc', $vs->sortDirection);
        $vs->toggleSortDirection();
        $this->assertSame('desc', $vs->sortDirection);
    }

    public function testClusterSortReflectsCurrentModeAndDirection(): void
    {
        $vs = new ViewState();
        $vs->sortMode = ViewState::SORT_MEMBERS;
        $vs->toggleSortDirection(); // → asc

        $cs = $vs->clusterSort();
        $this->assertSame('members', $cs->key);
        $this->assertSame('asc', $cs->direction);
    }

    public function testFocusedStageReflectsIndex(): void
    {
        $vs = new ViewState();
        $vs->focusedPaneIndex = 2;
        $this->assertSame(Stage::Clustering, $vs->focusedStage());
    }
}
