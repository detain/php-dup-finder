<?php
declare(strict_types=1);

namespace Phpdup\Tui;

use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\ProgressListener;
use Phpdup\Pipeline\Stage;
use Phpdup\Tui\Msg\RestartPipelineMsg;
use Phpdup\Tui\Msg\StagePumpedMsg;
use Phpdup\Util\CountPhrase;
use SugarCraft\Forms\Spinner\Spinner;
use SugarCraft\Forms\Spinner\Style as SpinnerStyle;
use SugarCraft\Forms\Spinner\TickMsg as SpinnerTickMsg;
use SugarCraft\Charts\Sparkline\Sparkline;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\Progress;
use SugarCraft\Core\ProgressBarState;
use SugarCraft\Core\Subscriptions;
use SugarCraft\Core\View;
use SugarCraft\Cli\Theme;
use SugarCraft\Flexbox\Flex\FlexBox;
use SugarCraft\Flexbox\Flex\FlexItem;

/**
 * SugarCraft TUI model for phpdup.
 *
 * Holds {@see PipelineState} (analysis data) + {@see ViewState} (focus, sort mode, toasts)
 * and renders a four-pane FlexBox dashboard. Phase 2 ships the shell with key handling and
 * theme support; Phase 3 wires live pipeline updates in via TickMsg / per-stage callbacks.
 */
final class PhpdupModel implements Model, ProgressListener
{
    private Spinner $spinner;

    /** @var list<float> stage durations pushed in order, plotted as a Sparkline. */
    private array $stageDurations = [];

    private float $startedAt;

    /**
     * Cooperative pipeline iterator — null when the model isn't driving live (Phase 2 mode).
     */
    private ?\Generator $iterator = null;

    /**
     * shell-S40 (php-dup-finder-shell-051): minimum interval between RSS
     * samples, seconds. The progress callbacks fire per file / per pair —
     * thousands of times per stage on a hot corpus — while the dashboard's
     * memory gauge is only worth re-reading a few times a second; in
     * particular memory_get_peak_usage(true) reaches the allocator's real
     * figures, a syscall on the per-yield path. Inside the window the last
     * reading is kept as-is (a gauge, not an alarm: staleness costs a
     * repaint, not correctness).
     */
    private const RSS_SAMPLE_INTERVAL_SECONDS = 0.25;

    /**
     * Clock backing the RSS throttle ({@see self::$lastRssSampleAt}).
     * Injectable so unit tests pin the window without sleeping; the
     * default is wall-clock seconds like every other timer in this model.
     *
     * @var \Closure(): float
     */
    private readonly \Closure $nowSeconds;

    private ?float $lastRssSampleAt = null;

    /** True once the iterator has been rewound; tracks whether to call rewind() vs next() on the next pump. */
    private bool $iteratorStarted = false;

    /**
     * Factory used to (re)build a Pipeline iterator when the model wants to start fresh —
     * e.g. on init or after a {@see RestartPipelineMsg}. The factory should also return a
     * fresh PipelineState (which is then assigned to {@see $state}) so the dashboard zeroes
     * out properly.
     *
     * @var \Closure(): array{0: \Generator<int, Stage>, 1: PipelineState}|null
     */
    private $iteratorFactory;

    /** Reload counter shown in the dashboard when watch-mode triggers RestartPipelineMsg. */
    public int $reloadCount = 0;

    /**
     * Raw halt fact of the live pipeline (once-S07): the message of the
     * throwable that killed a pump, null while no halt happened. Stored
     * undisplayed on purpose — the renderer scrubs it through
     * {@see ViewState::displaySafe()} at paint time, and {@see self::exitCode()}
     * turns it into the CLI's non-zero posture so a halt can never look
     * like a clean completion.
     */
    public ?string $pipelineError = null;

    /** Class of the throwable behind {@see self::$pipelineError} (diagnostics identity). */
    public ?string $pipelineErrorClass = null;

