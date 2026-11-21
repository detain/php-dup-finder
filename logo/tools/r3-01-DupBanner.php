<?php

declare(strict_types=1);

namespace Phpdup\Branding;

/**
 * DupBanner — "Dot Plot Diagonals" animated ANSI logo for phpdup.
 *
 * The classic clone-detection picture: file A's 112 tokens along X, file B's
 * 28 tokens down Y, drawn in Unicode braille (2x4 dots per cell -> 56 cols x
 * 7 rows = 112 x 28 dots). A dot lights wherever A[i] == B[j]. Random matches
 * read as faint speckle; copied runs form bright diagonal streaks.
 *
 * The token data is REAL: A comes from a seeded PRNG over a small alphabet;
 * B splices three runs out of A — one exact, one with a single changed token
 * (a type-2 clone: a streak with a one-dot gap), one with a swapped pair —
 * plus random tail tokens. The plot, the streaks and the pink bracket boxes
 * are all COMPUTED from the token arrays (diagonal run-length analysis with
 * one-dot gap tolerance and swap bridging), never faked. This mirrors the
 * pairwise scoring of phpdup's Clusterer / PairScoreWorker and the
 * CoherenceAnalyzer pairwise matrix.
 *
 * One-shot animation, 45 ticks at <= 30fps (1.5s), then the final frame holds:
 *   ticks  0..21  (<=0.7s)   plot fills in a diagonal wipe, all matches dim
 *   ticks 22..32  (~0.7-1.1s) streaks brighten speckle->cyan, pink corner boxes pop
 *   ticks 33..44  (~1.1-1.5s) speckle fades to 25%, streaks pulse cyan->white once,
 *                             the "phpdup" wordmark block-fades in over the centre
 *
 * Frames are PURE functions of the tick: no wall-clock, byte-reproducible
 * across separate processes.
 *
 * Fallback chain: truecolor (\e[38;2;r;g;bm / \e[48;2;r;g;bm) -> 256-colour
 * (38;5 / 48;5, xterm 6x6x6 cube + grey ramp) -> plain ASCII, zero escapes.
 */
final class DupBanner
{
    public const COLS = 56;
    public const ROWS = 7;
    public const DOT_W = 112;
    public const DOT_H = 28;

    public const TICKS = 45;
    public const FPS = 30;

    /** Streaks with fewer lit dots than this stay speckle noise. */
    public const MIN_CLONE = 5;

    public const BG = [11, 17, 32];          // #0B1120
    public const SPECKLE = [51, 65, 85];     // #334155
    public const STREAK = [34, 211, 238];    // #22D3EE
    public const PEAK = [236, 254, 255];     // #ECFEFF
    public const BOX = [244, 114, 182];      // #F472B6

    public const SEED = 1337;

    /** Small token alphabet so random pairs collide into a faint speckle field. */
    public const ALPHABET = ['if', 'for', 'echo', '$v', 'call', 'ret', 'new', 'str', 'num', '}'];

    private const T_WIPE_END = 21;     // last tick of the fill wipe
    private const T_GLOW0 = 22;        // streak 0 starts brightening
    private const T_GLOW_STEP = 3;     // stagger per streak
    private const T_GLOW_RAMP = 5;     // ticks per streak brighten
    private const T_BOX0 = 26;         // streak 0 gets its corner box
    private const T_FADE = 33;         // speckle fade to 25% begins
    private const T_FADE_RAMP = 6;
    private const T_PULSE = 33;        // single cyan->white pulse
    private const T_PULSE_RAMP = 9;
    private const T_WORD = 35;         // wordmark block fade-in
    private const T_WORD_RAMP = 9;

    /** Dot classes; in a mixed cell the higher number paints the cell. */
    private const CLS_SPECKLE = 0;
    private const CLS_BOX = 1;
    private const CLS_STREAK = 2;

    /** Braille bit for dot (dx, dy) inside a cell, Unicode dot numbering. */
    private const BITS = [
        [0x01, 0x02],  // dy 0: dot1 left,  dot2 right
        [0x04, 0x20],  // dy 1: dot3 left,  dot6 right
        [0x10, 0x80],  // dy 2: dot5 left,  dot8 right
        [0x40, 0x08],  // dy 3: dot7 left,  dot4 right
    ];

