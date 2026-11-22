<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Tui;

use PHPUnit\Framework\TestCase;
use Phpdup\Pipeline\Stage;
use Phpdup\Tui\ViewState;
use Phpdup\Util\ControlText;

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

    // ---- php-dup-finder-once-024: TUI control-char display boundary ----

    public function testDisplaySafeLeavesCleanTextByteIdentical(): void
    {
        $this->assertSame(
            'app/Model/User.php',
            ViewState::displaySafe('app/Model/User.php'),
            'a label with no control bytes must pass through untouched',
        );
    }

    public function testTerminalOnlyControlBytesRenderAsCaretNotation(): void
    {
        // BEL (0x07) and TAB (0x09) are legal-ish report bytes ControlText
        // excuses but drive/tear a terminal cell — they must show as caret.
        $this->assertSame('a^Gb', ViewState::displaySafe("a\x07b"));
        $this->assertSame('a^Ib', ViewState::displaySafe("a\tb"));
        $this->assertSame('a^?b', ViewState::displaySafe("a\x7fb"));
        $this->assertStringNotContainsString("\x07", ViewState::displaySafe("a\x07b"));
    }

    public function testForgeBytesGoThroughTheSharedControlTextVenue(): void
    {
        // The four stream-forging bytes (ESC here) take ControlText's
        // substitute arm — identical rendering to the output-S19 CLI scrub,
        // NOT a second forked caret sanitizer.
        $this->assertSame(
            ControlText::SUBSTITUTE . '[41m',
            ViewState::displaySafe("\x1b[41m"),
        );
        $this->assertStringNotContainsString("\x1b", ViewState::displaySafe("\x1b[41m"));

        $this->assertSame(
            'a' . ControlText::SUBSTITUTE . 'b^Lc'
                . ControlText::SUBSTITUTE . 'd^Ge',
            ViewState::displaySafe("a\x00b\x0cc\x0dd\x07e"),
            'NUL/CR take the substitute arm (one visible replacement char each); FF and BEL stay caret notation',
        );
    }

    public function testClipToCellsRunsOnDisplayCellsOfSafeText(): void
    {
        $this->assertSame('', ViewState::clipToCells('abc', 0));
        $this->assertSame('…', ViewState::clipToCells('abc', 1));
        $this->assertSame('abcde', ViewState::clipToCells('abcde', 5));
        $this->assertSame('ab…', ViewState::clipToCells('abcde', 3));
        $this->assertSame(3, mb_strwidth(ViewState::clipToCells('abcde', 3), 'UTF-8'));
    }

    public function testWidthMathRunsAfterEscapingNotOnRawBytes(): void
    {
        $raw = 'user' . "\x07" . 'name';      // 9 raw bytes, 10 escaped cells
        $this->assertSame(
            'user^Gname',
            ViewState::displayLabel($raw, 10),
            'a label that fits AFTER escaping passes whole — no premature ellipsis',
        );

        $clipped = ViewState::displayLabel($raw, 9);
        $this->assertStringNotContainsString("\x07", $clipped, 'no raw control byte survives the boundary');
        $this->assertLessThanOrEqual(9, mb_strwidth($clipped, 'UTF-8'), 'column budget honoured on escaped cells');
        $this->assertStringEndsWith('…', $clipped, 'clipping ran on the escaped (grown) width, not the raw bytes');

        // The bug class: measuring the raw 9 bytes against a 9-cell budget
        // would have emitted the raw BEL un-truncated. Escape-first does not.
        $this->assertSame('user^Gna…', $clipped);
    }

    public function testDisplayLabelPreservesColumnsForCleanLabels(): void
    {
        $this->assertSame(
            'app/Model/User.php',
            ViewState::displayLabel('app/Model/User.php', 80),
            'a clean in-budget label is byte-for-byte unchanged',
        );
    }

    public function testPushToastSanitizesAndClipsOnEnqueue(): void
    {
        $vs = new ViewState();
        $vs->cols = 20;
        $vs->pushToast('x' . str_repeat('y', 60) . "\x1b[200~done");

        $this->assertCount(1, $vs->toastQueue);
        $entry = $vs->toastQueue[0];
        $this->assertStringNotContainsString("\x1b", $entry, 'a toast can never carry a raw escape at paint time');
        $this->assertLessThanOrEqual(20, mb_strwidth($entry, 'UTF-8'));
        $this->assertStringEndsWith('…', $entry);
    }

    public function testPushToastRespectsZeroColsFloor(): void
    {
        $vs = new ViewState();
        $vs->cols = 0;
        $vs->pushToast('hello world');
        $this->assertSame('…', $vs->toastQueue[0], 'a degenerate viewport still emits a safe single cell');
    }
}