    public function __construct(
        public PipelineState $state,
        public ViewState $viewState,
        private readonly Theme $theme,
        ?\Closure $iteratorFactory = null,
        ?\Closure $nowSeconds = null,
    ) {
        $this->spinner         = Spinner::new(SpinnerStyle::miniDot());
        $this->startedAt       = microtime(true);
        $this->iteratorFactory = $iteratorFactory;
        $this->nowSeconds      = $nowSeconds ?? static fn(): float => microtime(true);
    }

    #[\Override]
    public function onStageStart(Stage $stage): void
    {
        $this->state->stage = $stage;
    }

    #[\Override]
    public function onStageEnd(Stage $stage): void
    {
        $duration = match ($stage) {
            Stage::Preprocessing => $this->state->timings['preprocess'] ?? 0.0,
            Stage::Clustering    => $this->state->timings['cluster']    ?? 0.0,
            Stage::Refactoring   => $this->state->timings['refactor']   ?? 0.0,
            default              => 0.0,
        };
        $this->stageDurations[] = $duration;
    }

    #[\Override]
    public function onFileScanned(int $scanned, int $total): void
    {
        // PipelineState already tracks this; surface to the dashboard via state.
        $this->state->scannedFiles = $scanned;
        $this->state->totalFiles   = $total;
        $this->sampleRss();
    }

    #[\Override]
    public function onFilePreprocessed(int $processed, int $reused, int $errors): void
    {
        $this->state->processedFiles = $processed;
        $this->state->reusedFiles    = $reused;
        $this->state->parseErrors    = $errors;
        $this->sampleRss();
    }

    #[\Override]
    public function onPairScored(int $scored, int $total): void
    {
        $this->state->scoredPairs    = $scored;
        $this->state->candidatePairs = $total;
        $this->sampleRss();
    }

    #[\Override]
    public function onClusterRefactored(int $refactored, int $total): void
    {
        $this->state->refactoredClusters = $refactored;
        $this->sampleRss();
    }

    /**
     * Read the memory gauges at most once per {@see self::RSS_SAMPLE_INTERVAL_SECONDS};
     * inside the window the previous reading survives untouched.
     */
    private function sampleRss(): void
    {
        $now = ($this->nowSeconds)();
        if ($this->lastRssSampleAt !== null && $now - $this->lastRssSampleAt < self::RSS_SAMPLE_INTERVAL_SECONDS) {
            return;
        }
        $this->lastRssSampleAt = $now;
        $this->state->rssBytes = memory_get_usage(false);
        $this->state->peakBytes = memory_get_peak_usage(true);
    }

    #[\Override]
    public function init(): \Closure
    {
        $cmds = [$this->spinner->tick()];
        if ($this->iteratorFactory !== null) {
            // Live-drive mode: build a fresh iterator + state and start pumping.
            $this->buildIterator();
            $cmds[] = $this->scheduleNextPump();
        } else {
            // No factory means the pipeline ran before the TUI booted (Phase 2 mode).
            $this->viewState->analysisComplete = true;
        }
        return Cmd::batch(...$cmds);
    }

    /** @return array{0: Model, 1: \Closure|null} */
    #[\Override]
    public function update(Msg $msg): array
    {
        if ($msg instanceof WindowSizeMsg) {
            $this->viewState->cols = $msg->cols;
            $this->viewState->rows = $msg->rows;
            return [$this, null];
        }
        if ($msg instanceof KeyMsg) {
            return $this->handleKey($msg);
        }
        if ($msg instanceof SpinnerTickMsg && $msg->id === $this->spinner->id()) {
            [$nextSpinner, $cmd] = $this->spinner->update($msg);
            assert($nextSpinner instanceof Spinner);
            $this->spinner = $nextSpinner;
            return [$this, $cmd];
        }
        if ($msg instanceof StagePumpedMsg) {
            return $this->pumpPipeline();
        }
        if ($msg instanceof RestartPipelineMsg) {
            return $this->restart($msg->reload);
        }
        return [$this, null];
    }

