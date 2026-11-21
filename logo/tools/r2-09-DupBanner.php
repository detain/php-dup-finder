<?php

declare(strict_types=1);

namespace Phpdup\Logo;

/**
 * Terminal colour-capability tiers for DupBanner.
 */
enum DupColorMode: string
{
    case Truecolor = 'truecolor';
    case Xterm256  = 'xterm256';
    case Plain     = 'plain';
}

/**
 * DupBanner — "Diff Fold" animated truecolor ANSI logo for phpdup.
 *
 * The logo plays as a unified diff:
 *   scroll (ticks 0-19)   a dim hunk header plus five guttered rows stream
 *                         in on tinted backgrounds — three identical red
 *                         `- phpdup` lines, then green `+ phpdup` and
 *                         `+ find the diff.` lines.
 *   fold   (ticks 20-34)  the identical `-` rows fold upward one after the
 *                         other into the top row; the wordmark `+` line
 *                         hops up into the vacated slot; on arrival the red
 *                         body morphs to cyan and `( ... )` materialises —
 *                         the fold reads as the call `phpdup(...)`.
 *   dissolve (35-44)      the +/- markers dissolve, the greens settle to
 *                         neutral grey, every line glides to centre and the
 *                         logo holds: cyan `phpdup(...)`, bold neutral
 *                         `phpdup`, dim tagline `find the diff.`.
 *
 * Concept timing 0.8s + 0.6s + 0.4s = 1.8s compressed x5/6 into the 1.5s
 * budget: 0.667s + 0.5s + 0.333s = 45 ticks at 30 fps. One-shot, ends HOLD.
 *
 * Determinism: frame() is a pure function of (tick, mode). All randomness is
 * a seeded splitmix of (salt, tick, position) — no wall-clock, no global
 * RNG state — so frame 0 and the hold frame are byte-reproducible.
 *
 * Constraints honoured: <=7 rows x <=56 cols, <=45 ticks, zero escapes in
 * the plain fallback, truecolor -> xterm256 -> plain chain.
 *
 *
 */
final class DupBanner
{
    public const ROWS  = 7;
    public const COLS  = 56;
    public const TICKS = 45;  // 45 ticks @ 30 fps = exactly 1.5 s, then hold
    public const FPS   = 30;

    // ---- concept palette --------------------------------------------------
    private const ADD_TEXT = [22, 163, 74];   // #16A34A
    private const ADD_BG   = [5, 46, 22];     // #052E16
    private const DEL_TEXT = [220, 38, 38];   // #DC2626
    private const DEL_BG   = [42, 10, 10];    // #2A0A0A
    private const CALL     = [6, 182, 212];   // #06B6D4
    private const NEUTRAL  = [229, 231, 235]; // #E5E7EB
    private const DIM      = [107, 114, 128]; // hunk header / tagline grey
    private const PAGE_BG  = [13, 17, 23];    // dark terminal ground

    // ---- static-text rows -------------------------------------------------
    private const HEADER    = '@@ -1,3 +1,2 @@';
    private const DUPLICATE = 'phpdup';
    private const WORDMARK  = 'phpdup';
    private const TAGLINE   = 'find the diff.';
    private const CALL_TEXT = 'phpdup(...)';

    private const GUTTER  = 2;   // marker column of the diff band
    private const BODY    = 4;   // first body column
    private const BAND_TO = 53;  // tinted band spans cols 2..53 while active

    // ---- phase windows (tick ranges) ----------------------------------------
    private const FOLD_ROW3       = [20, 25]; // '- phpdup' folds into row 2
    private const FOLD_ROW2       = [26, 31]; // '- phpdup' folds into row 1
    private const ADD_RISE_TICK   = 26;       // wordmark row hops 4 -> 3
    private const CALL_MERGE      = [31, 34]; // red->cyan morph + "(...)" lands
    private const MARKER_DISSOLVE = [35, 38]; // surviving +/- fade as lines centre
    private const CENTER_SLIDE    = [35, 43]; // lines glide left-to-centre
    private const WORDMARK_TINT   = [39, 43]; // green -> neutral bold
    private const TAGLINE_TINT    = [38, 42]; // green -> dim grey
    private const HEADER_FADE     = [42, 44]; // hunk header dissolves

