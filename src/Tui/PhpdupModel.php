<?php
declare(strict_types=1);

namespace Phpdup\Tui;

use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\Stage;
use SugarCraft\Bits\Spinner\Spinner;
use SugarCraft\Bits\Spinner\TickMsg as SpinnerTickMsg;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\QuitMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
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
final class PhpdupModel implements Model
{
    private Spinner $spinner;

    public function __construct(
        public PipelineState $state,
        public ViewState $viewState,
        private readonly Theme $theme,
    ) {
        $this->spinner = Spinner::new();
    }

    public function init(): \Closure
    {
        return Cmd::batch($this->spinner->tick());
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
        return [$this, null];
    }

    public function view(): View
    {
        return new View(body: $this->renderBody());
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
            KeyType::Left  => [$this, null],
            KeyType::Right => [$this, null],
            KeyType::Enter => $this->mutate(fn() => $this->openDetail()),
            KeyType::Escape => $this->mutate(fn() => $this->viewState->detailClusterId = null),
            default => [$this, null],
        };
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
            : "  " . $this->theme->muted->render('Tip: ↑/↓ focus pane · Enter detail · t sort · h help · q quit');

        $help = $this->viewState->helpExpanded ? "\n  " . $this->theme->muted->render(
            'Keys: ↑/↓ cycle focused pane · Enter expand cluster · Esc dismiss · t sort (impact/similarity/name) · h toggle help · Ctrl+C / q quit'
        ) : '';

        return $banner . "\n\n"
             . $panes->render($cols, max(8, $rows - 6)) . "\n"
             . $detail
             . $help;
    }

    private function paneFor(Stage $stage): string
    {
        $focused = $this->viewState->focusedStage() === $stage;
        $marker  = $focused ? $this->theme->accent->render('▸ ') : '  ';
        $label   = $stage->label();

        $body = match ($stage) {
            Stage::Scanning => sprintf("%d files\n%d paths", $this->state->totalFiles, count($this->state->config->paths)),
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