    /**
     * Pump the cooperative pipeline iterator one step. Each pump advances to
     * the next yield point; between pumps the runtime renders.
     *
     * @return array{0: Model, 1: \Closure|null}
     */
    private function pumpPipeline(): array
    {
        if ($this->iterator === null) {
            return [$this, null];
        }

        try {
            if (!$this->iteratorStarted) {
                $this->iterator->rewind();
                $this->iteratorStarted = true;
            } else {
                $this->iterator->next();
            }
        } catch (\Throwable $e) {
            // once-S07 (php-dup-finder-once-007): a pump that dies is a FAILURE,
            // not a completion. Record the raw halt fact (panel + exit code read
            // it), and route the breadcrumb toast through ViewState's display
            // door so the text is sanitized-and-clipped on the way in.
            $this->pipelineError = $e->getMessage();
            $this->pipelineErrorClass = $e::class;
            $this->viewState->pushToast('Pipeline error: ' . $e->getMessage());
            $this->iterator = null;
            $this->viewState->analysisComplete = true;
            return [$this, null];
        }

        if (!$this->iterator->valid()) {
            $this->iterator = null;
            $this->viewState->analysisComplete = true;
            return [$this, null];
        }

        return [$this, $this->scheduleNextPump()];
    }

    /**
     * Discard the current iterator/state and start a new analysis run. Called when
     * watch-mode detects a file change.
     *
     * @return array{0: Model, 1: \Closure|null}
     */
    private function restart(int $reload): array
    {
        if ($this->iteratorFactory === null) {
            return [$this, null];
        }
        $this->reloadCount = $reload;
        $this->viewState->analysisComplete = false;
        $this->buildIterator();
        return [$this, $this->scheduleNextPump()];
    }

    private function buildIterator(): void
    {
        if ($this->iteratorFactory === null) {
            return;
        }
        [$gen, $state] = ($this->iteratorFactory)();
        // A fresh run must not inherit the previous run's halt — the error
        // panel and the exit-1 posture clear together (init + watch restart).
        $this->pipelineError      = null;
        $this->pipelineErrorClass = null;
        $this->iterator        = $gen;
        $this->iteratorStarted = false;
        $this->state           = $state;
        $this->stageDurations  = [];
        $this->startedAt       = microtime(true);
        // A fresh run must not inherit the previous run's throttle window —
        // the new dashboard's first progress callback samples immediately.
        $this->lastRssSampleAt = null;
    }

    private function scheduleNextPump(): \Closure
    {
        return Cmd::tick(0.0, static fn() => new StagePumpedMsg());
    }

    #[\Override]
    public function view(): View
    {
        return new View(
            body: $this->renderBody(),
            progressBar: $this->taskbarProgress(),
        );
    }

    /**
     * No subscriptions: the dashboard drives its pipeline via a self-rescheduling
     * {@see Cmd::tick} pump (see {@see scheduleNextPump()}), not the subscription
     * system, so the reconciler has nothing to manage here.
     */
    #[\Override]
    public function subscriptions(): ?Subscriptions
    {
        return null;
    }

    /**
     * Process exit status the TUI must carry (once-S07). A halted pipeline
     * is the CLI's runtime-error class — exit 1 — not a silent success;
     * every other outcome (clean completion, user quit) stays 0.
     */
    public function exitCode(): int
    {
        return $this->pipelineError !== null ? 1 : 0;
    }

    private function taskbarProgress(): Progress
    {
        if ($this->pipelineError !== null) {
            return new Progress(ProgressBarState::Error, 100);
        }
        if ($this->viewState->analysisComplete) {
            return new Progress(ProgressBarState::Normal, 100);
        }
        $idx = $this->state->stage->index();
        $totalStages = count(Stage::ordered());
        $percent = (int)floor(($idx + $this->state->stageProgress) / $totalStages * 100);
        return new Progress(ProgressBarState::Normal, max(0, min(100, $percent)));
    }

