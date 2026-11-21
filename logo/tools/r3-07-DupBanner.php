<?php

declare(strict_types=1);

namespace Phpdup\Support;

/**
 * DupBanner — "Call-Site Rewire" animated truecolor ANSI logo for phpdup.
 *
 * A circuit board: three identical chips labelled "fn" sit across the top,
 * each wired by a copper trace down to its own call-site pad; signal pulses
 * run down every trace. A probe sweeps the chip row and gilds all three
 * chips gold: IDENTICAL. Two chips desolder (fade, leaving empty
 * footprints); their traces re-route ONE SEGMENT PER TICK (┌ ─ ┐ │ └ ┘ ┤ ├)
 * onto the survivor, which widens into a chip whose silkscreen label is the
 * phpdup wordmark. One chip now fans out to all three call sites; a single
 * pulse runs each trace; then everything goes still.
 *
 * Ties to src/Refactor/ApplyExtractor.php (--apply extracts the function and
 * rewrites the member call sites) + src/Semantic/CallGraph.php (the pads).
 *
 * Constraints honoured: one-shot 45 ticks @ 30 fps = 1.5 s ending on a byte
 * stable hold; 7 rows x 56 cols; 24-bit SGR with 256-color and zero-escape
 * plain fallbacks; frame(int) is pure and seed-deterministic (no wall clock).
 */
final class DupBanner
{
    public const FPS = 30;
    public const TICKS = 45;            // ticks 0..44 (<= 45 budget)
    public const FINAL_TICK = 44;

    public const ROWS = 7;
    public const COLS = 56;
    public const SEED = 20261120;

    // ----------------------------------------------------------- phase map
    public const PULSE_START = 0;       // 0.0-0.4 s  pulses down three traces
    public const PULSE_END = 11;
    public const PROBE_START = 12;      // 0.4-0.7 s  identify sweep (gold)
    public const PROBE_END = 17;
    public const DESOLDER_START = 20;   // 0.7-1.2 s  fade + re-route
    public const DESOLDER_END = 22;
    public const FOOTPRINT_TICK = 23;
    public const ROUTE_START = 24;      // exactly one segment per tick
    public const ROUTE_COUNT = 10;      // ticks 24..33
    public const ROUTE_END = 33;
    public const VERIFY_END = 35;       // copper continuity sparks (colour only)
    public const GROW_START = 36;       // 1.2-1.5 s  chip grows + label prints
    public const GROW_FINAL_TICK = 37;
    public const FAN_START = 41;        // one last pulse runs each trace
    public const FAN_END = 43;          // tick 44: everything still

    // ------------------------------------------------------------ geometry
    public const CHIP_A = 10;
    public const CHIP_B = 27;
    public const CHIP_C = 46;
    private const ROW_TOP = 0;
    private const ROW_MID = 1;
    private const ROW_BOT = 2;
    private const TRACE_1 = 3;
    private const TRACE_2 = 4;
    private const TRACE_3 = 5;
    private const PAD_ROW = 6;

    // ------------------------------------------------------------- palette
    public const BOARD = '#0B3D2E';
    private const DOT = '#155E48';
    public const COPPER = '#D97706';
    public const PULSE = '#FDBA74';
    public const PAD = '#FACC15';
    private const PAD_FLASH = '#FEF08A';
    public const BODY = '#111827';
    private const OUTLINE = '#6B7280';
    public const LABEL = '#E5E7EB';
    public const SILK = '#A7F3D0';
    private const GOLD_BODY = '#78350F';
    private const GOLD_OUTLINE = '#FBBF24';
    private const GOLD_LABEL = '#FDE68A';
    private const FOOT = '#2A6F57';
    private const PROBE = '#ECFDF5';

