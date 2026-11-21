<?php

declare(strict_types=1);

namespace Phpdup\Ui;

/**
 * DupBanner — "Five-Stage Conveyor" animated truecolor ANSI banner.
 *
 * Five stations (Scan · Preprocess · Cluster · Refactor · Report) ride a
 * sunrise-gradient rail. A white comet token travels station to station and
 * changes shape at each stop: file -> tree -> cluster ring -> signature ->
 * document, then dissolves into the "phpdup" wordmark.
 *
 * Owner constraints:
 *  - one-shot playback, <= 1.5 s (45 ticks x 33 ms = 1.485 s), holds final frame
 *  - final logo <= 7 rows x <= 56 cols (canvas is exactly 7 x 56)
 *  - 24-bit SGR (38;2;r;g;b) with 256-color fallback (38;5;n) and a plain
 *    zero-escape fallback for undecorated / NO_COLOR / --no-ansi
 *  - frame() is PURE and deterministic: seeded jitter via hash(), no wall clock
 *  - PHP 8.1 floor
 *
 * frame(0) and frame(FINAL_TICK) are byte-reproducible across invocations.
 */
final class DupBanner
{
    public const ROWS = 7;
    public const COLS = 56;
    public const TICKS = 45;
    public const FINAL_TICK = 44;
    public const FRAME_US = 33000;

    public const MODE_TRUECOLOR = 0;
    public const MODE_256 = 1;
    public const MODE_PLAIN = 2;
    public const MODE_AUTO = 3;

    /** @var list<string> */
    public const STAGES = ['Scan', 'Preprocess', 'Cluster', 'Refactor', 'Report'];

    /** @var list<array{int, int, int}> sunrise palette, one stop per stage */
    public const PALETTE = [
        [0x38, 0xBD, 0xF8], // sky blue   - Scan
        [0xA7, 0x8B, 0xFA], // violet     - Preprocess
        [0xF4, 0x72, 0xB6], // pink       - Cluster
        [0xFB, 0x92, 0x3C], // amber      - Refactor
        [0xFA, 0xCC, 0x15], // yellow     - Report
    ];

    /** @var list<int> column centre of each station (derived from the caption) */
    public const STATION_X = [5, 15, 27, 37, 47];

    /** @var list<int> column where each stage label starts in the caption row */
    private const LABEL_X = [4, 11, 24, 34, 45];

    private const RAIL_ROW = 3;
    private const LABEL_ROW = 4;
    private const WORD_ROW = 2;
    private const WORD_COL = 22;   // first wordmark letter; step 2 between letters
    private const DISSOLVE_TICK = 36;
    private const DWELL = 4;
    private const TRAVEL = 4;

    /** @var array{int, int, int} */
    private const WHITE = [255, 255, 255];

    /** @var list<list<string>> per-station token shape, 3 rows (<= 4 cols, centered) */
    private const SHAPES = [
        ['┌─┐', '│ │', '└─┘'],  // file (page)
        [' ┴ ', '─┼─', ' ┬ '],  // tree (AST node)
        ['╭─╮', '│ │', '╰─╯'],  // cluster ring
        ['    ', 'f(x)', '    '], // signature
        ['┌─┐', '├─┤', '└─┘'],  // document (ruled report)
    ];

    /**
     * Pure deterministic frame for tick 0..FINAL_TICK (clamped). Truecolor by
     * default; pass a MODE_* constant for the 256/plain variants. Each frame
     * repaints the whole 7 x 56 canvas and opens with a cursor-home so the
     * stream replays in place and HOLDs the final frame.
     */
    public static function frame(int $tick, int $mode = self::MODE_TRUECOLOR): string
    {
        $t = max(0, min(self::FINAL_TICK, $tick));
        $m = self::resolveMode($mode);
        $grid = self::build($t);
        $body = self::emit($grid, $m);

        return $m === self::MODE_PLAIN ? $body : "\e[H" . $body;
    }

    /** Single static fallback frame — plain text, ZERO escape codes. */
    public static function plain(): string
    {
        return self::frame(self::FINAL_TICK, self::MODE_PLAIN);
    }

    /** Full one-shot animation byte stream; ends holding the final frame. */
    public static function stream(int $mode = self::MODE_AUTO): string
    {
        $m = self::resolveMode($mode);
        if ($m === self::MODE_PLAIN) {
            return self::plain();
        }

        $out = "\e[?25l";
        for ($t = 0; $t <= self::FINAL_TICK; $t++) {
            $out .= self::frame($t, $m);
        }

        return $out . "\e[?25h";
    }