    /** @return array{0: Model, 1: \Closure|null} */
    private function handleKey(KeyMsg $msg): array
    {
        if ($msg->ctrl && $msg->type === KeyType::Char && strtolower($msg->rune) === 'c') {
            return [$this, Cmd::quit()];
        }
        if ($msg->ctrl && $msg->type === KeyType::Char && strtolower($msg->rune) === 'z') {
            return [$this, Cmd::suspend()];
        }
        if ($msg->type === KeyType::Char) {
            // Handle shift-T (uppercase) for direction toggling before
            // lower-casing for the regular dispatch.
            if ($msg->rune === 'T') {
                return $this->mutate(fn() => $this->viewState->toggleSortDirection());
            }
            return match (strtolower($msg->rune)) {
                'q'     => [$this, Cmd::quit()],
                't'     => $this->mutate(fn() => $this->viewState->cycleSortMode()),
                'h'     => $this->mutate(fn() => $this->viewState->helpExpanded = !$this->viewState->helpExpanded),
                default => [$this, null],
            };
        }
        return match ($msg->type) {
            KeyType::Up    => $this->mutate(fn() => $this->viewState->cyclePaneFocus(-1)),
            KeyType::Down  => $this->mutate(fn() => $this->viewState->cyclePaneFocus(1)),
            KeyType::Left  => $this->mutate(fn() => $this->cycleClusterDetail(-1)),
            KeyType::Right => $this->mutate(fn() => $this->cycleClusterDetail(1)),
            KeyType::Enter => $this->mutate(fn() => $this->openDetail()),
            KeyType::Escape => $this->mutate(fn() => $this->viewState->detailClusterId = null),
            default => [$this, null],
        };
    }

    private function cycleClusterDetail(int $delta): void
    {
        if ($this->state->clusters === [] || $this->viewState->detailClusterId === null) {
            return;
        }
        $ids = array_map(fn($c) => $c->id, $this->state->clusters);
        $idx = array_search($this->viewState->detailClusterId, $ids, true);
        if ($idx === false) {
            $this->viewState->detailClusterId = $ids[0];
            return;
        }
        $count = count($ids);
        $next  = (($idx + $delta) % $count + $count) % $count;
        $this->viewState->detailClusterId = $ids[$next];
    }

    private function openDetail(): void
    {
        if ($this->state->clusters !== []) {
            $this->viewState->detailClusterId = $this->state->clusters[0]->id;
        }
    }

    /**
     * Apply a mutation to viewState then return self with no Cmd.
     *
     * @return array{0: Model, 1: \Closure|null}
     */
    private function mutate(\Closure $apply): array
    {
        $apply();
        return [$this, null];
    }

    private function renderBody(): string
    {
        // once-S61 (php-dup-finder-once-070): the viewport is a CEILING, not
        // a suggestion. The old `max(60, cols)` / `max(20, rows)` inflated
        // the layout past small terminals — rows wider than the screen are
        // wrapped by the terminal itself, tearing every border below. A
        // degenerate 0-clipped reading floors at 1 honest cell; it never
        // grows past what the terminal can show.
        $cols = max(1, $this->viewState->cols);
        $rows = max(1, $this->viewState->rows);

        $banner = $this->theme->accent->render(ViewState::clipToCells(sprintf(
            ' phpdup — %s · %d blocks · %d clusters%s',
            CountPhrase::files(count($this->state->files)),
            count($this->state->blocks),
            count($this->state->clusters),
            $this->viewState->analysisComplete ? '' : '   ' . $this->spinner->view(),
        ), $cols));

        $panes = FlexBox::row(
            FlexItem::new($this->paneFor(Stage::Scanning))->withRatio(1),
            FlexItem::new($this->paneFor(Stage::Preprocessing))->withRatio(1),
            FlexItem::new($this->paneFor(Stage::Clustering))->withRatio(1),
            FlexItem::new($this->paneFor(Stage::Refactoring))->withRatio(1),
        )->withGap(1);

        // Panes shrink instead of overflowing: 8 chrome rows (banner + blank
        // + status + sparkline + detail + blanks) reserved, never more rows
        // than the screen has, never fewer than one honest row.
        $paneRows = max(1, min($rows, $rows - 8));

        $errPanel = $this->renderErrorPanel($cols);

        $statusLine = $this->viewState->analysisComplete ? '' : $this->renderStatusLine($cols);

        $detail = $this->viewState->detailClusterId !== null
            ? $this->renderDetail($cols)
            : $this->tipLine($cols);

        $help = $this->viewState->helpExpanded ? $this->helpLine($cols) : '';

        $paneBlock = $panes->render($cols, $paneRows);
        $compose   = fn(string $status, string $spark, string $helpRow): string =>
            $banner . "\n\n"
            . $errPanel
            . $paneBlock . "\n"
            . $status . $spark . $detail . $helpRow;

        $body = $compose($statusLine, $this->renderTimingsSparkline($cols), $help);

        // Vertical clamp that CLAMPS (once-070): too many rows scrolls the
        // terminal and tears the layout below just as badly as too-wide rows
        // wrap. Auxiliary rows drop in least-important order — help, then
        // sparkline, then live status — until the paint fits the screen.
        // The halt panel, banner, one pane row and the detail/tip row are
        // the irreducible honest frame (5 rows: banner + blank + pane +
        // detail head + continuation, plus one per error-panel row) and are
        // never dropped; below that floor the terminal scrolls by physical
        // necessity, disclosed in the census as max($rows, 5 + err rows).
        $errRows = $this->countRows($errPanel);
        $census  = static fn(string $b): int => $b === '' ? 0 : count(explode("\n", rtrim($b, "\n")));
        $floor   = 5 + $errRows;
        if ($census($body) > max($rows, $floor)) {
            $body = $compose($statusLine, $this->renderTimingsSparkline($cols), '');
        }
        if ($census($body) > max($rows, $floor)) {
            $body = $compose($statusLine, '', '');
        }
        if ($census($body) > max($rows, $floor)) {
            $body = $compose('', '', '');
        }
        return $body;
    }