    /** 4x4-cell pixel glyphs ('#' = on), painted as solid U+28FF blocks. */
    private const FONT = [
        'p' => ['###.', '#..#', '###.', '#...'],
        'h' => ['#...', '###.', '#.#.', '#.#.'],
        'd' => ['...#', '.###', '#..#', '.###'],
        'u' => ['#..#', '#..#', '#..#', '.###'],
    ];

    private const WORD = 'phpdup';
    private const WORD_COL = 11;   // start cell column
    private const WORD_ROW = 2;    // start cell row

    /** @var array<int, list<array{int, int, int}>> cell key => list of [braille bit, class, streak idx|-1] */
    private array $cellDots;

    /** @var list<array{dots: list<array{int, int}>, brackets: list<array{int, int}>, length: int, type: string}> */
    private array $streaks;

    public function __construct(private readonly string $depth = 'tc')
    {
        if (!in_array($this->depth, ['tc', '256', 'plain'], true)) {
            throw new \InvalidArgumentException("unknown depth '{$this->depth}'");
        }
        $model = self::buildModel();
        $this->streaks = $model['streaks'];
        $this->cellDots = self::cellRegistry(['lit' => $model['lit'], 'streaks' => $model['streaks']]);
    }

    /** Depth chain from the environment: NO_COLOR/dumb -> plain, truecolor COLORTERM -> tc, else 256. */
    public static function forEnvironment(): self
    {
        $env = static fn (string $k): string => strtolower((string) (getenv($k) ?: ''));
        if (getenv('NO_COLOR') !== false && getenv('NO_COLOR') !== '') {
            return new self('plain');
        }
        $ct = $env('COLORTERM');
        if (str_contains($ct, 'truecolor') || str_contains($ct, '24bit')) {
            return new self('tc');
        }
        return $env('TERM') === 'dumb' ? new self('plain') : new self('256');
    }

    /** Truecolor frame for the given tick — pure, deterministic. */
    public function frame(int $tick): string
    {
        if ($tick < 0 || $tick >= self::TICKS) {
            throw new \OutOfRangeException('tick ' . $tick . ' outside 0..' . (self::TICKS - 1));
        }
        return $this->encode($this->cells($tick));
    }

    /**
     * Composed cell grid for a tick. cls: 0 blank, 1 speckle, 2 box,
     * 3 streak, 4 word. gi: owning streak index, -1 otherwise.
     *
     * @return list<list<array{cp: int, fg: ?array{int, int, int}, cls: int, gi: int}>>
     */
    public function cells(int $tick): array
    {
        $grid = [];
        $wordGate = self::wordLevel($tick) > 0.02;
        for ($cy = 0; $cy < self::ROWS; $cy++) {
            $row = [];
            for ($cx = 0; $cx < self::COLS; $cx++) {
                if ($wordGate && self::wordPixel($cx, $cy)) {
                    $row[] = ['cp' => 0x28FF, 'fg' => self::wordColor($tick), 'cls' => 4, 'gi' => -1];
                    continue;
                }
                $row[] = $this->plotCell($cx, $cy, $tick);
            }
            $grid[] = $row;
        }
        return $grid;
    }

    /**
     * @return array{cp: int, fg: ?array{int, int, int}, cls: int, gi: int}
     */
    private function plotCell(int $cx, int $cy, int $tick): array
    {
        if ($cx + $cy > self::wipeLimit($tick)) {
            return ['cp' => 0, 'fg' => null, 'cls' => 0, 'gi' => -1];
        }
        $mask = 0;
        $best = -1;
        $bestGi = -1;
        foreach ($this->cellDots[$cy * self::COLS + $cx] ?? [] as $dot) {
            [$bit, $cls, $gi] = $dot;
            if ($cls === self::CLS_BOX && !self::boxOn($tick, $gi)) {
                continue;
            }
            $mask |= $bit;
            if ($cls > $best) {
                $best = $cls;
                $bestGi = $gi;
            }
        }
        if ($mask === 0) {
            return ['cp' => 0, 'fg' => null, 'cls' => 0, 'gi' => -1];
        }
        $fg = match (true) {
            $best === self::CLS_STREAK => self::streakColor($tick, $bestGi),
            $best === self::CLS_BOX => self::BOX,
            default => self::speckleColor($tick),
        };
        return ['cp' => 0x2800 + $mask, 'fg' => $fg, 'cls' => $best + 1, 'gi' => $bestGi];
    }