    /** stream-in ticks of diff rows 1..5 (three '-', then two '+') */
    private const ENTER = [2, 5, 8, 11, 14];

    /**
     * Pure: the full 7x56 canvas for one tick — rows joined "\n", no
     * trailing newline, no cursor moves. Deterministic per (tick, mode).
     */
    public static function frame(int $tick, DupColorMode $mode = DupColorMode::Truecolor): string
    {
        if ($tick < 0) {
            $tick = 0;
        }
        if ($tick > self::TICKS - 1) {
            $tick = self::TICKS - 1;
        }

        $grid = self::blankGrid();
        self::paintHeader($grid, $tick);
        self::paintDuplicateRow($grid, $tick, 1, null);       // survives, becomes the call
        self::paintDuplicateRow($grid, $tick, 2, self::FOLD_ROW2);
        self::paintDuplicateRow($grid, $tick, 3, self::FOLD_ROW3);
        self::paintAddRow($grid, $tick, self::ADD_RISE_TICK, 4, self::WORDMARK, self::WORDMARK_TINT, self::NEUTRAL, true, 17);
        self::paintAddRow($grid, $tick, -1, 5, self::TAGLINE, self::TAGLINE_TINT, self::DIM, false, 23);
        self::paintCallRow($grid, $tick);

        return self::serialize($grid, $mode);
    }

    public static function finalTick(): int
    {
        return self::TICKS - 1;
    }

    public static function finalFrame(DupColorMode $mode = DupColorMode::Truecolor): string
    {
        return self::frame(self::finalTick(), $mode);
    }

    /** Plain-text static fallback: the hold frame, zero escape codes. */
    public static function staticText(): string
    {
        $rows = array_map('rtrim', explode("\n", self::finalFrame(DupColorMode::Plain)));
        return trim(implode("\n", $rows), "\n") . "\n";
    }

    /**
     * The complete one-shot byte stream: every tick repainted in place via
     * `\e[<ROWS-1>A\r` cursor-up framing, ending on the hold frame. The
     * plain mode degrades to the static frame only — zero escapes.
     */
    public static function play(DupColorMode $mode = DupColorMode::Truecolor): string
    {
        if ($mode === DupColorMode::Plain) {
            return self::staticText();
        }

        $frames = [];
        for ($tick = 0; $tick < self::TICKS; $tick++) {
            $frames[] = self::frame($tick, $mode);
        }

        $up = "\e[" . (self::ROWS - 1) . "A\r";
        return "\e[?25l" . implode($up, $frames) . "\e[0m\e[?25h";
    }

    /**
     * Capability pick: NO_COLOR or TERM=dumb force plain; COLORTERM
     * truecolor|24bit unlocks truecolor; TERM names with 256-colour support
     * use the palette; anything else falls back to plain.
     */
    public static function detectMode(): DupColorMode
    {
        $env = static function (string $key): string {
            $value = getenv($key);
            return $value === false ? '' : $value;
        };

        if ($env('NO_COLOR') !== '' || $env('TERM') === 'dumb') {
            return DupColorMode::Plain;
        }
        if (preg_match('/truecolor|24bit/i', $env('COLORTERM')) === 1) {
            return DupColorMode::Truecolor;
        }
        if (preg_match('/256color/i', $env('TERM')) === 1) {
            return DupColorMode::Xterm256;
        }

        return DupColorMode::Plain;
    }

    // =======================================================================
    //  Cell painting — grid cells are trusted once written; every guard
    //  lives at the put() boundary.
    // =======================================================================