    /** Screen rows a fragment occupies (trailing newline is a row terminator, not a row). */
    private function countRows(string $fragment): int
    {
        return $fragment === '' ? 0 : count(explode("\n", rtrim($fragment, "\n")));
    }

    /**
     * Live-task status row: the fixed label segment keeps its cells, the
     * task text (stage-produced, and per the display-door doctrine treated
     * as untrusted content) gets whatever the viewport leaves, clipped in
     * cells so the row always occupies exactly one screen row.
     */
    private function renderStatusLine(int $cols): string
    {
        $label = '▸ ' . $this->state->stage->label();
        // 2 leading + label + 2 separator cells are fixed; the task owns the
        // rest. When the viewport cannot hold even the fixed part plus one
        // task cell, the honest layout drops the row — a row that cannot fit
        // is a guaranteed wrap (php-dup-finder-once-070).
        $fixed = 4 + ViewState::displayWidth($label);
        if ($cols < $fixed + 1) {
            return '';
        }
        $task = ViewState::displayLabel(
            $this->state->currentTask !== '' ? $this->state->currentTask : 'working...',
            ViewState::regionBudget($cols, $fixed, PHP_INT_MAX),
        );

        return "  " . $this->theme->info->render($label)
              . '  ' . $this->theme->muted->render($task) . "\n";
    }

    /** Tip row, clipped to the viewport (once-070: the 84-col tip never was). */
    private function tipLine(int $cols): string
    {
        $tip = sprintf(
            '  Tip: ↑/↓ focus · Enter detail · t sort (%s) · T flip dir · h help · q quit',
            $this->viewState->sortMode . ':' . $this->viewState->sortDirection,
        );
        // The indent rides INSIDE the clip so even a 1-cell viewport gets a
        // 1-cell row — a pad that cannot fit is a line-break in disguise.
        return $this->theme->muted->render(ViewState::clipToCells($tip, $cols));
    }

    /** Key-help row, clipped to the viewport (once-070: the 190-col row never was). */
    private function helpLine(int $cols): string
    {
        return "\n" . $this->theme->muted->render(ViewState::clipToCells('  ' .
            'Keys: ↑/↓ cycle focused pane · ←/→ navigate detail · Enter expand · Esc dismiss · t cycle sort (impact/members/block-size/lines/similarity/confidence/name) · T flip asc/desc · h toggle help · Ctrl+C / q quit',
            $cols,
        ));
    }