    /**
     * CLI playback: paints the animation once (<= 1.5 s), then leaves the
     * final frame on screen. Sleeps are wall-clock pacing only — the visual
     * comes from the pure frame().
     */
    public static function play(int $mode = self::MODE_AUTO): void
    {
        $m = self::resolveMode($mode);
        if ($m === self::MODE_PLAIN) {
            echo self::plain(), "\n";
            return;
        }

        echo "\e[?25l";
        for ($t = 0; $t <= self::FINAL_TICK; $t++) {
            echo self::frame($t, $m);
            if ($t < self::FINAL_TICK) {
                usleep(self::FRAME_US);
            }
        }
        echo "\e[0m\e[?25h\n";
    }

    /** Choose truecolor / 256 / plain from the environment. */
    public static function detectMode(): int
    {
        $noColor = getenv('NO_COLOR');
        if (is_string($noColor) && $noColor !== '') {
            return self::MODE_PLAIN;
        }

        $term = getenv('TERM');
        if ($term === 'dumb' || $term === '' || $term === false) {
            return self::MODE_PLAIN;
        }

        $colorterm = getenv('COLORTERM');
        if ($colorterm === 'truecolor' || $colorterm === '24bit') {
            return self::MODE_TRUECOLOR;
        }

        // any other real terminal gets the 256-color degradation; dumb/no TERM
        // was already caught above.
        return self::MODE_256;
    }

    private static function resolveMode(int $mode): int
    {
        return $mode === self::MODE_AUTO ? self::detectMode() : $mode;
    }

    // ---------------------------------------------------------------- build --

    /**
     * @return array<int, array<int, array{0: string, 1: ?array{int, int, int}, 2: bool}>>
     */
    private static function build(int $tick): array
    {
        $grid = self::newGrid();

        if ($tick >= self::DISSOLVE_TICK) {
            self::buildDissolve($grid, $tick);
            return $grid;
        }

        // ticks 0-31: five legs (4-tick dwell + 4-tick travel); ticks 32-35:
        // dwell at Report; ticks 36-44: dissolve into the wordmark.
        if ($tick >= 4 * (self::DWELL + self::TRAVEL)) {
            $cx = self::STATION_X[4];
            self::drawRail($grid, $cx);
            self::drawStations($grid, $cx, 4);
            self::drawShape($grid, 4, $cx);
        } else {
            $pair = intdiv($tick, self::DWELL + self::TRAVEL); // leg index 0..3
            $k = $tick % (self::DWELL + self::TRAVEL);
            if ($k < self::DWELL) {
                $cx = self::STATION_X[$pair];
                self::drawRail($grid, $cx);
                self::drawStations($grid, $cx, $pair);
                self::drawShape($grid, $pair, $cx);
            } else {
                $x0 = self::STATION_X[$pair];
                $x1 = self::STATION_X[$pair + 1];
                $pos = (int) round($x0 + ($x1 - $x0) * (($k - self::DWELL + 1) / self::TRAVEL));
                self::drawRail($grid, $pos);
                self::drawStations($grid, $pos, -1);
                self::drawCore($grid, $pos);
            }
        }

        self::drawCaption($grid, self::frontier($tick));

        return $grid;
    }

    /**
     * @return array<int, array<int, array{0: string, 1: ?array{int, int, int}, 2: bool}>>
     */
    private static function newGrid(): array
    {
        $grid = [];
        for ($r = 0; $r < self::ROWS; $r++) {
            for ($c = 0; $c < self::COLS; $c++) {
                $grid[$r][$c] = [' ', null, false];
            }
        }

        return $grid;
    }

    /**
     * Furthest column the comet has energised so far (rail/caption lighting).
     */
    private static function frontier(int $tick): int
    {
        if ($tick >= self::DISSOLVE_TICK) {
            return self::COLS;
        }

        if ($tick >= 4 * (self::DWELL + self::TRAVEL)) {
            return self::STATION_X[4];
        }

        $pair = intdiv($tick, self::DWELL + self::TRAVEL);
        $k = $tick % (self::DWELL + self::TRAVEL);
        if ($k < self::DWELL) {
            return self::STATION_X[$pair];
        }

        $x0 = self::STATION_X[$pair];
        $x1 = self::STATION_X[$pair + 1];

        return (int) round($x0 + ($x1 - $x0) * (($k - self::DWELL + 1) / self::TRAVEL));
    }

    /**
     * @param array<int, array<int, array{0: string, 1: ?array{int, int, int}, 2: bool}>> $grid
     */
    private static function drawRail(array &$grid, int $reached): void
    {
        for ($c = 0; $c < self::COLS; $c++) {
            $color = self::railColor($c);
            if ($c > $reached) {
                $color = self::dim($color, 0.45);
            }
            self::put($grid, self::RAIL_ROW, $c, '─', $color, false);
        }
    }