    /**
     * @param list<list<array{cp: int, fg: ?array{int, int, int}, cls: int, gi: int}>> $grid
     */
    private function encode(array $grid): string
    {
        if ($this->depth === 'plain') {
            return self::encodePlain($grid);
        }
        $out = "\e[H";
        foreach ($grid as $row) {
            $out .= "\e[0m" . $this->seq(48, self::BG);
            $prev = null;
            foreach ($row as $cell) {
                $ch = $cell['cp'] === 0 ? ' ' : \mb_chr($cell['cp'], 'UTF-8');
                $fg = $cell['fg'];
                if ($fg !== null && $fg !== $prev) {
                    $out .= $this->seq(38, $fg);
                    $prev = $fg;
                }
                $out .= $ch;
            }
            $out .= "\e[0m\n";
        }
        return $out;
    }

    /** @param array{int, int, int} $rgb */
    private function seq(int $kind, array $rgb): string
    {
        if ($this->depth === '256') {
            return "\e[{$kind};5;" . self::xtermIndex($rgb) . 'm';
        }
        return "\e[{$kind};2;{$rgb[0]};{$rgb[1]};{$rgb[2]}m";
    }

    /**
     * Static plain-text frame: '#' wordmark, 'X' streaks, '+' boxes, '.' speckle.
     * Guaranteed zero escape codes.
     */
    public function plain(): string
    {
        return self::encodePlain($this->cells(self::TICKS - 1));
    }

    /** @param list<list<array{cp: int, fg: ?array{int, int, int}, cls: int, gi: int}>> $grid */
    private static function encodePlain(array $grid): string
    {
        $map = [' ', '.', '+', 'X', '#'];
        $out = '';
        foreach ($grid as $row) {
            foreach ($row as $cell) {
                $out .= $map[$cell['cls']];
            }
            $out .= "\n";
        }
        return $out;
    }

    /** Full animation stream (no sleeps); the final frame stays as the hold. */
    public function stream(): string
    {
        if ($this->depth === 'plain') {
            return $this->plain();
        }
        $out = "\e[?25l\e[2J";
        for ($t = 0; $t < self::TICKS; $t++) {
            $out .= $this->frame($t);
        }
        return $out . "\e[0m\e[?25h";
    }

    /** Live playback with pacing; after the last tick the final frame holds on screen. */
    public function play(?int $fps = null): void
    {
        $fps = $fps ?? self::FPS;
        if ($this->depth === 'plain') {
            echo $this->plain();
            return;
        }
        $us = intdiv(1_000_000, $fps);
        echo "\e[?25l\e[2J";
        for ($t = 0; $t < self::TICKS; $t++) {
            echo $this->frame($t);
            usleep($us);
        }
        echo "\e[0m\e[?25h";
    }

    // ---------------------------------------------------------------- timing

    /** Diagonal-wipe limit: cell (cx, cy) appears once cx+cy <= this. */
    public static function wipeLimit(int $tick): int
    {
        if ($tick >= self::T_WIPE_END) {
            return self::COLS + self::ROWS - 1;
        }
        return intdiv(($tick + 1) * (self::COLS + self::ROWS), self::T_WIPE_END + 1);
    }

    /** 0..1 brighten ramp of streak $gi. */
    public static function glow(int $tick, int $gi): float
    {
        return self::clamp01(($tick - (self::T_GLOW0 + $gi * self::T_GLOW_STEP)) / self::T_GLOW_RAMP);
    }

    public static function boxOn(int $tick, int $gi): bool
    {
        return $tick >= self::T_BOX0 + $gi * self::T_GLOW_STEP;
    }

    /** 0..1 progress of the speckle fade to 25%. */
    public static function speckleFade(int $tick): float
    {
        return self::clamp01(($tick - self::T_FADE) / self::T_FADE_RAMP);
    }

    /** 0..1..0 single pulse bump in the settle phase. */
    public static function pulse(int $tick): float
    {
        if ($tick < self::T_PULSE) {
            return 0.0;
        }
        return sin(M_PI * self::clamp01(($tick - self::T_PULSE) / self::T_PULSE_RAMP));
    }

    /** 0..1 smoothstep fade-in of the wordmark. */
    public static function wordLevel(int $tick): float
    {
        $w = self::clamp01(($tick - self::T_WORD) / self::T_WORD_RAMP);
        return $w * $w * (3.0 - 2.0 * $w);
    }

    // --------------------------------------------------------------- colours

    /** @return array{int, int, int} */
    public static function speckleColor(int $tick): array
    {
        $dim = self::lerp(self::BG, self::SPECKLE, 0.25);
        return self::lerp(self::SPECKLE, $dim, self::speckleFade($tick));
    }