    /**
     * Error panel (once-S07): the halted pipeline's reason rendered loudly
     * under the banner in the same "phpdup: " shape the CLI writes on
     * stderr. Each source line of the message is display-sanitized and
     * WORD-WRAPPED (not clipped) at the panel width, so multi-line remedies
     * — the CandidateBudgetException "Ways forward:" advice — stay readable
     * at 80 columns instead of losing their tail to a clip.
     */
    private function renderErrorPanel(int $cols): string
    {
        if ($this->pipelineError === null) {
            return '';
        }
        // First line shares the row with the '✗ phpdup: ' head, so the wrap
        // budget is the viewport minus that head. The old `max(20, cols-24)`
        // alone could hand a narrow terminal a WIDER budget than the screen
        // minus its head (once-070) — min() now also caps at $cols - head,
        // while the generous 24-col inset survives as the comfort ceiling it
        // always wanted to be (php-dup-finder-once-070).
        $headPrefix = '✗ phpdup: ';
        $width = max(1, min(
            $cols - ViewState::displayWidth($headPrefix),
            max(20, $cols - 24),
        ));
        $displayLines = [];
        foreach (explode("\n", $this->pipelineError) as $sourceLine) {
            foreach (self::wrapToCells(ViewState::displaySafe($sourceLine), $width) as $wrapped) {
                $displayLines[] = $wrapped;
            }
        }
        $head = array_shift($displayLines) ?? '';
        // Both rows ride a final viewport clip covering their prefixes, so
        // the panel can never emit an over-wide line even at degenerate
        // widths (the wrap budget already guarantees it for sane screens).
        $out = "\n" . $this->theme->error->render(
            ViewState::clipToCells($headPrefix . $head, $cols),
        ) . "\n";
        foreach ($displayLines as $line) {
            $out .= $this->theme->error->render(ViewState::clipToCells('  ' . $line, $cols)) . "\n";
        }
        return $out . "\n";
    }