    /** @return array<int, array<int, array{ch: string, fg: array{0:int,1:int,2:int}|null, bg: array{0:int,1:int,2:int}|null, bold: bool}>> */
    private static function blankGrid(): array
    {
        $grid = [];
        for ($row = 0; $row < self::ROWS; $row++) {
            for ($col = 0; $col < self::COLS; $col++) {
                $grid[$row][$col] = ['ch' => ' ', 'fg' => null, 'bg' => null, 'bold' => false];
            }
        }
        return $grid;
    }

    /**
     * @param array<int, array<int, array{ch: string, fg: array{0:int,1:int,2:int}|null, bg: array{0:int,1:int,2:int}|null, bold: bool}>> $grid
     * @param array{0:int,1:int,2:int}|null $fg
     * @param array{0:int,1:int,2:int}|null $bg
     */
    private static function put(array &$grid, int $row, int $col, string $ch, ?array $fg, ?array $bg, bool $bold): void
    {
        if ($row < 0 || $row >= self::ROWS || $col < 0 || $col >= self::COLS) {
            return;
        }
        $grid[$row][$col] = ['ch' => $ch, 'fg' => $fg, 'bg' => $bg, 'bold' => $bold];
    }

    /**
     * @param array<int, array<int, array{ch: string, fg: array{0:int,1:int,2:int}|null, bg: array{0:int,1:int,2:int}|null, bold: bool}>> $grid
     * @param array{0:int,1:int,2:int}|null $bg
     */
    private static function fillBand(array &$grid, int $row, int $col0, int $col1, ?array $bg): void
    {
        if ($bg === null) {
            return;
        }
        for ($col = max(0, $col0); $col <= min(self::COLS - 1, $col1); $col++) {
            self::put($grid, $row, $col, ' ', null, $bg, false);
        }
    }

    /**
     * Reveal the first $count chars of $text at ($row, $col); each cell is
     * tinted through $colorAt and sits on $bg (the band shows through text).
     *
     * @param array<int, array<int, array{ch: string, fg: array{0:int,1:int,2:int}|null, bg: array{0:int,1:int,2:int}|null, bold: bool}>>                   $grid
     * @param callable(int): array{0:int,1:int,2:int}     $colorAt
     * @param array{0:int,1:int,2:int}|null               $bg
     */
    private static function drawText(array &$grid, int $row, int $col, string $text, int $count, callable $colorAt, ?array $bg, bool $bold): void
    {
        $chars = str_split($text);
        $limit = min($count, count($chars));
        for ($index = 0; $index < $limit; $index++) {
            self::put($grid, $row, $col + $index, $chars[$index], $colorAt($index), $bg, $bold);
        }
    }

    /**
     * Smoothstep progress of a tick window, clamped to [0, 1].
     *
     * @param array{0:int,1:int} $window
     */
    private static function phase(int $tick, array $window): float
    {
        [$from, $to] = $window;
        if ($tick <= $from) {
            return 0.0;
        }
        if ($tick >= $to) {
            return 1.0;
        }
        $p = ($tick - $from) / max(1, $to - $from);
        return $p * $p * (3 - 2 * $p);
    }

    /** Deterministic seeded noise in [0, 1) — stable across processes. */
    private static function noise(int $salt, int $tick, int $pos): float
    {
        $x = (($salt * 2654435761) + ($tick * 97531) + ($pos * 340573321)) & 0xFFFFFFFF;
        $x ^= $x >> 13;
        $x = (($x * 1274126177) & 0xFFFFFFFF) ^ ($x >> 16);
        $x ^= $x >> 11;
        return ($x & 0x7FFFFFFF) / 2147483648.0;
    }

    /**
     * @param array{0:int,1:int,2:int} $a
     * @param array{0:int,1:int,2:int} $b
     * @return array{0:int,1:int,2:int}
     */
    private static function mix(array $a, array $b, float $t): array
    {
        $t = max(0.0, min(1.0, $t));
        return [
            (int) round($a[0] + ($b[0] - $a[0]) * $t),
            (int) round($a[1] + ($b[1] - $a[1]) * $t),
            (int) round($a[2] + ($b[2] - $a[2]) * $t),
        ];
    }