    /** @return array{int, int, int} */
    public static function streakColor(int $tick, int $gi): array
    {
        $g = self::glow($tick, $gi);
        if ($g <= 0.0) {
            return self::speckleColor($tick);
        }
        $base = self::lerp(self::speckleColor($tick), self::STREAK, $g);
        return self::lerp($base, self::PEAK, self::pulse($tick) * $g);
    }

    /** @return array{int, int, int} */
    public static function wordColor(int $tick): array
    {
        return self::lerp(self::BG, self::PEAK, self::wordLevel($tick));
    }

    /**
     * @param array{int, int, int} $a
     * @param array{int, int, int} $b
     * @return array{int, int, int}
     */
    public static function lerp(array $a, array $b, float $t): array
    {
        $t = self::clamp01($t);
        return [
            (int) round($a[0] + ($b[0] - $a[0]) * $t),
            (int) round($a[1] + ($b[1] - $a[1]) * $t),
            (int) round($a[2] + ($b[2] - $a[2]) * $t),
        ];
    }

    /**
     * Nearest xterm colour: 6x6x6 cube vs 22-step grey ramp.
     *
     * @param array{int, int, int} $rgb
     */
    public static function xtermIndex(array $rgb): int
    {
        $lv = static fn (int $v): int => $v < 48 ? 0 : intdiv($v - 35, 40);
        [$r, $g, $b] = $rgb;
        [$qr, $qg, $qb] = [$lv($r), $lv($g), $lv($b)];
        $cubeIdx = 16 + 36 * $qr + 6 * $qg + $qb;
        $cubeRgb = [55 + 40 * $qr, 55 + 40 * $qg, 55 + 40 * $qb];
        $avg = intdiv($r + $g + $b, 3);
        if ($avg < 8) {
            $greyIdx = 16;
            $greyRgb = [0, 0, 0];
        } elseif ($avg > 247) {
            $greyIdx = 231;
            $greyRgb = [238, 238, 238];
        } else {
            $greyIdx = 232 + intdiv($avg - 8, 10);
            $v = 8 + ($greyIdx - 232) * 10;
            $greyRgb = [$v, $v, $v];
        }
        $dCube = ($r - $cubeRgb[0]) ** 2 + ($g - $cubeRgb[1]) ** 2 + ($b - $cubeRgb[2]) ** 2;
        $dGrey = ($r - $greyRgb[0]) ** 2 + ($g - $greyRgb[1]) ** 2 + ($b - $greyRgb[2]) ** 2;
        return $dGrey < $dCube ? $greyIdx : $cubeIdx;
    }

    private static function clamp01(float $v): float
    {
        return $v < 0.0 ? 0.0 : ($v > 1.0 ? 1.0 : $v);
    }

    // ------------------------------------------------------------- word font

    public static function wordPixel(int $cx, int $cy): bool
    {
        $col = $cx - self::WORD_COL;
        $row = $cy - self::WORD_ROW;
        if ($col < 0 || $row < 0 || $row >= 4) {
            return false;
        }
        $charIdx = intdiv($col, 6);
        $inChar = $col % 6;
        if ($charIdx >= strlen(self::WORD) || $inChar >= 4) {
            return false;
        }
        $glyph = self::FONT[substr(self::WORD, $charIdx, 1)];
        return $glyph[$row][$inChar] === '#';
    }

    // ------------------------------------------------------------- the model

    /**
     * @return array{
     *     A: list<string>, B: list<string>,
     *     lit: list<array{int, int}>,
     *     streaks: list<array{dots: list<array{int, int}>, brackets: list<array{int, int}>, length: int, type: string}>
     * }
     */
    public static function buildModel(): array
    {
        [$A, $B] = self::tokens();

        $lit = [];
        for ($y = 0; $y < self::DOT_H; $y++) {
            for ($x = 0; $x < self::DOT_W; $x++) {
                if ($A[$x] === $B[$y]) {
                    $lit[] = [$x, $y];
                }
            }
        }

        return ['A' => $A, 'B' => $B, 'lit' => $lit, 'streaks' => self::detectStreaks($lit)];
    }