    /**
     * Precomputed re-route segments, revealed strictly one per tick starting
     * at ROUTE_START. Each segment is a list of [row, col, char] cells. The
     * two arms are staggered (row 4 from the left, row 5 from the right) so
     * they merge into the surviving centre trace at ┤ / ├ junctions.
     *
     * @return list<list<array{0: int, 1: int, 2: string}>>
     */
    public static function routeSegments(): array
    {
        $span = static fn (int $r, int $from, int $to, string $ch): array => array_map(
            static fn (int $c): array => [$r, $c, $ch],
            range($from, $to),
        );

        return [
            [[self::TRACE_2, self::CHIP_A, '┌'], ...$span(self::TRACE_2, 11, 13, '─')],
            [[self::TRACE_3, self::CHIP_C, '┐'], ...$span(self::TRACE_3, 43, 45, '─')],
            $span(self::TRACE_2, 14, 18, '─'),
            $span(self::TRACE_3, 38, 42, '─'),
            $span(self::TRACE_2, 19, 23, '─'),
            $span(self::TRACE_3, 33, 37, '─'),
            $span(self::TRACE_2, 24, 26, '─'),
            $span(self::TRACE_3, 28, 32, '─'),
            [[self::TRACE_2, self::CHIP_B, '┤']],
            [[self::TRACE_3, self::CHIP_B, '├']],
        ];
    }

    // ---------------------------------------------------------- public API

    /** Pure truecolor frame for one tick; byte-identical on every call/run. */
    public function frame(int $tick): string
    {
        return $this->renderFrame($tick, 'tc');
    }

    /** 256-color rendition (38;5 / 48;5 only). */
    public function frame256(int $tick): string
    {
        return $this->renderFrame($tick, '256');
    }

    /** Zero-escape plain-text rendition. */
    public function framePlain(int $tick): string
    {
        return $this->renderFrame($tick, 'plain');
    }

    /** Full truecolor stream — plays once, ends on the hold frame. */
    public function play(): string
    {
        return $this->assembleStream('tc');
    }

    /** Full 256-color stream. */
    public function play256(): string
    {
        return $this->assembleStream('256');
    }

    /** Static fallback: the final fan-out, plain text, zero escape codes. */
    public function staticText(): string
    {
        return $this->framePlain(self::FINAL_TICK);
    }

    /**
     * Fallback chain for the ambient environment:
     * undecorated / NO_COLOR / --no-ansi -> plain; COLORTERM truecolor or
     * 24bit -> 24-bit; anything else -> 256-color approximation.
     *
     * @param array<string, string> $env
     */
    public function render(array $env = [], bool $decorated = true): string
    {
        if (!$decorated) {
            return $this->staticText();
        }
        $env += getenv() ?: [];
        if (($env['NO_COLOR'] ?? '') !== '' || ($env['TERM'] ?? '') === 'dumb') {
            return $this->staticText();
        }
        $ct = strtolower($env['COLORTERM'] ?? '');
        if ($ct === 'truecolor' || $ct === '24bit') {
            return $this->play();
        }
        return $this->play256();
    }

    // --------------------------------------------------------------- core

    private function assembleStream(string $mode): string
    {
        $out = "\e[?25l";
        $sep = "\e[" . self::ROWS . "A\r";
        for ($t = 0; $t < self::TICKS; $t++) {
            if ($t > 0) {
                $out .= $sep;
            }
            $out .= $this->renderFrame($t, $mode);
        }
        return $out . "\e[?25h";
    }

    private function renderFrame(int $tick, string $mode): string
    {
        if ($tick < 0 || $tick >= self::TICKS) {
            throw new \InvalidArgumentException("DupBanner tick out of range: {$tick}");
        }

        $grid = $this->buildGrid($tick);
        $lines = '';
        foreach ($grid as $row) {
            if ($mode === 'plain') {
                $chars = '';
                foreach ($row as $cell) {
                    $chars .= $cell['ch'] === '.' ? ' ' : $cell['ch'];
                }
                $lines .= rtrim($chars) . "\n";
                continue;
            }
            $curFg = null;
            $curBg = null;
            $line = '';
            foreach ($row as $cell) {
                if ($cell['bg'] !== $curBg) {
                    $line .= $this->colorSgr('48', $mode, $cell['bg']);
                    $curBg = $cell['bg'];
                }
                if ($cell['ch'] !== ' ') {
                    if ($cell['fg'] !== $curFg) {
                        $line .= $this->colorSgr('38', $mode, $cell['fg']);
                        $curFg = $cell['fg'];
                    }
                    $line .= $cell['ch'];
                } else {
                    $line .= ' ';
                }
            }
            $lines .= $line . "\e[0m\n";
        }
        return $lines;
    }