    /** Centre-start column of a hold-frame line. */
    private static function centreOf(string $text): int
    {
        return intdiv(self::COLS - strlen($text), 2);
    }

    /** Eased glide from the gutter band to the centred hold position. */
    private static function slideCol(int $tick, string $text, int $salt, int $from = self::BODY): int
    {
        if ($tick < self::CENTER_SLIDE[0]) {
            return $from;
        }
        $p      = self::phase($tick, self::CENTER_SLIDE);
        $target = self::centreOf($text);
        $moved  = (int) round($p * ($target - $from));
        $lag    = $p < 1.0 && self::noise($salt, $tick, $moved) > 0.8 ? 1 : 0;
        return $from + $moved - $lag;
    }

    // ---------------------------------------------------------------- header

    /** @param array<int, array<int, array{ch: string, fg: array{0:int,1:int,2:int}|null, bg: array{0:int,1:int,2:int}|null, bold: bool}>> $grid */
    private static function paintHeader(array &$grid, int $tick): void
    {
        $p = self::phase($tick, self::HEADER_FADE);
        if ($p >= 1.0) {
            return;
        }
        if ($tick <= 1) {
            return; // frame 0 and 1: blank canvas (terminal clears)
        }

        $fg   = self::mix(self::DIM, self::PAGE_BG, $p);
        $col  = self::slideCol($tick, self::HEADER, 11, self::GUTTER);
        $count = min(strlen(self::HEADER), ($tick - 1) * 4);
        self::drawText($grid, 0, $col, self::HEADER, $count, static fn(int $i): array => $fg, null, false);
    }

    // -------------------------------------------------------- '- phpdup' rows

    /**
     * One of the three identical red `- phpdup` hunk lines. $fold is null for
     * the survivor (row 1) which never absorbs; otherwise the row drains
     * right-to-left across the window as it folds into the line above.
     *
     * @param array<int, array<int, array{ch: string, fg: array{0:int,1:int,2:int}|null, bg: array{0:int,1:int,2:int}|null, bold: bool}>>         $grid
     * @param array{0:int,1:int}|null  $fold
     */
    private static function paintDuplicateRow(array &$grid, int $tick, int $row, ?array $fold): void
    {
        $enter = self::ENTER[$row - 1];
        if ($tick < $enter) {
            return;
        }

        $absorb = $fold === null ? 0.0 : self::phase($tick, $fold);
        if ($fold !== null && $tick >= $fold[1]) {
            return; // fully folded into the row above
        }
        if ($fold === null && $tick >= self::CALL_MERGE[0]) {
            return; // the call row has taken over the slot
        }

        $band   = self::mix(self::DEL_BG, self::PAGE_BG, $absorb);
        $text   = self::mix(self::DEL_TEXT, self::DEL_BG, $absorb);
        $marker = self::mix(self::DEL_TEXT, self::PAGE_BG, $absorb);

        $bandTo = $absorb > 0.0
            ? self::BAND_TO - (int) round($absorb * (self::BAND_TO - self::GUTTER))
            : min(self::BAND_TO, self::GUTTER + ($tick - $enter) * 9);
        if ($bandTo >= self::GUTTER) {
            self::fillBand($grid, $row, self::GUTTER, $bandTo, $band);
        }

        $textCount = $absorb > 0.0
            ? self::shrinkCount(self::DUPLICATE, $absorb, $tick, $row)
            : min(strlen(self::DUPLICATE), max(0, ($tick - $enter) * 4 - 2));

        self::put($grid, $row, self::GUTTER, '-', $marker, $bandTo >= self::GUTTER ? $band : null, false);
        self::drawText($grid, $row, self::BODY, self::DUPLICATE, $textCount, static fn(int $i): array => $text, null, false);

        // the receiving line flashes as its donor lands (two ticks only)
        $donorFolds = $row === 2 ? self::FOLD_ROW3 : ($row === 1 ? self::FOLD_ROW2 : null);
        if ($donorFolds !== null && $tick >= $donorFolds[1] - 1 && $tick <= $donorFolds[1]) {
            self::drawText(
                $grid, $row, self::BODY, self::DUPLICATE, strlen(self::DUPLICATE),
                static fn(int $i): array => self::mix($text, [255, 255, 255], 0.22),
                $bandTo >= self::GUTTER ? $band : null, false
            );
        }
    }

