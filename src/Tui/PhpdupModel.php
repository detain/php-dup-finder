<?php
declare(strict_types=1);

namespace Phpdup\Tui;

use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\ProgressListener;
use Phpdup\Pipeline\Stage;
use Phpdup\Tui\Msg\RestartPipelineMsg;
use Phpdup\Tui\Msg\StagePumpedMsg;
use SugarCraft\Bits\Spinner\Spinner;
use SugarCraft\Bits\Spinner\TickMsg as SpinnerTickMsg;
use SugarCraft\Charts\Sparkline\Sparkline;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\Progress;
use SugarCraft\Core\ProgressBarState;
use SugarCraft\Core\View;
use SugarCraft\Kit\Theme;
use SugarCraft\Stickers\Flex\FlexBox;
use SugarCraft\Stickers\Flex\FlexItem;

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

    /** Cooperative pipeline iterator — null when the model isn't driving live (Phase 2 mode). */
    private ?\Generator $iterator = null;

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

    public function __construct(
        public PipelineState $state,
        public ViewState $viewState,
        private readonly Theme $theme,
        ?\Closure $iteratorFactory = null,
    ) {
        $this->spinner         = Spinner::new();
        $this->startedAt       = microtime(true);
        $this->iteratorFactory = $iteratorFactory;
    }

    public function onStageStart(Stage $stage): void
    {
        $this->state->stage = $stage;
    }

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

    public function onFileScanned(int $scanned, int $total): void
    {
        // PipelineState already tracks this; surface to the dashboard via state.
        $this->state->scannedFiles = $scanned;
        $this->state->totalFiles   = $total;
    }

    public function onFilePreprocessed(int $processed, int $reused, int $errors): void {}
    public function onPairScored(int $scored, int $total): void {}
    public function onClusterRefactored(int $refactored, int $total): void {}

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
            $this->viewState->toastQueue[] = 'Pipeline error: ' . $e->getMessage();
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
        $this->iterator        = $gen;
        $this->iteratorStarted = false;
        $this->state           = $state;
        $this->stageDurations  = [];
        $this->startedAt       = microtime(true);
    }

    private function scheduleNextPump(): \Closure
    {
        return Cmd::tick(0.0, static fn() => new StagePumpedMsg());
    }

    public function view(): View
    {
        return new View(
            body: $this->renderBody(),
            progressBar: $this->taskbarProgress(),
        );
    }

    private function taskbarProgress(): Progress
    {
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
        $cols = max(60, $this->viewState->cols);
        $rows = max(20, $this->viewState->rows);

        $banner = $this->theme->accent->render(sprintf(
            ' phpdup — %d files · %d blocks · %d clusters%s',
            count($this->state->files),
            count($this->state->blocks),
            count($this->state->clusters),
            $this->viewState->analysisComplete ? '' : '   ' . $this->spinner->view(),
        ));

        $panes = FlexBox::row(
            FlexItem::new($this->paneFor(Stage::Scanning))->withRatio(1),
            FlexItem::new($this->paneFor(Stage::Preprocessing))->withRatio(1),
            FlexItem::new($this->paneFor(Stage::Clustering))->withRatio(1),
            FlexItem::new($this->paneFor(Stage::Refactoring))->withRatio(1),
        )->withGap(1);

        $detail = $this->viewState->detailClusterId !== null
            ? $this->renderDetail()
            : "  " . $this->theme->muted->render(sprintf(
                'Tip: ↑/↓ focus · Enter detail · t sort (%s) · T flip dir · h help · q quit',
                $this->viewState->sortMode . ':' . $this->viewState->sortDirection,
            ));

        $help = $this->viewState->helpExpanded ? "\n  " . $this->theme->muted->render(
            'Keys: ↑/↓ cycle focused pane · ←/→ navigate detail · Enter expand · Esc dismiss · t cycle sort (impact/members/block-size/lines/similarity/confidence/name) · T flip asc/desc · h toggle help · Ctrl+C / q quit'
        ) : '';

        return $banner . "\n\n"
             . $panes->render($cols, max(8, $rows - 8)) . "\n"
             . $this->renderTimingsSparkline($cols)
             . $detail
             . $help;
    }

    private function renderTimingsSparkline(int $cols): string
    {
        if ($this->stageDurations === []) {
            return '';
        }
        $width    = max(10, min(40, $cols - 30));
        $sparkline = Sparkline::new($this->stageDurations, $width);
        $elapsed   = microtime(true) - $this->startedAt;
        return '  ' . $this->theme->muted->render(sprintf('elapsed %5.2fs ', $elapsed))
             . $sparkline->view() . "\n";
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
                "%d files\n%d paths%s",
                $this->state->totalFiles,
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
            Stage::Clustering => sprintf(
                "%d clusters\n%.2fs",
                count($this->state->clusters),
                $this->state->timings['cluster'] ?? 0.0,
            ),
            Stage::Refactoring => sprintf(
                "%d refactored\n%.2fs",
                count($this->state->clusters),
                $this->state->timings['refactor'] ?? 0.0,
            ),
            default => '',
        };

        return $marker . $this->theme->info->render($label) . "\n" . $body;
    }

    private function renderDetail(): string
    {
        $id = $this->viewState->detailClusterId;
        foreach ($this->state->clusters as $c) {
            if ($c->id === $id) {
                $sig = $c->signature ?? '<no signature>';
                return sprintf(
                    "  Cluster %s — %d members, similarity %.2f, impact %d\n%s",
                    $c->id,
                    count($c->members),
                    $c->similarity,
                    $c->impact,
                    $sig,
                );
            }
        }
        return '  (cluster not found)';
    }
}