    private function colorSgr(string $which, string $mode, string $hex): string
    {
        $rgb = $this->hex($hex);
        return $mode === 'tc'
            ? sprintf("\e[%s;2;%d;%d;%dm", $which, $rgb[0], $rgb[1], $rgb[2])
            : sprintf("\e[%s;5;%dm", $which, $this->xterm256($rgb));
    }

    /**
     * Compose the cell grid for one tick. Paint order: board -> board speck
     * -> traces (initial topology + desolder removals) -> re-route segments
     * -> pads -> chips -> probe -> pulses/sparks.
     *
     * @return list<list<array{ch: string, fg: string, bg: string}>>
     */
    private function buildGrid(int $tick): array
    {
        $grid = [];
        for ($y = 0; $y < self::ROWS; $y++) {
            $row = [];
            for ($x = 0; $x < self::COLS; $x++) {
                $row[$x] = ['ch' => ' ', 'fg' => self::COPPER, 'bg' => self::BOARD];
            }
            $grid[$y] = $row;
        }

        $this->paintBoardDots($grid);
        $this->paintInitialTraces($grid, $tick);
        $this->paintRouteSegments($grid, $tick);
        $this->paintPads($grid, $tick);
        $this->paintChips($grid, $tick);
        $this->paintProbe($grid, $tick);
        $this->paintPulses($grid, $tick);

        return $grid;
    }

    /** Faint silkscreen speckle so the board reads as a board. */
    /** @param list<list<array{ch: string, fg: string, bg: string}>> $grid */
    private function paintBoardDots(array &$grid): void
    {
        for ($y = 0; $y < self::ROWS; $y++) {
            for ($x = 0; $x < self::COLS; $x++) {
                if ($this->hashUnit($x, $y) > 0.955) {
                    $this->setCell($grid, $y, $x, '.', self::DOT, self::BOARD);
                }
            }
        }
    }

    /** The three original chip-to-pad drops + their desolder-time removals. */
    /** @param list<list<array{ch: string, fg: string, bg: string}>> $grid */
    private function paintInitialTraces(array &$grid, int $tick): void
    {
        $drop = function (int $col, int $rowFrom, int $rowTo) use (&$grid): void {
            for ($y = $rowFrom; $y <= $rowTo; $y++) {
                $this->setCell($grid, $y, $col, '│', self::COPPER, self::BOARD);
            }
        };

        // chip A trace: top cell lifts off when the chip desolders
        $drop(self::CHIP_A, self::TRACE_1, self::TRACE_3);
        if ($tick >= self::DESOLDER_START + 1) {
            $this->clearCell($grid, self::TRACE_1, self::CHIP_A);
        }

        // chip B trace: the survivor, intact throughout
        $drop(self::CHIP_B, self::TRACE_1, self::TRACE_3);

        // chip C trace: two upper cells lift; the bottom cell stays and
        // becomes the ┐ corner when its arm routes
        $drop(self::CHIP_C, self::TRACE_1, self::TRACE_3);
        if ($tick >= self::DESOLDER_START + 1) {
            $this->clearCell($grid, self::TRACE_2, self::CHIP_C);
            $this->clearCell($grid, self::TRACE_1, self::CHIP_C);
        }
    }

    /** @param list<list<array{ch: string, fg: string, bg: string}>> $grid */
    private function clearCell(array &$grid, int $y, int $x): void
    {
        $this->setCell($grid, $y, $x, ' ', self::COPPER, self::BOARD);
    }

    /** Re-routed arms: one precomputed segment revealed per routing tick. */
    /** @param list<list<array{ch: string, fg: string, bg: string}>> $grid */
    private function paintRouteSegments(array &$grid, int $tick): void
    {
        if ($tick < self::ROUTE_START) {
            return;
        }
        $revealed = min($tick - self::ROUTE_START + 1, self::ROUTE_COUNT);
        $segments = self::routeSegments();
        for ($i = 0; $i < $revealed; $i++) {
            foreach ($segments[$i] as $cell) {
                [$y, $x, $ch] = $cell;
                $this->setCell($grid, $y, $x, $ch, self::COPPER, self::BOARD);
            }
        }
    }