    /** Right-to-left char drain with seeded per-char flicker. */
    private static function shrinkCount(string $text, float $absorb, int $tick, int $salt): int
    {
        $len     = strlen($text);
        $visible = (int) floor((1.0 - $absorb) * ($len + 1));
        if ($visible < $len && self::noise($salt, $tick, $visible) > 0.5) {
            $visible++; // flicker: one doomed char lingers a beat, seeded
        }
        return min($len, $visible);
    }

    // -------------------------------------------------------- '+ …' rows

    /**
     * A green '+' hunk line. $riseFrom > 0 means it hops one row up at that
     * tick (single-tick void mid-hop); -1 keeps it put.
     *
     * @param array<int, array<int, array{ch: string, fg: array{0:int,1:int,2:int}|null, bg: array{0:int,1:int,2:int}|null, bold: bool}>>   $grid
     * @param array{0:int,1:int} $tintWindow
     * @param array{0:int,1:int,2:int}     $tintColor
     */
    private static function paintAddRow(array &$grid, int $tick, int $riseFrom, int $row, string $body, array $tintWindow, array $tintColor, bool $boldAtEnd, int $salt): void
    {
        $enter = self::ENTER[$row - 1];
        if ($tick < $enter) {
            return;
        }
        if ($riseFrom > 0) {
            if ($tick === $riseFrom - 1) {
                return; // void tick: the line is mid-hop, not on either row
            }
            if ($tick >= $riseFrom) {
                $row -= 1;
            }
        }

        $dissolve = self::phase($tick, self::MARKER_DISSOLVE);
        $tint     = self::phase($tick, $tintWindow);
        $band     = self::mix(self::ADD_BG, self::PAGE_BG, $dissolve);
        $text     = self::mix(self::ADD_TEXT, $tintColor, $tint);
        $marker   = self::mix(self::ADD_TEXT, self::PAGE_BG, $dissolve);
        $bold     = $boldAtEnd && $tick >= self::CENTER_SLIDE[1];

        $bandTo = $dissolve > 0.0
            ? self::BAND_TO - (int) round($dissolve * (self::BAND_TO - self::GUTTER))
            : min(self::BAND_TO, self::GUTTER + ($tick - $enter) * 9);
        $bandActive = $dissolve < 1.0 && $bandTo >= self::GUTTER;
        if ($bandActive) {
            self::fillBand($grid, $row, self::GUTTER, $bandTo, $band);
        }

        $col       = self::slideCol($tick, $body, $salt);
        $textCount = min(strlen($body), max(0, ($tick - $enter) * 4 - 2));

        if ($dissolve < 1.0) {
            // marker rides just left of the body as the line centres, fading to page
            $markerCol = max(self::GUTTER, $col - (self::BODY - self::GUTTER));
            self::put($grid, $row, $markerCol, '+', $marker, $bandActive ? $band : null, false);
        }
        self::drawText($grid, $row, $col, $body, $textCount, static fn(int $i): array => $text, $bandActive ? $band : null, $bold);
    }

    // ------------------------------------------------------------- the call