    /**
     * @param array<int, array<int, array{0: string, 1: ?array{int, int, int}, 2: bool}>> $grid
     */
    private static function drawStations(array &$grid, int $reached, int $dwelling): void
    {
        foreach (self::STATION_X as $idx => $sx) {
            if ($sx === $reached && $dwelling >= 0 && $idx === $dwelling) {
                self::put($grid, self::RAIL_ROW, $sx, '●', self::WHITE, true);
                continue;
            }

            $color = self::PALETTE[$idx];
            if ($sx < $reached) {
                self::put($grid, self::RAIL_ROW, $sx, '●', $color, true);
            } elseif ($sx > $reached) {
                self::put($grid, self::RAIL_ROW, $sx, '●', self::dim($color, 0.4), false);
            } else {
                self::put($grid, self::RAIL_ROW, $sx, '●', $color, true);
            }
        }
    }

    /**
     * @param array<int, array<int, array{0: string, 1: ?array{int, int, int}, 2: bool}>> $grid
     */
    private static function drawCore(array &$grid, int $pos): void
    {
        // comet tail behind the core along the rail
        self::put($grid, self::RAIL_ROW, $pos - 1, '─', self::mix(self::WHITE, self::railColor($pos - 1), 0.55), false);
        self::put($grid, self::RAIL_ROW, $pos - 2, '─', self::mix(self::WHITE, self::railColor($pos - 2), 0.28), false);
        self::put($grid, self::RAIL_ROW, $pos, '●', self::WHITE, true);
    }

    /**
     * @param array<int, array<int, array{0: string, 1: ?array{int, int, int}, 2: bool}>> $grid
     */
    private static function drawShape(array &$grid, int $station, int $cx): void
    {
        foreach (self::SHAPES[$station] as $r => $row) {
            $start = $cx - intdiv(mb_strlen($row) - 1, 2);
            foreach (mb_str_split($row) as $j => $ch) {
                self::put($grid, $r, $start + $j, $ch, self::WHITE, true);
            }
        }
    }

    /**
     * @param array<int, array<int, array{0: string, 1: ?array{int, int, int}, 2: bool}>> $grid
     */
    private static function drawCaption(array &$grid, int $reached): void
    {
        foreach (self::STAGES as $idx => $label) {
            $col = self::LABEL_X[$idx];
            $color = self::PALETTE[$idx];
            if ($reached < self::STATION_X[$idx]) {
                $color = self::dim($color, 0.4);
            }
            $bold = $reached >= self::STATION_X[$idx];
            foreach (mb_str_split($label) as $j => $ch) {
                self::put($grid, self::LABEL_ROW, $col + $j, $ch, $color, $bold);
            }
            if ($idx < 4) {
                $sepCol = $col + mb_strlen($label);
                self::put($grid, self::LABEL_ROW, $sepCol, ' ', null, false);
                self::put($grid, self::LABEL_ROW, $sepCol + 1, '·', [110, 110, 125], false);
                self::put($grid, self::LABEL_ROW, $sepCol + 2, ' ', null, false);
            }
        }
    }

    /**
     * @param array<int, array<int, array{0: string, 1: ?array{int, int, int}, 2: bool}>> $grid
     */
    private static function buildDissolve(array &$grid, int $tick): void
    {
        $d = $tick - self::DISSOLVE_TICK; // 0..8

        self::drawRail($grid, self::COLS);
        self::drawStations($grid, self::COLS, -1);
        self::drawCaption($grid, self::COLS);

        // the document at the Report station breaks apart, cell by cell
        $fade = ($d + 1) / 5.0;
        if ($fade < 1.0) {
            for ($r = 0; $r < 3; $r++) {
                for ($c = 0; $c < 3; $c++) {
                    if (self::jitter('doc', $r, $c) > $fade) {
                        $ch = mb_substr(self::SHAPES[4][$r], $c, 1);
                        self::put($grid, $r, self::STATION_X[4] - 1 + $c, $ch, self::WHITE, true);
                    }
                }
            }
        }

        // white sparks stream from the station into the wordmark centre
        if ($d < 5) {
            for ($s = 0; $s < 8; $s++) {
                $h = self::jitter('spark', $s, $d);
                $h2 = self::jitter('spark2', $s, $d);
                $ox = self::STATION_X[4] - 1 + (int) floor($h * 3);
                $oy = (int) floor($h2 * 3);
                $prog = min(1.0, ($d + 1 + $h) / 5.0);
                if ($prog >= 1.0) {
                    continue;
                }
                $px = (int) round($ox + (self::WORD_COL + 2 - $ox) * $prog);
                $py = (int) round($oy + (self::WORD_ROW - $oy) * $prog);
                self::put($grid, $py, $px, '·', self::WHITE, true);
            }
        }

        // the wordmark draws in, letter by letter
        $word = 'phpdup';
        for ($i = 0; $i < mb_strlen($word); $i++) {
            if ($d >= 3 + $i) {
                self::put($grid, self::WORD_ROW, self::WORD_COL + 2 * $i, mb_substr($word, $i, 1), self::wordColor($i), true);
            }
        }
    }