    /** @param list<list<array{ch: string, fg: string, bg: string}>> $grid */
    private function paintPads(array &$grid, int $tick): void
    {
        $phase1Flash = $tick <= self::PULSE_END && $tick % 4 === 3;
        $arrival = $tick >= self::FAN_START && $tick <= self::FAN_END;
        foreach ([self::CHIP_A, self::CHIP_B, self::CHIP_C] as $col) {
            $color = self::PAD;
            if ($phase1Flash) {
                $color = self::PAD_FLASH;
            }
            if ($arrival && $col !== self::CHIP_B) {
                $color = self::PULSE;
            }
            $this->setCell($grid, self::PAD_ROW, $col, '●', $color, self::BOARD);
        }
    }

    /** @param list<list<array{ch: string, fg: string, bg: string}>> $grid */
    private function paintChips(array &$grid, int $tick): void
    {
        // side chips: fn -> gold -> desoldering (fades from gold) -> footprint
        if ($tick >= self::FOOTPRINT_TICK) {
            $this->drawFootprint($grid, self::CHIP_A);
            $this->drawFootprint($grid, self::CHIP_C);
        } else {
            $fadeK = $tick >= self::DESOLDER_START
                ? min(1.0, ($tick - self::DESOLDER_START) / 2)
                : 0.0;
            $goldA = $tick >= 13;
            $goldC = $tick >= 17;
            $this->drawChip($grid, self::CHIP_A, $goldA, $fadeK);
            $this->drawChip($grid, self::CHIP_C, $goldC, $fadeK);
        }

        // surviving chip: fn -> gold -> grows to the wordmark package
        if ($tick >= self::GROW_FINAL_TICK) {
            $this->drawWordmarkChip($grid, 23, 30, 'phpdup');
        } elseif ($tick >= self::GROW_START) {
            $this->drawWordmarkChip($grid, 23, 29, 'phpdu');
        } else {
            $this->drawChip($grid, self::CHIP_B, $tick >= 15, 0.0);
        }
    }

    /**
     * Small 5-wide "fn" chip centred on $center. $fadeK > 0 desolders it
     * (gold -> board) after the identify sweep.
     *
     * @param list<list<array{ch: string, fg: string, bg: string}>> $grid
     */
    private function drawChip(array &$grid, int $center, bool $gold, float $fadeK): void
    {
        $body = self::BODY;
        $border = self::OUTLINE;
        $text = self::LABEL;
        if ($gold) {
            $body = self::GOLD_BODY;
            $border = self::GOLD_OUTLINE;
            $text = self::GOLD_LABEL;
        }
        if ($fadeK > 0.0) {
            $body = $this->mixHex($body, self::BOARD, $fadeK);
            $border = $this->mixHex($border, self::FOOT, $fadeK);
            $text = $this->mixHex($text, self::FOOT, $fadeK);
        }

        $l = $center - 2;
        $r = $center + 2;
        $this->setCell($grid, self::ROW_TOP, $l, '┌', $border, $body);
        $this->setCell($grid, self::ROW_TOP, $l + 1, '─', $border, $body);
        $this->setCell($grid, self::ROW_TOP, $center, '─', $border, $body);
        $this->setCell($grid, self::ROW_TOP, $r - 1, '─', $border, $body);
        $this->setCell($grid, self::ROW_TOP, $r, '┐', $border, $body);
        $this->setCell($grid, self::ROW_MID, $l, '│', $border, $body);
        $this->setCell($grid, self::ROW_MID, $r, '│', $border, $body);
        $this->setCell($grid, self::ROW_BOT, $l, '└', $border, $body);
        $this->setCell($grid, self::ROW_BOT, $l + 1, '─', $border, $body);
        $this->setCell($grid, self::ROW_BOT, $center, '┬', $border, $body);
        $this->setCell($grid, self::ROW_BOT, $r - 1, '─', $border, $body);
        $this->setCell($grid, self::ROW_BOT, $r, '┘', $border, $body);

        $label = $fadeK >= 0.5 ? 'f· ' : 'fn·';
        foreach (mb_str_split($label) as $i => $ch) {
            $this->setCell($grid, self::ROW_MID, $l + 1 + $i, $ch, $text, $body);
        }
    }

