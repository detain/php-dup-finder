<?php
declare(strict_types=1);

namespace Phpdup\Tui;

use Phpdup\Pipeline\Stage;

/**
 * UI-only state for the phpdup TUI.
 *
 * Kept separate from {@see \Phpdup\Pipeline\PipelineState} so analysis state
 * is unaware of any rendering concerns and the renderer doesn't carry domain data.
 */
final class ViewState
{
    public const SORT_IMPACT     = 'impact';
    public const SORT_SIMILARITY = 'similarity';
    public const SORT_NAME       = 'name';

    /** Index into the four pipeline stage panes (Scanning/Preprocessing/Clustering/Refactoring). */
    public int $focusedPaneIndex = 0;

    /** ID of cluster shown in expanded detail view, or null when closed. */
    public ?string $detailClusterId = null;

    public string $sortMode = self::SORT_IMPACT;

    public bool $helpExpanded = false;

    /** @var list<string> One-line toast queue; renderer is responsible for evicting expired entries. */
    public array $toastQueue = [];

    public int $cols = 80;
    public int $rows = 24;

    public bool $analysisComplete = false;

    /** @return list<Stage> The four stages users can focus, in display order. */
    public static function focusablePanes(): array
    {
        return [Stage::Scanning, Stage::Preprocessing, Stage::Clustering, Stage::Refactoring];
    }

    public function focusedStage(): Stage
    {
        $panes = self::focusablePanes();
        return $panes[$this->focusedPaneIndex % count($panes)];
    }

    public function cyclePaneFocus(int $delta): void
    {
        $count = count(self::focusablePanes());
        $this->focusedPaneIndex = (($this->focusedPaneIndex + $delta) % $count + $count) % $count;
    }

    public function cycleSortMode(): void
    {
        $this->sortMode = match ($this->sortMode) {
            self::SORT_IMPACT     => self::SORT_SIMILARITY,
            self::SORT_SIMILARITY => self::SORT_NAME,
            default               => self::SORT_IMPACT,
        };
    }
}