    /** @return array{list<string>, list<string>} */
    private static function tokens(): array
    {
        $seed = self::SEED;
        $pick = static function () use (&$seed): string {
            $seed = ($seed * 1103515245 + 12345) & 0x7FFFFFFF;
            return self::ALPHABET[abs($seed) % count(self::ALPHABET)];
        };

        $A = [];
        for ($i = 0; $i < self::DOT_W; $i++) {
            $A[] = $pick();
        }

        $B = array_fill(0, self::DOT_H, '');
        // Run 1: exact copy A[8..14] -> B[0..6]   (solid diagonal streak)
        for ($k = 0; $k <= 6; $k++) {
            $B[$k] = $A[8 + $k];
        }
        // Run 2: copy A[30..36] -> B[7..13] with one changed middle token (type-2 clone)
        for ($k = 0; $k <= 6; $k++) {
            $B[7 + $k] = $A[30 + $k];
        }
        $B[10] = self::mutate($A[33]);
        // Run 3: copy A[60..65] -> B[14..19] with the middle pair swapped
        for ($k = 0; $k <= 5; $k++) {
            $B[14 + $k] = $A[60 + $k];
        }
        $B[16] = $A[63];
        $B[17] = $A[62];
        // Tail: random tokens (pure speckle contributors)
        for ($y = 20; $y < self::DOT_H; $y++) {
            $B[$y] = $pick();
        }

        return [$A, $B];
    }

    /** Change a token into a guaranteed-different alphabet member. */
    private static function mutate(string $token): string
    {
        $idx = array_search($token, self::ALPHABET, true);
        if ($idx === false) {
            throw new \LogicException('token outside alphabet');
        }
        return self::ALPHABET[($idx + 4) % count(self::ALPHABET)];
    }

