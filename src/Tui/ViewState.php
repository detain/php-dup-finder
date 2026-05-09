<?php
declare(strict_types=1);

namespace Phpdup\Tui;

use Phpdup\Pipeline\Stage;
use Phpdup\Reporting\ClusterSort;

/**
 * UI-only state for the phpdup TUI.
 *
 * Kept separate from {@see \Phpdup\Pipeline\PipelineState} so analysis state
 * is unaware of any rendering concerns and the renderer doesn't carry domain data.
 */
final class ViewState
{
    /**
     * Sort keys the user can cycle through with the `t` key. Aligned with
     * {@see ClusterSort::ALL_KEYS} so the TUI uses the same vocabulary as
     * the CLI `--sort` flag.
     */
    public const SORT_CYCLE = [
        ClusterSort::KEY_IMPACT,
        ClusterSort::KEY_MEMBERS,
        ClusterSort::KEY_BLOCK_SIZE,
        ClusterSort::KEY_LINES,
        ClusterSort::KEY_SIMILARITY,
        ClusterSort::KEY_CONFIDENCE,
        ClusterSort::KEY_NAME,
    ];

    // Backwards-compatible aliases — kept so existing tests / callers
    // referring to ViewState::SORT_IMPACT etc. keep working.
    public const SORT_IMPACT     = ClusterSort::KEY_IMPACT;
    public const SORT_SIMILARITY = ClusterSort::KEY_SIMILARITY;
    public const SORT_NAME       = ClusterSort::KEY_NAME;
    public const SORT_MEMBERS    = ClusterSort::KEY_MEMBERS;

    /** Index into the four pipeline stage panes (Scanning/Preprocessing/Clustering/Refactoring). */
    public int $focusedPaneIndex = 0;

    /** ID of cluster shown in expanded detail view, or null when closed. */
    public ?string $detailClusterId = null;

    public string $sortMode = ClusterSort::KEY_IMPACT;

    /** Direction toggled by `T` (shift-t). */
    public string $sortDirection = ClusterSort::DIRECTION_DESC;

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

    /**
     * Cycle to the next sort key in {@see SORT_CYCLE}. Direction is unchanged.
     * Defaults back to the first key when the current mode is somehow off-list.
     */
    public function cycleSortMode(): void
    {
        $idx = array_search($this->sortMode, self::SORT_CYCLE, true);
        if ($idx === false) {
            $this->sortMode = self::SORT_CYCLE[0];
            return;
        }
        $this->sortMode = self::SORT_CYCLE[($idx + 1) % count(self::SORT_CYCLE)];
    }

    /** Toggle between asc and desc — bound to `T` (shift-t) in PhpdupModel. */
    public function toggleSortDirection(): void
    {
        $this->sortDirection = $this->sortDirection === ClusterSort::DIRECTION_DESC
            ? ClusterSort::DIRECTION_ASC
            : ClusterSort::DIRECTION_DESC;
    }

    public function clusterSort(): ClusterSort
    {
        return new ClusterSort($this->sortMode, $this->sortDirection);
    }
}