    private static function jitter(string $tag, int $a, int $b): float
    {
        return hexdec(substr(hash('crc32b', $tag . ':' . $a . ':' . $b), 0, 8)) / 4294967295.0;
    }

    // --------------------------------------------------------------- colour --

    /**
     * @return array{int, int, int}
     */
    private static function railColor(int $c): array
    {
        return self::gradClamped($c / (self::COLS - 1));
    }

    /**
     * @return array{int, int, int}
     */
    private static function wordColor(int $letter): array
    {
        return self::gradClamped($letter / 5);
    }

    /**
     * @return array{int, int, int}
     */
    private static function gradClamped(float $t): array
    {
        $t = max(0.0, min(1.0, $t));
        $seg = (int) ($t * 4);
        if ($seg >= 4) {
            $seg = 3; // last gradient segment: stops 3 -> 4
        } elseif ($seg < 0) {
            $seg = 0; // unreachable once clamped, but pins the offset domain
        }
        $local = $t * 4 - $seg;

        return self::lerp(self::PALETTE[$seg], self::PALETTE[$seg + 1], $local);
    }

    /**
     * @param array{int, int, int} $a
     * @param array{int, int, int} $b
     * @return array{int, int, int}
     */
    private static function lerp(array $a, array $b, float $t): array
    {
        return [
            (int) round($a[0] + ($b[0] - $a[0]) * $t),
            (int) round($a[1] + ($b[1] - $a[1]) * $t),
            (int) round($a[2] + ($b[2] - $a[2]) * $t),
        ];
    }

    /**
     * @param array{int, int, int} $a
     * @param array{int, int, int} $b
     * @return array{int, int, int}
     */
    private static function mix(array $a, array $b, float $t): array
    {
        return self::lerp($a, $b, $t);
    }

    /**
     * @param array{int, int, int} $c
     * @return array{int, int, int}
     */
    private static function dim(array $c, float $f): array
    {
        return [(int) round($c[0] * $f), (int) round($c[1] * $f), (int) round($c[2] * $f)];
    }

    /**
     * @param array{int, int, int} $c
     */
    private static function to256(array $c): int
    {
        $max = max($c);
        $min = min($c);
        if ($max - $min <= 12) {
            $gray = (int) round(($c[0] + $c[1] + $c[2]) / 3);
            if ($gray < 8) {
                return 16;
            }
            if ($gray > 248) {
                return 231;
            }

            return 232 + (int) round(($gray - 8) / 240 * 23);
        }

        $q = static fn (int $v): int => (int) round($v / 255 * 5);

        return 16 + 36 * $q($c[0]) + 6 * $q($c[1]) + $q($c[2]);
    }

    // ----------------------------------------------------------------- emit --

    /**
     * @param array<int, array<int, array{0: string, 1: ?array{int, int, int}, 2: bool}>> $grid
     */
    private static function emit(array $grid, int $mode): string
    {
        $lines = [];
        foreach ($grid as $row) {
            $line = '';
            $prev = null;
            foreach ($row as $cell) {
                [$ch, $fg, $bold] = $cell;
                $key = $mode === self::MODE_PLAIN
                    ? 'p'
                    : ($fg === null ? '-' : $fg[0] . ',' . $fg[1] . ',' . $fg[2]) . '|' . ($bold ? '1' : '0');
                if ($key !== $prev) {
                    if ($mode !== self::MODE_PLAIN) {
                        $line .= "\e[0m" . self::sgr($fg, $bold, $mode);
                    }
                    $prev = $key;
                }
                $line .= $ch;
            }
            $lines[] = $mode === self::MODE_PLAIN ? $line : $line . "\e[0m";
        }

        return implode("\n", $lines);
    }

    /**
     * @param ?array{int, int, int} $fg
     */
    private static function sgr(?array $fg, bool $bold, int $mode): string
    {
        if ($mode === self::MODE_PLAIN) {
            return '';
        }

        $b = $bold ? '1;' : '';
        if ($fg === null) {
            return $bold ? "\e[{$b}m" : '';
        }

        if ($mode === self::MODE_TRUECOLOR) {
            return "\e[{$b}38;2;{$fg[0]};{$fg[1]};{$fg[2]}m";
        }

        return "\e[{$b}38;5;" . self::to256($fg) . 'm';
    }

    /**
     * @param array<int, array<int, array{0: string, 1: ?array{int, int, int}, 2: bool}>> $grid
     * @param ?array{int, int, int} $fg
     */
    private static function put(array &$grid, int $row, int $col, string $ch, ?array $fg, bool $bold): void
    {
        if ($row < 0 || $row >= self::ROWS || $col < 0 || $col >= self::COLS) {
            return;
        }
        $grid[$row][$col] = [$ch, $fg, $bold];
    }
}