    /**
     * Empty desoldered footprint: outline glyph only, board showing through.
     * @param list<list<array{ch: string, fg: string, bg: string}>> $grid
     */
    private function drawFootprint(array &$grid, int $center): void
    {
        $l = $center - 2;
        $r = $center + 2;
        foreach (['┌', '┐', '└', '┘'] as $i => $ch) {
            $x = $i % 2 === 0 ? $l : $r;
            $y = $i < 2 ? self::ROW_TOP : self::ROW_BOT;
            $this->setCell($grid, $y, $x, $ch, self::FOOT, self::BOARD);
        }
        foreach ([self::ROW_TOP, self::ROW_BOT] as $y) {
            foreach ([$l + 1, $center, $r - 1] as $x) {
                $ch = $y === self::ROW_BOT && $x === $center ? '┬' : '─';
                $this->setCell($grid, $y, $x, $ch, self::FOOT, self::BOARD);
            }
        }
        $this->setCell($grid, self::ROW_MID, $l, '│', self::FOOT, self::BOARD);
        $this->setCell($grid, self::ROW_MID, $r, '│', self::FOOT, self::BOARD);
        foreach ([$l + 1, $center, $r - 1] as $x) {
            $this->setCell($grid, self::ROW_MID, $x, ' ', self::COPPER, self::BOARD);
        }
    }

    /**
     * The surviving chip widened into its final package; the wordmark prints
     * in silkscreen mint across it.
     *
     * @param list<list<array{ch: string, fg: string, bg: string}>> $grid
     */
    private function drawWordmarkChip(array &$grid, int $l, int $r, string $word): void
    {
        $this->setCell($grid, self::ROW_TOP, $l, '┌', self::SILK, self::BODY);
        $this->setCell($grid, self::ROW_TOP, $r, '┐', self::SILK, self::BODY);
        $this->setCell($grid, self::ROW_MID, $l, '│', self::SILK, self::BODY);
        $this->setCell($grid, self::ROW_MID, $r, '│', self::SILK, self::BODY);
        $this->setCell($grid, self::ROW_BOT, $l, '└', self::SILK, self::BODY);
        $this->setCell($grid, self::ROW_BOT, $r, '┘', self::SILK, self::BODY);
        $this->setCell($grid, self::ROW_BOT, self::CHIP_B, '┬', self::SILK, self::BODY);

        for ($x = $l + 1; $x <= $r - 1; $x++) {
            if ($x !== self::CHIP_B) {
                $this->setCell($grid, self::ROW_BOT, $x, '─', self::SILK, self::BODY);
            }
            $this->setCell($grid, self::ROW_TOP, $x, '─', self::SILK, self::BODY);
            $this->setCell($grid, self::ROW_MID, $x, ' ', self::SILK, self::BODY);
        }

        foreach (mb_str_split($word) as $i => $ch) {
            $this->setCell($grid, self::ROW_MID, $l + 1 + $i, $ch, self::SILK, self::BODY);
        }
    }

    /** @param list<list<array{ch: string, fg: string, bg: string}>> $grid */
    private function setCell(array &$grid, int $y, int $x, string $ch, string $fg, string $bg): void
    {
        $grid[$y][$x] = ['ch' => $ch, 'fg' => $fg, 'bg' => $bg];
    }

    /** Identify probe: a crosshair riding row 0, gilding chips as it passes. */
    /** @param list<list<array{ch: string, fg: string, bg: string}>> $grid */
    private function paintProbe(array &$grid, int $tick): void
    {
        if ($tick < self::PROBE_START || $tick > self::PROBE_END) {
            return;
        }
        $x = 8 + ($tick - self::PROBE_START) * 8;
        $this->setCell($grid, self::ROW_TOP, $x, '┼', self::PROBE, self::BOARD);
    }