    /**
     * Row 1 from CALL_MERGE onward: the survivor `- phpdup` (marker already
     * dropped) morphs red -> cyan while `( ... )` lands character by
     * character, then the whole call centres and bolds for the hold.
     *
     * @param array<int, array<int, array{ch: string, fg: array{0:int,1:int,2:int}|null, bg: array{0:int,1:int,2:int}|null, bold: bool}>> $grid
     */
    private static function paintCallRow(array &$grid, int $tick): void
    {
        if ($tick < self::CALL_MERGE[0]) {
            return;
        }

        $merge  = self::phase($tick, self::CALL_MERGE);
        $col    = self::slideCol($tick, self::CALL_TEXT, 7);
        $band   = self::mix(self::DEL_BG, self::PAGE_BG, $merge);
        $suffix = substr(self::CALL_TEXT, strlen(self::DUPLICATE)); // "(...)"
        $seen   = (int) round($merge * strlen($suffix) + self::noise(91, $tick, 2) * 0.999 * $merge);
        $count  = strlen(self::DUPLICATE) + min(strlen($suffix), max(0, $seen));

        if ($merge < 1.0) {
            $bandTo = self::BAND_TO - (int) round($merge * (self::BAND_TO - self::GUTTER));
            if ($bandTo >= self::GUTTER) {
                self::fillBand($grid, 1, self::GUTTER, $bandTo, $band);
            }
        }

        $bold = $tick >= self::CENTER_SLIDE[1];
        if ($tick <= self::CALL_MERGE[0] + 1) {
            // the final '-' marker ghosts out as the call lands
            $ghost = self::mix(self::DEL_TEXT, self::PAGE_BG, $tick - self::CALL_MERGE[0]);
            self::put($grid, 1, self::GUTTER, '-', $ghost, null, false);
        }
        self::drawText(
            $grid,
            1,
            $col,
            self::CALL_TEXT,
            $count,
            static fn(int $i): array => $i < strlen(self::DUPLICATE)
                ? self::mix(self::DEL_TEXT, self::CALL, $merge)
                : self::CALL,
            $merge < 1.0 ? $band : null,
            $bold
        );
    }

    // ================================================================= export

    /** @param array<int, array<int, array{ch: string, fg: array{0:int,1:int,2:int}|null, bg: array{0:int,1:int,2:int}|null, bold: bool}>> $grid */
    private static function serialize(array $grid, DupColorMode $mode): string
    {
        $out = [];
        foreach ($grid as $rowCells) {
            $line = '';
            $open = '';
            foreach ($rowCells as $cell) {
                $style = self::sgr($cell['fg'], $cell['bg'], $cell['bold'], $mode);
                if ($style !== $open) {
                    $line .= $style;
                    $open = $style;
                }
                $line .= $cell['ch'];
            }
            $out[] = $mode === DupColorMode::Plain ? rtrim($line, ' ') : $line . "\e[0m";
        }
        return implode("\n", $out);
    }

    /**
     * @param array{0:int,1:int,2:int}|null $fg
     * @param array{0:int,1:int,2:int}|null $bg
     */
    private static function sgr(?array $fg, ?array $bg, bool $bold, DupColorMode $mode): string
    {
        if ($mode === DupColorMode::Plain) {
            return '';
        }

        $prefix = $bold ? "\e[1m" : '';
        if ($mode === DupColorMode::Truecolor) {
            $f = $fg === null ? '' : sprintf("\e[38;2;%d;%d;%dm", $fg[0], $fg[1], $fg[2]);
            $b = $bg === null ? '' : sprintf("\e[48;2;%d;%d;%dm", $bg[0], $bg[1], $bg[2]);
            return $prefix . $f . $b;
        }

        $f = $fg === null ? '' : "\e[38;5;" . self::to256($fg) . 'm';
        $b = $bg === null ? '' : "\e[48;5;" . self::to256($bg) . 'm';
        return $prefix . $f . $b;
    }

    /**
     * Nearest xterm-256 slot via the 6x6x6 colour cube.
     *
     * @param array{0:int,1:int,2:int} $rgb
     */
    private static function to256(array $rgb): int
    {
        $levels = [0, 95, 135, 195, 235, 255];
        $best = static function (int $v) use ($levels): int {
            $pick = 0;
            foreach ($levels as $i => $level) {
                if (abs($v - $level) < abs($v - $levels[$pick])) {
                    $pick = $i;
                }
            }
            return $pick;
        };
        return 16 + 36 * $best($rgb[0]) + 6 * $best($rgb[1]) + $best($rgb[2]);
    }
}
