<?php

declare(strict_types=1);

namespace Phpdup\Reporting;

/**
 * DupBanner — animated truecolor ANSI logo for phpdup.
 *
 * Concept 4: "Anti-Unification Template". The PHPDUP wordmark is rendered as
 * an anti-unification template: most glyph cells are constant graphite on a
 * dark plate, while a few cells — the typographic counters of P, P, D and U —
 * are typed HOLES. Each hole cycles placeholder types (int cyan, string
 * amber, array magenta, mixed grey, ~150 ms per swap) until the holes lock
 * onto their synthesised labels in a staggered cascade. The held final frame
 * shows the mark with its typed slots beneath the holes — exactly what
 * AntiUnifier (hole discovery) + ParameterSynthesizer (hole typing/naming)
 * produce for a clone cluster.
 *
 * Hard constraints (owner spec):
 *  - 24-bit SGR (38;2/48;2) with xterm-256 fallback and a zero-escape plain
 *    frame for undecorated / NO_COLOR / --no-ansi output.
 *  - one-shot playback <= 1.5 s then HOLD (45 ticks at <= 30 fps).
 *  - final logo <= 7 rows x <= 56 cols (no overflow at 80 cols).
 *  - frame() is PURE: deterministic, seeded by tick only — no wall clock.
 */
final class DupBanner
{
    public const DEPTH_TRUECOLOR = 'truecolor';
    public const DEPTH_256 = 'xterm256';
    public const DEPTH_PLAIN = 'plain';

    public const COLS = 56;
    public const ROWS = 7;
    public const FPS = 30;
    public const TICK_COUNT = 45;
    public const LAST_TICK = self::TICK_COUNT - 1;
    public const TICK_USEC = 33_333;

    /** ticks per placeholder-type swap while cycling (5 × 33.3 ms ≈ 150 ms) */
    public const CYCLE_STRIDE = 5;
    /** staggered lock moment per hole (ticks) */
    /** @var array<int, int> */
    public const LOCK_TICKS = [30, 34, 38, 42];
    /** white-hot decay window after a hole locks */
    /** @var array<int, float> */
    public const FLASH_LEVELS = [1.0, 0.5, 0.2];

    private const GRAPHITE = [203, 213, 225];   // #CBD5E1
    private const PLATE    = [15, 23, 42];      // #0F172A dark ground
    private const DIM      = [71, 85, 105];     // #475569 connector dots
    private const RULE     = [51, 65, 85];      // #334155 template rule
    private const WHITE    = [255, 255, 255];

    /** @var list<array{word: string, rgb: array{0: int, 1: int, 2: int}}> */
    private const TYPES = [
        ['word' => 'int',    'rgb' => [34, 211, 238]],   // #22D3EE
        ['word' => 'string', 'rgb' => [245, 158, 11]],   // #F59E0B
        ['word' => 'array',  'rgb' => [232, 121, 249]],  // #E879F9
        ['word' => 'mixed',  'rgb' => [163, 163, 163]],  // #A3A3A3
    ];

    /** @var array<string, list<string>> char => 5 ink masks, row-major */
    private const FONT = [
        'P' => ['1110', '1001', '1110', '1000', '1000'],
        'H' => ['1001', '1001', '1111', '1001', '1001'],
        'D' => ['1110', '1001', '1001', '1001', '1110'],
        'U' => ['1001', '1001', '1001', '1001', '0110'],
    ];

    private const WORD = 'PHPDUP';
    /** left column of each letter (4-wide glyphs, 2-col gaps) */
    /** @var array<int, int> */
    private const LETTER_X = [11, 17, 23, 29, 35, 41];
    /** holes: [letter index, mask row of the 2-cell counter, final type index] */
    /** @var array<int, array{0: int, 1: int, 2: int}> */
    private const HOLES = [[0, 1, 0], [2, 1, 1], [3, 2, 2], [4, 2, 3]];
    /** hash seed so flicker/flash jitter is reproducible */
    private const SEED = 0xD0E1A1E;

    // ================================================================ public API

    /**
     * Pure truecolor frame for the given tick (0..LAST_TICK).
     * Byte-reproducible across runs — only the tick and SEED feed it.
     */
    public function frame(int $tick): string
    {
        return $this->frameAtDepth($tick, self::DEPTH_TRUECOLOR);
    }