    /**
     * Greedy word-wrap into display-cell-sized lines. Every word survives —
     * a word wider than the line is the only thing that gets clipped.
     *
     * @return list<string>
     */
    private static function wrapToCells(string $text, int $width): array
    {
        if ($text === '') {
            return [''];
        }
        $words = preg_split('/ +/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $lines   = [];
        $current = null;
        foreach ($words as $word) {
            $word = ViewState::clipToCells($word, $width);
            if ($current === null) {
                $current = $word;
                continue;
            }
            if (ViewState::displayWidth($current . ' ' . $word) <= $width) {
                $current .= ' ' . $word;
                continue;
            }
            $lines[]   = $current;
            $current   = $word;
        }
        $lines[] = $current ?? '';
        return $lines;
    }

    private function renderTimingsSparkline(int $cols): string
    {
        if ($this->stageDurations === []) {
            return '';
        }
        // The label owns its 15 cells first (2 leading + 'elapsed NNN.NNs '),
        // the plot takes what the viewport leaves up to its ideal 40 — a
        // clamp that clamps: at 40 cols the plot renders 25 cells, not the
        // old floor-inflated 10-on-a-20-col-screen row that never fit
        // anywhere (php-dup-finder-once-070).
        $elapsedLabel = sprintf('elapsed %5.2fs ', microtime(true) - $this->startedAt);
        if ($cols < 2 + ViewState::displayWidth($elapsedLabel) + 1) {
            return ''; // label + one plot cell cannot coexist on this row
        }
        $labelClipped = ViewState::clipToCells($elapsedLabel, ViewState::regionBudget($cols, 2, PHP_INT_MAX));
        $plotWidth    = ViewState::regionBudget($cols, 2 + ViewState::displayWidth($labelClipped), 40);
        $sparkline    = Sparkline::new($this->stageDurations, $plotWidth);
        return '  ' . $this->theme->muted->render($labelClipped) . $sparkline->view() . "\n";
    }

    private function paneFor(Stage $stage): string
    {
        $focused = $this->viewState->focusedStage() === $stage;
        $marker  = $focused ? $this->theme->accent->render('▸ ') : '  ';
        $label   = $stage->label();

        $kindFilter = $this->state->config->allowedKinds === []
            ? ''
            : "\nfiltering: " . implode(', ', $this->state->config->allowedKinds);

        $body = match ($stage) {
            Stage::Scanning => sprintf(
                "%s\n%d paths%s",
                CountPhrase::files($this->state->totalFiles),
                count($this->state->config->paths),
                $kindFilter,
            ),
            Stage::Preprocessing => sprintf(
                "%d blocks\n%d reused · %d processed\n%d parse errors",
                count($this->state->blocks),
                $this->state->reusedFiles,
                $this->state->processedFiles,
                $this->state->parseErrors,
            ),
            Stage::Clustering => $this->state->candidatePairs > 0
                ? sprintf(
                    "%d clusters\n%d / %d pairs\n%.2fs",
                    count($this->state->clusters),
                    $this->state->scoredPairs,
                    $this->state->candidatePairs,
                    $this->state->timings['cluster'] ?? 0.0,
                )
                : sprintf(
                    "%d clusters\n%.2fs",
                    count($this->state->clusters),
                    $this->state->timings['cluster'] ?? 0.0,
                ),
            Stage::Refactoring => $this->refactorPaneBody(),
            default => '',
        };

        $debugSection = '';
        if ($focused && $this->state->debugIndex > 0) {
            $debugLines = [];
            $count = min(5, $this->state->debugIndex);
            for ($i = 0; $i < $count; $i++) {
                $idx = ($this->state->debugIndex - 1 - $i + PipelineState::DEBUG_BUFFER_SIZE) % PipelineState::DEBUG_BUFFER_SIZE;
                if (isset($this->state->debugMessages[$idx])) {
                    $debugLines[] = $this->state->debugMessages[$idx];
                }
            }
            if ($debugLines !== []) {
                $debugSection = "\n" . $this->theme->muted->render('debug:') . "\n" . implode("\n", array_map(
                    fn(string $msg) => '  ' . $this->theme->muted->render($msg),
                    $debugLines
                ));
            }
        }

        return $marker . $this->theme->info->render($label) . "\n" . $body . $debugSection;
    }

    /**
     * Refactor pane body — switches between live "X / Y refactored" while the
     * refactor stage is running and a static final count once we've moved on
     * (Ranker can filter clusters in ReportStage, so the live numerator can
     * exceed the post-report cluster count otherwise).
     */
    private function refactorPaneBody(): string
    {
        $refactored = $this->state->refactoredClusters;
        $duration   = $this->state->timings['refactor'] ?? 0.0;
        $stageIdx   = $this->state->stage->index();
        $refactorIdx = Stage::Refactoring->index();
        $isLiveOrEarlier = !$this->viewState->analysisComplete && $stageIdx <= $refactorIdx;
        if ($isLiveOrEarlier) {
            $total = count($this->state->clusters);
            return sprintf("%d / %d refactored\n%.2fs", $refactored, $total, $duration);
        }
        return sprintf("%d refactored\n%.2fs", $refactored, $duration);
    }

    private function renderDetail(int $cols): string
    {
        $id = $this->viewState->detailClusterId;
        foreach ($this->state->clusters as $c) {
            if ($c->id === $id) {
                // The signature is composed from SCANNED source (member
                // names, parameter lists): it crosses the display door and
                // is clipped in cells like every other region — escape
                // first, then measure (php-dup-finder-once-070,
                // once-024's boundary contract).
                $sig      = $c->signature ?? '<no signature>';
                $head     = ViewState::clipToCells(ViewState::displaySafe(sprintf(
                    '  Cluster %s — %d members, similarity %.2f, impact %d',
                    $c->id,
                    count($c->members),
                    $c->similarity,
                    $c->impact,
                )), $cols);
                $sigCells = max(1, $cols - 2);
                $sigLines = [];
                foreach (explode("\n", $sig) as $sigLine) {
                    // Outer clip covers the indent at sub-3-col viewports,
                    // where a fixed 2-space pad is itself an overflow row.
                    $sigLines[] = ViewState::clipToCells(
                        "  " . ViewState::displayLabel($sigLine, $sigCells),
                        $cols,
                    );
                }
                return $head . "\n" . implode("\n", $sigLines);
            }
        }
        return ViewState::clipToCells('  (cluster not found)', $cols);
    }
}
