<?php
declare(strict_types=1);

namespace Phpdup\Tui;

use Phpdup\Pipeline\Stage;
use Phpdup\Reporting\ClusterSort;
use Phpdup\Util\ControlText;
use SugarCraft\Core\Util\Width;

/**
 * UI-only state for the phpdup TUI.
 *
 * Kept separate from {@see \Phpdup\Pipeline\PipelineState} so analysis state
 * is unaware of any rendering concerns and the renderer doesn't carry domain data.
 *
 * This class is also the single display-string boundary for the TUI: every
 * scanned-derived label, toast or excerpt entering a terminal cell passes
 * {@see self::displayLabel()}, which consumes the shared
 * {@see ControlText} venue (php-dup-finder-once-024, ViewState leg).
 *
 * All cell math runs through sugar-core's {@see Width} util — the same
 * grapheme-cluster, East-Asian-wide, emoji-aware measure the rest of the
 * SugarCraft stack (and sugar-flexbox itself) lays out with — so the model's
 * budgets and the renderer's painting agree on what a "column" is
 * (php-dup-finder-once-070, ViewState leg).
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

    /**
     * The sort specification for the current view state — the single door
     * where TUI sort state meets cluster order. PhpdupModel's detail
     * navigation ({@see PhpdupModel::sortedClusters()}) is its production
     * consumer: the `t`/`T` keys re-shape the order Enter/←/→ walk, which
     * is what makes the advertised sort affordance real instead of dead
     * (php-dup-finder-once-071).
     */
    public function clusterSort(): ClusterSort
    {
        return new ClusterSort($this->sortMode, $this->sortDirection);
    }

    /**
     * Every C0 control byte plus DEL — the byte class a terminal cell must
     * never carry raw (php-dup-finder-once-024). Superset of
     * {@see ControlText::FORGE_BYTES}: a device is driven by far more than
     * the four stream-forging bytes — BEL (0x07) rings the bell, any CSI
     * fragment relocates the cursor, and a raw TAB makes column math a lie.
     */
    private const C0_DEL_BYTES =
        "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0A\x0B\x0C\x0D\x0E\x0F" .
        "\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F\x7F";

    /** Ellipsis closing a clipped display run (one cell under {@see Width}). */
    private const ELLIPSIS = '…';

    /** @var array<string,string>|null single control byte => caret notation, derived once from C0_DEL_BYTES */
    private static ?array $caretMap = null;

    /**
     * Display scrub for an untrusted string entering a terminal cell.
     *
     * The four stream-forging bytes go through the central
     * {@see ControlText} substitute arm — the same rendering output-S19's
     * CLI boundary chose, so the same hostile byte looks identical in the
     * report and on screen. The remaining C0/DEL bytes are the terminal-only
     * residue ControlText deliberately excuses for line-oriented formats;
     * they render as printable caret notation (`^G` for BEL, `^I` for TAB)
     * so an attack stays visible instead of merely inert. Clean text passes
     * through byte-identical.
     */
    public static function displaySafe(string $text): string
    {
        if (strcspn($text, self::C0_DEL_BYTES) === strlen($text)) {
            return $text;
        }
        return strtr(ControlText::substitute($text), self::caretMap());
    }

    /**
     * Display-cell width of already-safe text — the canonical count.
     *
     * Delegates to sugar-core's {@see Width::string()}, the grapheme-cluster
     * measure the whole SugarCraft stack paints with: CJK 2 cells, combining
     * marks 0, emoji ZWJ sequences one cluster. `mb_strwidth` (per-codepoint)
     * and `mb_strlen` (codepoints) disagree with the terminal on all three and
     * are the desync this door closes (php-dup-finder-once-070).
     */
    public static function displayWidth(string $displayText): int
    {
        return Width::string($displayText);
    }

    /**
     * Cell budget for one region inside a viewport — a clamp that CLAMPS.
     *
     * The layout bug class this replaces (once-070): guards written as
     * `max(60, $cols)` inflate the layout past a small terminal — a floor
     * where a ceiling was meant, so 40-col screens got 60-col rows that the
     * terminal wrapped into border-tear. The honest shape is min(): never
     * more than the viewport minus the region's fixed overhead, never more
     * than the region's ideal, and never less than one cell (a 1-cell run
     * still renders a visible ellipsis instead of vanishing).
     */
    public static function regionBudget(int $viewportCells, int $overheadCells, int $idealCells): int
    {
        $usable = $viewportCells - $overheadCells;
        return max(1, min($usable, $idealCells));
    }

    /**
     * Clip an already display-safe string to a display-cell budget.
     *
     * The caller must pass text that went through {@see self::displaySafe()}
     * (caret notation is 2 cells long); this is what lets the renderer escape
     * first and then lay out, instead of slicing escape bytes mid-notation —
     * the compounding bug class the UPSTREAM scout flagged (UP-04). Result
     * occupies at most $maxCells columns; a clipped run ends in one ellipsis
     * cell. Cuts land on grapheme-cluster boundaries: a wide glyph is never
     * split and a combining mark never strands from its base
     * (php-dup-finder-once-070 — widths count in cells, not codepoints).
     */
    public static function clipToCells(string $displayText, int $maxCells): string
    {
        if ($maxCells <= 0) {
            return '';
        }
        if (Width::string($displayText) <= $maxCells) {
            return $displayText;
        }
        if ($maxCells === 1) {
            return self::ELLIPSIS;
        }

        $budget = $maxCells - Width::string(self::ELLIPSIS);
        $used   = 0;
        $out    = '';
        $length = strlen($displayText);
        for ($i = 0; $i < $length;) {
            $cluster = Width::nextCluster($displayText, $i);
            $cells   = Width::string($cluster);
            if ($used + $cells > $budget) {
                break;
            }
            $out  .= $cluster;
            $used += $cells;
            $i    += strlen($cluster);
        }
        return $out . self::ELLIPSIS;
    }

    /**
     * Full boundary for a scanned-derived label about to occupy a terminal
     * cell: escape first, then measure — width math always runs on
     * display-cells AFTER escaping (php-dup-finder-once-024 acceptance).
     */
    public static function displayLabel(string $text, int $maxCells): string
    {
        return self::clipToCells(self::displaySafe($text), $maxCells);
    }

    /**
     * Guarded enqueue for the one-line toast queue: the message is
     * display-sanitized and clipped to the current viewport width on the way
     * in, so a scanned path or exception text can never drive the terminal
     * later, at paint time. Direct writes to {@see self::$toastQueue} bypass
     * this door and are the residual the PhpdupModel wiring closes.
     */
    public function pushToast(string $message): void
    {
        $this->toastQueue[] = self::displayLabel($message, max(1, $this->cols));
    }

    /**
     * @return array<string,string> single-byte key => '^X' caret notation
     *                              for every byte in C0_DEL_BYTES (string
     *                              keys: strtr casts int keys to decimal,
     *                              which would replace digits instead)
     */
    private static function caretMap(): array
    {
        if (self::$caretMap !== null) {
            return self::$caretMap;
        }
        $map = [];
        foreach (mb_str_split(self::C0_DEL_BYTES, 1, 'UTF-8') as $byte) {
            $ord = ord($byte);
            // PS/ISO 646 caret convention: control b (0x00-0x1F) shows as
            // '@'..'_' after '^'; DEL (0x7F) folds to '^?'.
            $map[$byte] = '^' . chr($ord === 0x7F ? 0x3F : $ord + 0x40);
        }
        return self::$caretMap = $map;
    }
}