    /**
     * @param string $depth one of self::DEPTH_*
     */
    public function frameAtDepth(int $tick, string $depth): string
    {
        if ($tick < 0 || $tick > self::LAST_TICK) {
            throw new \InvalidArgumentException("tick $tick outside 0.." . self::LAST_TICK);
        }
        if ($depth === self::DEPTH_PLAIN) {
            return $this->plain();
        }
        if ($depth !== self::DEPTH_TRUECOLOR && $depth !== self::DEPTH_256) {
            throw new \InvalidArgumentException("unknown depth '$depth'");
        }

        return $this->encode($this->compose($tick), $depth);
    }

    public function finalFrame(): string
    {
        return $this->frame(self::LAST_TICK);
    }

    /** Zero-escape static fallback (undecorated output / NO_COLOR / --no-ansi). */
    public function plain(): string
    {
        $labels = [];
        foreach (self::TYPES as $t) {
            $labels[] = $t['word'];
        }

        return "PHPDUP -- anti-unification template\n"
            . '   ' . str_pad($labels[0], 8) . str_pad($labels[1], 8) . str_pad($labels[2], 8) . $labels[3] . "\n";
    }

    /**
     * Full one-shot animation byte stream ending on the hold frame:
     * cat it in a truecolor terminal (>= 8 cols below the cursor) to replay.
     *
     * @param self::DEPTH_* $depth
     */
    public function stream(string $depth = self::DEPTH_TRUECOLOR): string
    {
        if ($depth === self::DEPTH_PLAIN) {
            return $this->plain();
        }

        $frames = [];
        for ($tick = 0; $tick <= self::LAST_TICK; $tick++) {
            $frames[] = $this->frameAtDepth($tick, $depth);
        }

        return "\e[?25l" . implode("\e[" . self::ROWS . "A\r", $frames) . "\e[?25h";
    }

    /**
     * Write the animation with real pacing, then hold the final frame.
     *
     * @param resource $out
     */
    public function play($out = STDOUT, bool $decorated = true, bool $noAnsi = false): void
    {
        $depth = self::resolveMode($decorated, $noAnsi);
        if ($depth === self::DEPTH_PLAIN) {
            \fwrite($out, $this->plain());

            return;
        }

        \fwrite($out, "\e[?25l");
        for ($tick = 0; $tick <= self::LAST_TICK; $tick++) {
            if ($tick > 0) {
                \fwrite($out, "\e[" . self::ROWS . "A\r");
                \usleep(self::TICK_USEC);
            }
            \fwrite($out, $this->frameAtDepth($tick, $depth));
        }
        \fwrite($out, "\e[?25h");
    }

    /**
     * Fallback chain: truecolor when COLORTERM advertises it, xterm-256
     * otherwise, plain text for undecorated / NO_COLOR / --no-ansi.
     *
     * @return self::DEPTH_*
     */
    public static function resolveMode(bool $decorated = true, bool $noAnsi = false, bool $noColor = false): string
    {
        if (!$decorated || $noAnsi || $noColor) {
            return self::DEPTH_PLAIN;
        }
        $colorterm = \getenv('COLORTERM');
        if (is_string($colorterm) && \preg_match('/truecolor|24bit/', $colorterm) === 1) {
            return self::DEPTH_TRUECOLOR;
        }

        return self::DEPTH_256;
    }

    // ================================================================ design