    /** Phase-one pulse heads + routing/late-phase colour-only sparks. */
    /** @param list<list<array{ch: string, fg: string, bg: string}>> $grid */
    private function paintPulses(array &$grid, int $tick): void
    {
        if ($tick <= self::PULSE_END) {
            $pos = $tick % 4;
            if ($pos > 2) {
                return;
            }
            $y = self::TRACE_1 + $pos;
            foreach ([self::CHIP_A, self::CHIP_B, self::CHIP_C] as $col) {
                $this->setCell($grid, $y, $col, '█', self::PULSE, self::BOARD);
            }
            return;
        }

        // Colour-only highlights: glyphs never change here, so the routing
        // segment gate stays exact.
        $sparks = [
            self::ROUTE_END + 1 => [
                [self::TRACE_3, self::CHIP_A],
                [self::TRACE_1, self::CHIP_B],
                [self::TRACE_3, self::CHIP_C],
            ],
            self::VERIFY_END => [
                [self::TRACE_2, 15],
                [self::TRACE_2, self::CHIP_B],
                [self::TRACE_3, 38],
            ],
            self::FAN_START => [
                [self::TRACE_1, self::CHIP_B],
                [self::TRACE_2, 23],
                [self::TRACE_3, 31],
            ],
            self::FAN_START + 1 => [
                [self::TRACE_2, self::CHIP_B],
                [self::TRACE_2, 16],
                [self::TRACE_3, 39],
            ],
            self::FAN_END => [
                [self::TRACE_3, self::CHIP_B],
                [self::TRACE_3, self::CHIP_A],
                [self::TRACE_3, self::CHIP_C],
            ],
        ];
        foreach ($sparks[$tick] ?? [] as [$y, $x]) {
            $cell = $grid[$y][$x];
            if ($cell['ch'] !== ' ' && $cell['ch'] !== '.') {
                $this->setCell($grid, $y, $x, $cell['ch'], self::PULSE, $cell['bg']);
            }
        }
    }

    // ------------------------------------------------------------- helpers

    /** @return array{0: int, 1: int, 2: int} */
    private function hex(string $hex): array
    {
        $h = ltrim($hex, '#');
        return [
            (int) hexdec(substr($h, 0, 2)),
            (int) hexdec(substr($h, 2, 2)),
            (int) hexdec(substr($h, 4, 2)),
        ];
    }

    private function mixHex(string $a, string $b, float $f): string
    {
        $f = max(0.0, min(1.0, $f));
        $ca = $this->hex($a);
        $cb = $this->hex($b);
        $out = '#';
        for ($i = 0; $i < 3; $i++) {
            $out .= str_pad(dechex((int) round($ca[$i] + ($cb[$i] - $ca[$i]) * $f)), 2, '0', STR_PAD_LEFT);
        }
        return strtoupper($out);
    }

    /** Deterministic integer hash -> unit float (seeded, no wall clock). */
    private function hashUnit(int $x, int $y): float
    {
        $n = ($x * 374761393 + $y * 668265263 + self::SEED) & 0x7FFFFFFF;
        $n = (($n ^ ($n >> 13)) * 1274126177) & 0x7FFFFFFF;
        $n = ($n ^ ($n >> 16)) & 0x7FFFFFFF;
        return ($n & 0xFFFF) / 0xFFFF;
    }

    /**
     * Nearest xterm-256 cube index for a 3-channel colour.
     * @param array{0: int, 1: int, 2: int} $rgb
     */
    private function xterm256(array $rgb): int
    {
        $steps = [0, 95, 135, 175, 215, 255];
        $idx = [0, 0, 0];
        foreach ($rgb as $c => $v) {
            $best = 0;
            $bestD = 255;
            foreach ($steps as $s => $step) {
                $d = abs($v - $step);
                if ($d < $bestD) {
                    $bestD = $d;
                    $best = $s;
                }
            }
            $idx[$c] = $best;
        }
        return 16 + 36 * $idx[0] + 6 * $idx[1] + $idx[2];
    }
}
