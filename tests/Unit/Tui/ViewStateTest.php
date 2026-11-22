<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Tui;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Clustering\CandidateBudgetException;
use Phpdup\Clustering\Cluster;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\Stage;
use Phpdup\Tui\Msg\StagePumpedMsg;
use Phpdup\Tui\PhpdupModel;
use Phpdup\Tui\TuiRunner;
use Phpdup\Tui\ViewState;
use Phpdup\Util\ControlText;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\Util\Width;

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

    // ---- php-dup-finder-once-070: clamps that clamp, cell-aware widths ----

    /**
     * The canonical-count contract: our displayWidth IS sugar-core's Width —
     * the grapheme-cell measure the terminal and sugar-flexbox paint with.
     * Rows are chosen where the retired per-codepoint math (mb_strwidth)
     * disagreed: combining marks, emoji presentation, ZWJ clusters,
     * regional-indicator pairs.
     *
     * @return array<string, array{string, int}>
     */
    public static function cellWidthMatrix(): array
    {
        return [
            'ascii'            => ['hello', 5],
            'cjk'              => ['日本語です', 10],
            'halfwidth-kana'   => ["\u{FF66}\u{FF67}\u{FF68}", 3], // halfwidth katakana: one cell each — only FULLWIDTH forms are 2
            'hangul'           => ['한글', 4],
            'accent-decomposed'=> ['a' . "\u{0301}" . 'b', 2], // combining mark rides 'a', 0 cells
            'check-emoji'      => ["\u{2705}ok", 4],           // Emoji_Presentation=Yes: 2 + 1 + 1
            'skin-tone'        => ["\u{1F44D}\u{1F3FD}", 2],   // one cluster, two cells — codepoint math said 4
            'zwj-family'       => ["\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}\u{200D}\u{1F466}", 2],
            'flag-pair'        => ["\u{1F1EF}\u{1F1F5}", 2],
            'ellipsis'         => ['…', 1],
            'arrows'           => ['↑/↓', 3],
        ];
    }

    /** @dataProvider cellWidthMatrix */
    public function testDisplayWidthAgreesWithCandyWidthUtil(string $text, int $cells): void
    {
        $this->assertSame($cells, ViewState::displayWidth($text), 'pinned cell count matches doctrine');
        $this->assertSame(
            Width::string($text),
            ViewState::displayWidth($text),
            'the door delegates to the stack-wide measure — no second fork',
        );
    }

    /**
     * Clipping cuts on grapheme-cluster boundaries in CELLS. The two
     * combining/ZWJ rows are exactly where the retired mb_strwidth
     * per-codepoint walk cut differently: it charged the accent 1 cell
     * (dropping the whole 'a-acute' cluster for lack of budget) and exploded
     * the family emoji into codepoints.
     *
     * @return array<string, array{string, int, string}>
     */
    public static function clipMatrix(): array
    {
        return [
            'fits exactly untouched'    => ['abcde', 5, 'abcde'],
            'ascii tail clipped'        => ['abcdefgh', 4, 'abc…'],
            'cjk budget 7'              => ['日本語です', 7, '日本語…'],  // 2+2+2+…(1) = 7 exactly — three whole glyphs fit the post-ellipsis budget
            'cjk odd budget'            => ['日本語です', 6, '日本…'],   // budget 5 fits only two glyphs — never a half cell…
            'zero budget'               => ['abc', 0, ''],
            'one budget is ellipsis'    => ['abcdefgh', 1, '…'],
            'combining cluster rides'   => ['a' . "\u{0301}" . 'bc', 2, 'a' . "\u{0301}" . '…'],
            'zwj cluster atomic'        => ["\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}\u{200D}\u{1F466}x", 3, "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}\u{200D}\u{1F466}x"],
            'skin tone atomic'          => ["\u{1F44D}\u{1F3FD}+x", 3, "\u{1F44D}\u{1F3FD}…"],
        ];
    }

    /** @dataProvider clipMatrix */
    public function testClipToCellsCutsOnClusterBoundariesInCells(string $text, int $maxCells, string $expected): void
    {
        $clipped = ViewState::clipToCells($text, $maxCells);
        $this->assertSame($expected, $clipped);
        $this->assertLessThanOrEqual(
            $maxCells,
            Width::string($clipped),
            'the clipped run never exceeds its cell budget under the candy measure',
        );
    }

    public function testClipToCellsNeverSplitsAWideGlyphFromItsBudget(): void
    {
        // Budget 3 with 2-cell glyphs: two cells used, ellipsis would need a
        // 4th — the walk stops at whole clusters and the ellipsis closes.
        $out = ViewState::clipToCells('日本語です', 3);
        $this->assertSame('日…', $out);
        $this->assertSame(3, Width::string($out));
    }

    public static function regionBudgetMatrix(): array
    {
        return [
            // viewport, overhead, ideal, expected — the once-070 class row first:
            '40-col screen never inflates to 60' => [40, 0, 60, 40],
            '20-col screen never inflates to 80' => [20, 0, 80, 20],
            'ideal wins when smaller'            => [100, 0, 40, 40],
            'overhead subtracts'                 => [80, 24, PHP_INT_MAX, 56],
            'overhead swallows viewport'         => [10, 24, PHP_INT_MAX, 1],
            'degenerate zero viewport'           => [0, 0, 60, 1],
            'negative viewport floors at one'    => [-5, 10, 60, 1],
        ];
    }

    /** @dataProvider regionBudgetMatrix */
    public function testRegionBudgetIsACeilingNotAFloor(int $viewport, int $overhead, int $ideal, int $expected): void
    {
        $budget = ViewState::regionBudget($viewport, $overhead, $ideal);
        $this->assertSame($expected, $budget);
        $this->assertGreaterThanOrEqual(1, $budget, 'a region always keeps one honest cell');
        $this->assertLessThanOrEqual(
            max(1, $viewport - $overhead),
            $budget,
            'a budget never exceeds what the viewport minus overhead can show',
        );
    }

    // ---- model-level layout pins (venue: this file is the lock; the model
    //      just consumes the ViewState doors above) ----

    public static function viewportMatrix(): array
    {
        return [
            'degenerate 1x1'      => [1, 1],
            'postcard 4x3'        => [4, 3],
            'wristwatch 12x5'     => [12, 5],
            'pager 16x8'          => [16, 8],
            'once-070 40x10'      => [40, 10],
            'cramped 60x12'       => [60, 12],
            'standard 80x24'      => [80, 24],
            'wide 120x30'         => [120, 30],
            'huge 200x50'         => [200, 50],
            'short 80x6'          => [80, 6],
            'tall 20x40'          => [20, 40],
        ] + [];
    }

    /**
     * Width census across the whole size matrix (once-070 acceptance): every
     * painted line fits the viewport in CELLS, from 1x1 to 200x50, with the
     * full hostile fixture running (CJK task, 90-char tail, expanded help,
     * detail with escaped signature, sparkline).
     *
     * @dataProvider viewportMatrix
     */
    public function testEveryRenderedLineFitsViewportWidth(int $cols, int $rows): void
    {
        $model = $this->buildLayoutModel(cols: $cols, rows: $rows);
        $this->assertBodyLinesFit($model->view()->body, $cols);
    }

    /**
     * Row census (once-070, vertical twin): too many painted rows scrolls
     * the terminal — border-tear by another route. Auxiliary rows (help,
     * sparkline, status) must drop before the frame overruns the screen;
     * the irreducible honest frame is 4 rows, disclosed as the floor.
     *
     * @dataProvider viewportMatrix
     */
    public function testRenderedRowCountNeverExceedsViewportRows(int $cols, int $rows): void
    {
        $model = $this->buildLayoutModel(cols: $cols, rows: $rows);
        $body  = $model->view()->body;
        $count = $body === '' ? 0 : count(explode("\n", rtrim($body, "\n")));
        $this->assertLessThanOrEqual(
            max($rows, 5),
            $count,
            "a {$cols}x{$rows} viewport never paints more rows than it has (help/spark/status drop first)",
        );
    }

    /**
     * The halt case across the matrix: the panel keeps every word it can
     * show, is never dropped, and its rows still fit the viewport width.
     *
     * @dataProvider viewportMatrix
     */
    public function testHaltedRenderKeepsEveryLineWithinViewportWidth(int $cols, int $rows): void
    {
        $model = $this->buildThrowingModel(CandidateBudgetException::exceeded(1_000, 500, 10_000));
        $model->update(new WindowSizeMsg($cols, $rows));
        $model->init();
        $model->update(new StagePumpedMsg());
        $model->update(new StagePumpedMsg());
        $model->update(new StagePumpedMsg());

        $body = $model->view()->body;
        $this->assertBodyLinesFit($body, $cols);
        if ($cols >= 12) {
            // From 12 cells up the panel head is legibly present (S07 law:
            // a halt is never laundered into invisibility).
            $this->assertStringContainsString('phpdup:', $body);
        }
    }

    public function testDegenerateViewportDoesNotInflatePastOneCell(): void
    {
        $model = $this->buildLayoutModel(cols: 1, rows: 1);
        $body  = $model->view()->body;
        foreach (explode("\n", $body) as $line) {
            $this->assertLessThanOrEqual(1, Width::string($line), 'cols=1 must paint 1-cell rows, never the old 60-col floor');
        }
    }

    public function testWideBannerIsClippedInsteadOfOverrunningTheScreen(): void
    {
        $model = $this->buildLayoutModel(cols: 40, rows: 10);
        $first = explode("\n", $model->view()->body)[0];
        $this->assertStringStartsWith(' phpdup —', $first, 'banner keeps its head');
        $this->assertStringEndsWith('…', $first, 'and closes with an ellipsis instead of a wrap');
        $this->assertLessThanOrEqual(40, Width::string($first));
    }

    public function testCjkLabelStaysOnItsRowAtNarrowWidth(): void
    {
        $model = $this->buildLayoutModel(cols: 40, rows: 10);
        $lines = explode("\n", $model->view()->body);

        $withCjk = array_values(array_filter($lines, static fn(string $l): bool => str_contains($l, '解析')));
        $this->assertNotSame([], $withCjk, 'the CJK task label is on screen');
        foreach ($withCjk as $row) {
            $this->assertLessThanOrEqual(
                40,
                Width::string($row),
                'a CJK-bearing row measured in CELLS (not codepoints) fits the viewport — it can never wrap to a second screen row',
            );
        }
    }

    public function testHostileClusterSignatureIsSanitizedAndClippedInDetail(): void
    {
        $model = $this->buildLayoutModel(cols: 40, rows: 10);
        $body  = $model->view()->body;
        $this->assertStringNotContainsString("\x1b", $body, 'signature ESC bytes never reach the screen');
        $this->assertStringNotContainsString("\x07", $body, 'signature BEL bytes never reach the screen');
        $this->assertStringContainsString('^G', $body, 'and they surface as caret notation inside the clipped detail row');
    }

    public function testWindowResizeShrinksLayoutWithoutTheOldFloor(): void
    {
        $model = $this->buildLayoutModel(cols: 80, rows: 24);
        $model->update(new WindowSizeMsg(40, 10));
        $this->assertBodyLinesFit($model->view()->body, 40, 'a live shrink re-lays-out at the new ceiling');
    }

    /**
     * once-S10/S07 law, pinned for the layout step: word-wrap (never a clip)
     * keeps the halt message readable — here at 60 columns, where every word
     * of the real CandidateBudgetException remedy text is shorter than the
     * wrap line, so the FULL message must survive whitespace-normalised.
     * A truncation-to-invisibility of a halt is the failure class this pins.
     */
    public function testHaltMessageSurvivesNarrowViewportAsVisibleWrappedText(): void
    {
        $model = $this->buildThrowingModel(CandidateBudgetException::exceeded(1_000, 500, 10_000));
        $model->update(new WindowSizeMsg(60, 12));
        $model->init();
        $model->update(new StagePumpedMsg());
        $model->update(new StagePumpedMsg());
        $model->update(new StagePumpedMsg());

        $body = $model->view()->body;
        $collapse = static fn(string $t): string => (string) preg_replace('/\s+/u', ' ', $t);
        $this->assertStringContainsString(
            $collapse('✗ phpdup: Candidate generation exceeded its safety budget'),
            $collapse($body),
            'the halt panel is present and loud at 60 cols',
        );
        $this->assertStringContainsString(
            $collapse('Ways forward: lower --max-df'),
            $collapse($body),
            'the remedy opening survives wrapping intact',
        );
        // Whole-word visibility (word > wrap-line is the ONLY clip case in
        // the S07 wrap contract): these fit 36-cell lines and must survive.
        foreach (['lower', '--max-df', 'raise', '--candidate-budget', 'MAX_CANDIDATE_PAIRS'] as $token) {
            $this->assertStringContainsString($token, $body, "remedy word {$token} visible at 60 cols");
        }
        $this->assertBodyLinesFit($body, 60);
    }

    /**
     * At a brutally narrow 20-column screen the panel keeps its head word and
     * stays inside the viewport — the halt never becomes invisible, and no
     * row overruns the screen. (Disclosed: a SINGLE word wider than the line
     * is the only thing clipped — the once-S07 wrap contract, verbatim.)
     */
    public function testHaltPanelAtDegenerateWidthStaysVisibleAndInside(): void
    {
        $model = $this->buildThrowingModel(new \RuntimeException('TED budget blown'));
        $model->update(new WindowSizeMsg(20, 10));
        $model->init();
        $model->update(new StagePumpedMsg());
        $model->update(new StagePumpedMsg());
        $model->update(new StagePumpedMsg());

        $body = $model->view()->body;
        $this->assertStringContainsString('✗ phpdup: TED', $body, 'head word visible at 20 cols');
        $this->assertBodyLinesFit($body, 20);
    }

    private function assertBodyLinesFit(string $body, int $cols, string $message = 'every rendered line fits the viewport'): void
    {
        foreach (explode("\n", $body) as $line) {
            $this->assertLessThanOrEqual($cols, Width::string($line), $message);
        }
    }

    /**
     * A model busy enough to paint every region at once: banner with counts,
     * four panes, live status line (long CJK task), sparkline, expanded
     * cluster detail with a hostile signature, and the full help row — the
     * old code rendered all of these unclipped.
     */
    private function buildLayoutModel(int $cols, int $rows): PhpdupModel
    {
        $state = new PipelineState(Config::defaults([__DIR__]));
        $state->files      = array_fill(0, 137, __DIR__ . '/fixture.php');
        $state->totalFiles = 137;
        $state->currentTask = '解析データファイル.php をクラスタリング中 ' . str_repeat('x', 90);
        $state->timings['preprocess'] = 1.25;
        $state->timings['cluster']    = 2.5;

        $cluster = new Cluster(id: 'C1', members: [], similarity: 0.99, exact: true);
        // Hostile bytes are placed where the 40-col clip still reaches them:
        // the ESC goes through ControlText's substitute arm, the BEL becomes
        // '^G' caret notation — both inside the clipped signature row.
        $cluster->signature = "function \x1b[31m\x07解析(const \$a): 混合 " . str_repeat('y', 200);
        $state->clusters = [$cluster];

        $view = new ViewState();
        $view->cols = $cols;
        $view->rows = $rows;
        $view->helpExpanded = true;
        $view->detailClusterId = 'C1';

        $model = new PhpdupModel($state, $view, TuiRunner::resolveTheme('plain'));
        $model->onStageEnd(Stage::Preprocessing); // one duration → sparkline paints
        return $model;
    }

    /** Model whose live pipeline yields once, then throws the given failure. */
    private function buildThrowingModel(\Throwable $failure): PhpdupModel
    {
        $factory = static function () use ($failure): array {
            $gen = (static function () use ($failure): \Generator {
                yield Stage::Scanning;
                throw $failure;
            })();
            return [$gen, new PipelineState(Config::defaults([__DIR__]))];
        };
        return new PhpdupModel(
            new PipelineState(Config::defaults([__DIR__])),
            new ViewState(),
            TuiRunner::resolveTheme('plain'),
            $factory,
        );
    }
}