    /**
     * @return list<list<array{0: string, 1: ?array{0: int, 1: int, 2: int}, 2: ?array{0: int, 1: int, 2: int}}>>
     */
    private function compose(int $tick): array
    {
        /** @var list<list<array{0: string, 1: ?array{0: int, 1: int, 2: int}, 2: ?array{0: int, 1: int, 2: int}}>> $cells */
        $cells = [];
        for ($y = 0; $y < self::ROWS; $y++) {
            $row = [];
            for ($x = 0; $x < self::COLS; $x++) {
                $row[] = [' ', null, $y <= 4 ? self::PLATE : null];
            }
            $cells[] = $row;
        }

        // flat per-letter hole maps (-1 = no hole)
        /** @var array<int,int> $holeCol */
        $holeCol = array_fill(0, \count(self::LETTER_X), -1);
        /** @var array<int,int> $holeRow */
        $holeRow = array_fill(0, \count(self::LETTER_X), -1);
        /** @var array<int,int> $holeSlot */
        $holeSlot = array_fill(0, \count(self::LETTER_X), -1);
        foreach (self::HOLES as $h => [$li, $row]) {
            $holeCol[$li] = self::LETTER_X[$li] + 1;
            $holeRow[$li] = $row;
            $holeSlot[$li] = $h;
        }

        // wordmark — constant graphite ink; hole counters + connector dots beneath them
        foreach (str_split(self::WORD) as $li => $ch) {
            $glyph = self::FONT[$ch];
            $x0 = self::LETTER_X[$li];
            for ($y = 0; $y < 5; $y++) {
                foreach (str_split($glyph[$y]) as $cx => $bit) {
                    $x = $x0 + $cx;
                    if ($bit === '1') {
                        $cells[$y][$x] = ['█', self::GRAPHITE, self::PLATE];
                    } elseif ($holeCol[$li] >= 0 && $y === $holeRow[$li] && ($x === $holeCol[$li] || $x === $holeCol[$li] + 1)) {
                        $cells[$y][$x] = $this->markerCell($tick, $holeSlot[$li]);
                    } elseif ($this->isConnector($y, $x, $holeCol[$li], $holeRow[$li])) {
                        $cells[$y][$x] = $this->connectorCell($tick, $holeSlot[$li]);
                    }
                }
            }
        }

        // template rule row 5 (segment under a locked hole brightens) + typed slots row 6
        for ($x = 11; $x <= 44; $x++) {
            $cells[5][$x] = ['┄', self::RULE, null];
        }
        foreach (self::HOLES as $h => [$li]) {
            $x = self::LETTER_X[$li] + 1;
            if ($tick >= self::lockTick($h)) {
                $tint = self::scale(self::TYPES[$this->finalType($h)]['rgb'], 0.55);
                $cells[5][$x] = ['┅', $tint, null];
                $cells[5][$x + 1] = ['┅', $tint, null];
            }
        }
        foreach (self::HOLES as $h => [$li]) {
            $type = $this->typeAt($h, $tick);
            $word = self::TYPES[$type]['word'];
            $start = self::LETTER_X[$li] + 2 - intdiv(\strlen($word), 2);
            $fg = self::mix(self::TYPES[$type]['rgb'], self::WHITE, $this->flashLevel($h, $tick));
            if ($tick < self::lockTick($h)) {
                $fg = self::scale($fg, 0.82 + 0.18 * self::hash($start, 6, $tick));
            }
            foreach (str_split($word) as $i => $c) {
                $cells[6][$start + $i] = [$c, $fg, null];
            }
        }

        return $cells;
    }

    /** @return array{0: string, 1: ?array{0: int, 1: int, 2: int}, 2: ?array{0: int, 1: int, 2: int}} */
    private function markerCell(int $tick, int $holeIndex): array
    {
        $type = $this->typeAt($holeIndex, $tick);
        $rgb = self::TYPES[$type]['rgb'];
        $flash = $this->flashLevel($holeIndex, $tick);
        $flicker = 0.85 + 0.15 * self::hash($holeIndex, $tick, $tick);
        $col = self::mix(self::scale($rgb, $flicker), self::WHITE, $flash);
        $glyph = $tick >= self::lockTick($holeIndex) ? '◉' : '▒';

        return [$glyph, $col, self::PLATE];
    }

    /**
     * Connector dot under a hole: grey while unresolved, its locked type colour after.
     *
     * @return array{0: string, 1: ?array{0: int, 1: int, 2: int}, 2: ?array{0: int, 1: int, 2: int}}
     */
    private function connectorCell(int $tick, int $holeIndex): array
    {
        if ($holeIndex >= 0 && $tick >= self::lockTick($holeIndex)) {
            return ['·', self::scale(self::TYPES[$this->finalType($holeIndex)]['rgb'], 0.8), self::PLATE];
        }

        return ['·', self::DIM, self::PLATE];
    }

    /**
     * A connector dot sits in an empty glyph cell directly under the hole's
     * 2-col marker, between the counter and the rule (rows 2..4).
     */
    private function isConnector(int $y, int $x, int $holeCol, int $holeRow): bool
    {
        return $holeCol >= 0 && $y > $holeRow && $y <= 4
            && ($x === $holeCol || $x === $holeCol + 1);
    }

    private function typeAt(int $holeIndex, int $tick): int
    {
        if ($tick >= self::lockTick($holeIndex)) {
            return $this->finalType($holeIndex);
        }

        return (intdiv($tick, self::CYCLE_STRIDE) + $holeIndex) % \count(self::TYPES);
    }

    private function finalType(int $holeIndex): int
    {
        return self::HOLES[$holeIndex][2] ?? 0;
    }

    /** Staggered lock tick for a hole (total — clamps out-of-range to the end). */
    private static function lockTick(int $holeIndex): int
    {
        return self::LOCK_TICKS[$holeIndex] ?? self::LAST_TICK;
    }

    private function flashLevel(int $holeIndex, int $tick): float
    {
        $delta = $tick - self::lockTick($holeIndex);
        if ($tick < self::lockTick($holeIndex)) {
            return 0.0;
        }

        return self::FLASH_LEVELS[$delta] ?? 0.0;
    }