    /**
     * Honest clone detection over the computed plot: per-diagonal consecutive
     * runs, merged across a single-dot gap (type-2 clone) or across a swapped
     * anti-diagonal pair (type-3/ordered clone). Merged runs with at least
     * MIN_CLONE lit dots become streaks and earn pink corner brackets.
     *
     * @param list<array{int, int}> $lit
     * @return list<array{dots: list<array{int, int}>, brackets: list<array{int, int}>, length: int, type: string}>
     */
    private static function detectStreaks(array $lit): array
    {
        /** @var array<int, array<int, bool>> $set */
        $set = [];
        /** @var array<int, list<array{int, int}>> $byDiag */
        $byDiag = [];
        foreach ($lit as [$x, $y]) {
            $set[$x][$y] = true;
            $byDiag[$x - $y][] = [$x, $y];
        }

        /** @var list<array{x0: int, y0: int, dots: list<array{int, int}>, gaps: int, swaps: int}> $groups */
        $groups = [];
        foreach ($byDiag as $pts) {
            usort($pts, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
            $acc = ['x0' => $pts[0][0], 'y0' => $pts[0][1], 'dots' => [$pts[0]], 'gaps' => 0, 'swaps' => 0];
            $i = 1;
            $n = count($pts);
            while ($i < $n) {
                $j = $i;
                while ($j + 1 < $n && $pts[$j + 1][0] === $pts[$j][0] + 1 && $pts[$j + 1][1] === $pts[$j][1] + 1) {
                    $j++;
                }
                $seg = array_slice($pts, $i, $j - $i + 1);
                $last = $acc['dots'][array_key_last($acc['dots'])];
                $gap = $seg[0][0] - $last[0];
                $lenA = count($acc['dots']);
                $lenB = count($seg);
                if ($gap === 1) {
                    foreach ($seg as $p) {
                        $acc['dots'][] = $p;
                    }
                    $i = $j + 1;
                    continue;
                }
                $bridged = false;
                if ($gap === 2 && $lenA >= 2 && $lenB >= 2) {
                    $acc['gaps']++;
                    $bridged = true;
                } elseif ($gap === 3 && $lenA >= 2 && $lenB >= 2) {
                    [$b, $yb] = $last;
                    $p1 = [$b + 2, $yb + 1];
                    $p2 = [$b + 1, $yb + 2];
                    if (isset($set[$p1[0]][$p1[1]], $set[$p2[0]][$p2[1]])) {
                        $acc['dots'][] = $p1;
                        $acc['dots'][] = $p2;
                        $acc['swaps']++;
                        $bridged = true;
                    }
                }
                if (!$bridged) {
                    if ($lenA >= self::MIN_CLONE) {
                        $groups[] = $acc;
                    }
                    $acc = ['x0' => $seg[0][0], 'y0' => $seg[0][1], 'dots' => $seg, 'gaps' => 0, 'swaps' => 0];
                    $i = $j + 1;
                    continue;
                }
                foreach ($seg as $p) {
                    $acc['dots'][] = $p;
                }
                $i = $j + 1;
            }
            if (count($acc['dots']) >= self::MIN_CLONE) {
                $groups[] = $acc;
            }
        }

        $streaks = [];
        foreach ($groups as $g) {
            $dots = $g['dots'];
            usort($dots, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
            $streaks[] = [
                'dots' => $dots,
                'brackets' => self::cornerBrackets($dots, $set),
                'length' => count($dots),
                'type' => $g['swaps'] > 0 ? 'swap' : ($g['gaps'] > 0 ? 'type2' : 'exact'),
            ];
        }

        usort($streaks, static fn (array $a, array $b): int => $a['dots'][0][0] <=> $b['dots'][0][0]);
        return $streaks;
    }

    /**
     * Pink corner-bracket arms around a streak's bounding box: 3-dot arms with
     * a 3-dot pad, clamped to the canvas; dots landing on real matches (or off
     * canvas) are dropped — data wins over ornament.
     *
     * @param list<array{int, int}> $dots
     * @param array<int, array<int, bool>> $set
     * @return list<array{int, int}>
     */
    private static function cornerBrackets(array $dots, array $set): array
    {
        $xs = array_map(static fn (array $d): int => $d[0], $dots);
        $ys = array_map(static fn (array $d): int => $d[1], $dots);
        $minX = min($xs) - 3;
        $maxX = max($xs) + 3;
        $minY = max(0, min($ys) - 3);
        $maxY = min(self::DOT_H - 1, max($ys) + 3);

        /** @var list<array{int, int}> $raw */
        $raw = [];
        foreach ([[$minX, $minY, 1, 1], [$maxX, $minY, -1, 1], [$minX, $maxY, 1, -1], [$maxX, $maxY, -1, -1]] as $corner) {
            [$cxD, $cyD, $sx, $sy] = $corner;
            for ($k = 0; $k < 3; $k++) {
                $raw[] = [$cxD + $sx * $k, $cyD];
            }
            for ($k = 1; $k < 3; $k++) {
                $raw[] = [$cxD, $cyD + $sy * $k];
            }
        }

        $kept = [];
        foreach ($raw as [$x, $y]) {
            if ($x < 0 || $y < 0 || $x >= self::DOT_W || $y >= self::DOT_H) {
                continue;
            }
            if (isset($set[$x][$y])) {
                continue;
            }
            $kept[] = [$x, $y];
        }
        return $kept;
    }

    /**
     * Flatten every dot (match + ornament) into per-cell [bit, class, streakIdx].
     *
     * @param array{lit: list<array{int, int}>, streaks: list<array{dots: list<array{int, int}>, brackets: list<array{int, int}>, length: int, type: string}>} $model
     * @return array<int, list<array{int, int, int}>>
     */
    private static function cellRegistry(array $model): array
    {
        /** @var array<int, array<int, int>> $streakAt */
        $streakAt = [];
        foreach ($model['streaks'] as $gi => $s) {
            foreach ($s['dots'] as [$x, $y]) {
                $streakAt[$x][$y] = $gi;
            }
        }

        $reg = [];
        foreach ($model['lit'] as [$x, $y]) {
            $cls = isset($streakAt[$x][$y]) ? self::CLS_STREAK : self::CLS_SPECKLE;
            $gi = $cls === self::CLS_STREAK ? $streakAt[$x][$y] : -1;
            $key = ($y >> 2) * self::COLS + ($x >> 1);
            $reg[$key][] = [self::BITS[$y & 3][$x & 1], $cls, $gi];
        }
        foreach ($model['streaks'] as $gi => $s) {
            foreach ($s['brackets'] as [$x, $y]) {
                $key = ($y >> 2) * self::COLS + ($x >> 1);
                $reg[$key][] = [self::BITS[$y & 3][$x & 1], self::CLS_BOX, $gi];
            }
        }
        return $reg;
    }

    /** @return list<array{dots: list<array{int, int}>, brackets: list<array{int, int}>, length: int, type: string}> */
    public function streakList(): array
    {
        return $this->streaks;
    }

    /** @return array{int, list<string>, list<string>, list<array{length: int, type: string}>} */
    public function summary(): array
    {
        $model = self::buildModel();
        $meta = [];
        foreach ($this->streaks as $s) {
            $meta[] = ['length' => $s['length'], 'type' => $s['type']];
        }
        return [count($model['lit']), $model['A'], $model['B'], $meta];
    }
}