    // ================================================================ encoding

    /**
     * @param list<list<array{0: string, 1: ?array{0: int, 1: int, 2: int}, 2: ?array{0: int, 1: int, 2: int}}>> $cells
     * @param string $depth validated truecolor/xterm256 by the frameAtDepth boundary guard
     */
    private function encode(array $cells, string $depth): string
    {
        $out = '';
        foreach ($cells as $row) {
            $curFg = '39';
            $curBg = '49';
            $line = '';
            foreach ($row as [$ch, $fg, $bg]) {
                $codes = [];
                $bgSgr = $this->sgr($bg, $depth, true);
                if ($bgSgr !== $curBg) {
                    $codes[] = $curBg = $bgSgr;
                }
                if ($ch !== ' ') {
                    $fgSgr = $this->sgr($fg, $depth, false);
                    if ($fgSgr !== $curFg) {
                        $codes[] = $curFg = $fgSgr;
                    }
                }
                if ($codes !== []) {
                    $line .= "\e[" . implode(';', $codes) . 'm';
                }
                $line .= $ch;
            }
            $out .= $line . "\e[0m\n";
        }

        return $out;
    }

    /** @param ?array{0: int, 1: int, 2: int} $c */
    private function sgr(?array $c, string $depth, bool $bg): string
    {
        if ($c === null) {
            return $bg ? '49' : '39';
        }

        return $depth === self::DEPTH_256
            ? ($bg ? '48;5;' : '38;5;') . self::to256($c)
            : sprintf('%s;2;%d;%d;%d', $bg ? '48' : '38', $c[0], $c[1], $c[2]);
    }

    /**
     * Nearest xterm-256 palette entry (6x6x6 cube + grayscale ramp).
     *
     * @param array{0: int, 1: int, 2: int} $c
     */
    public static function to256(array $c): int
    {
        static $memo = [];
        $key = "$c[0],$c[1],$c[2]";
        if (isset($memo[$key])) {
            return $memo[$key];
        }

        $best = 16;
        $bestDist = INF;
        $steps = [0, 95, 135, 175, 215, 255];
        $idx = 16;
        for ($r = 0; $r < 6; $r++) {
            for ($g = 0; $g < 6; $g++) {
                for ($b = 0; $b < 6; $b++) {
                    $d = self::redmean($c, [$steps[$r], $steps[$g], $steps[$b]]);
                    if ($d < $bestDist) {
                        $bestDist = $d;
                        $best = $idx;
                    }
                    $idx++;
                }
            }
        }
        for ($i = 0; $i < 24; $i++) {
            $v = 8 + $i * 10;
            $d = self::redmean($c, [$v, $v, $v]);
            if ($d < $bestDist) {
                $bestDist = $d;
                $best = 232 + $i;
            }
        }

        return $memo[$key] = $best;
    }

    /**
     * @param array{0: int, 1: int, 2: int} $a
     * @param array{0: int, 1: int, 2: int} $b
     */
    private static function redmean(array $a, array $b): float
    {
        $rm = ($a[0] + $b[0]) / 2;
        $dr = $a[0] - $b[0];
        $dg = $a[1] - $b[1];
        $db = $a[2] - $b[2];

        return (2 + $rm / 256) * $dr * $dr + 4 * $dg * $dg + (2 + (255 - $rm) / 256) * $db * $db;
    }

    // ================================================================ colour math

    /**
     * @param array{0: int, 1: int, 2: int} $a
     * @param array{0: int, 1: int, 2: int} $b
     * @return array{0: int, 1: int, 2: int}
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

    /**
     * @param array{0: int, 1: int, 2: int} $c
     * @return array{0: int, 1: int, 2: int}
     */
    private static function scale(array $c, float $k): array
    {
        return [
            (int) max(0, min(255, round($c[0] * $k))),
            (int) max(0, min(255, round($c[1] * $k))),
            (int) max(0, min(255, round($c[2] * $k))),
        ];
    }

    /** Deterministic hash noise in [0,1) for (x, y, tick) — seeded by SEED. */
    private static function hash(int $x, int $y, int $tick): float
    {
        $h = ($x * 374761393 + $y * 668265263 + $tick * 2147483647 + self::SEED) & 0xFFFFFFFF;
        $h = (($h ^ ($h >> 13)) * 1274126177) & 0xFFFFFFFF;

        return (($h ^ ($h >> 16)) & 0xFFFFFF) / 0x1000000;
    }
}
