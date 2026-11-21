<?php

declare(strict_types=1);

namespace Phpdup\Branding;

/**
 * "Debt Skyline" — a truecolor animated ANSI wordmark for phpdup.
 *
 * A city skyline at dusk: six towers (one per source directory) rise from
 * the ground line, each as tall as its duplicated-line count, windows glowing
 * warm yellow. The dashed red CI gate — the BaselineStore line — draws across
 * the sky; every tower poking above it flashes red ("new duplication"). Then
 * the towers are demolished floor by floor, ░▒ dust drifting down, until
 * they are cut down to exactly the letters PHPDUP: the letters ARE the
 * buildings, lit windows glowing inside their strokes while the moon rises.
 *
 * Design contract (owner spec, round 3):
 *  - 24-bit SGR (38;2 / 48;2), falls back to 256-colour, then plain text.
 *  - one-shot <= 1.5 s, <= 45 ticks, <= 30 fps, then the final frame HOLDS.
 *  - canvas 7 rows x 48 cols (<= 56 cap), safe at 80 columns.
 *  - frame(int $tick): string is PURE and seeded: byte-reproducible, clock-free.
 *  - half-blocks double the sky gradient to 14 vertical colour stops.
 *
 * Self-contained: no logo-kit, no deps. Drop-in single file.
 */
final class DupBanner
{
    /** Animation ticks (hard cap 45). Playback = TICKS * TICK_MS ms. */
    public const TICKS = 44;

    /** Per-tick wall-clock suggestion (34 ms => 29.4 fps <= 30 fps cap). */
    public const TICK_MS = 34;

    /** Canvas rows / cols (final logo <= 7 x <= 56). */
    public const ROWS = 7;
    public const COLS = 48;

    // ---- phase boundaries (inclusive last tick of each phase) --------------
    // 0..8    skyline rises           (~306 ms)
    // 9..14   gate draws, violators flash (~204 ms)
    // 15..37  demolition to the letters (~782 ms)
    // 38..43  windows light across the wordmark, moon rises (~204 ms)
    private const RISE_END = 8;
    private const BASE_END = 14;
    private const DEMO_END = 37;
    private const FIN_START = 38;

    // ---- layout -------------------------------------------------------------
    private const SUB = self::ROWS * 2;         // 14 half-row pixel lines
    private const LAST = self::SUB - 1;         // 13, the ground line
    private const GATE = 3;                     // CI-gate sub-row (BaselineStore)
    private const LET_TOP = 4;                  // wordmark occupies sub-rows 4..13
    private const WORD_X = 9;                   // x of the first letter column
    private const WORD_W = 29;                  // 6 letters x 4 cols + gaps

    // ---- palette (owner-specified) --------------------------------------------
    /** @var array{int,int,int} */ private const SKY_TOP = [30, 27, 75];    // #1E1B4B
    /** @var array{int,int,int} */ private const SKY_HZ  = [249, 115, 22];  // #F97316
    /** @var array{int,int,int} */ private const BLD     = [15, 23, 42];    // #0F172A towers
    /** @var array{int,int,int} */ private const WIN     = [253, 230, 138]; // #FDE68A windows
    /** @var array{int,int,int} */ private const WINDIM  = [122, 92, 38];   // unlit warm
    /** @var array{int,int,int} */ private const RED     = [239, 68, 68];   // #EF4444 gate
    /** @var array{int,int,int} */ private const DUST    = [100, 116, 139]; // #64748B
    /** @var array{int,int,int} */ private const MOON    = [254, 243, 199]; // #FEF3C7

    /**
     * Block-font letters, 5 rows x 4 cols. The letters ARE the buildings:
     * counters (bowl holes) read as sky shining through the towers.
     *
     * @var array<string, list<string>>
     */
    private const FONT = [
        'p' => ['1110', '1001', '1110', '1000', '1000'],
        'h' => ['1000', '1110', '1001', '1001', '1001'],
        'd' => ['0001', '0111', '1001', '1001', '0111'],
        'u' => ['0000', '1001', '1001', '1001', '0110'],
    ];

    /** @var list<string> the six towers, one per directory */
    private const WORD = ['p', 'h', 'p', 'd', 'u', 'p'];

    /**
     * Seeded default debt heights (duplicated-line count per directory):
     * the sub-row each tower's roof starts at. Smaller = taller; a roof above
     * GATE is a baseline violation that flashes red until it is cut down.
     *
     * @var list<int>
     */
    private const DEBT = [0, 3, 1, 3, 3, 1];

    /** @var array<int, int>|null tower index => first tick fully below the gate */
    private static ?array $clears = null;

    public function __construct(
        private readonly string $depth = 'tc',
    ) {
        if (!in_array($this->depth, ['tc', '256', 'plain'], true)) {
            throw new \InvalidArgumentException("Unknown colour depth: {$this->depth}");
        }
    }

    /**
     * Pick a depth from the environment: NO_COLOR / TERM=dumb win for plain,
     * COLORTERM truecolor/24bit wins for tc, otherwise 256-colour.
     */
    public static function fromEnv(): self
    {
        if (getenv('NO_COLOR') !== false && getenv('NO_COLOR') !== '') {
            return new self('plain');
        }
        if (getenv('TERM') === 'dumb') {
            return new self('plain');
        }
        $ct = (string) getenv('COLORTERM');
        if ($ct === 'truecolor' || $ct === '24bit') {
            return new self('tc');
        }
        return new self('256');
    }

    public function depth(): string
    {
        return $this->depth;
    }

    /**
     * Single source of truth: a deterministic, clock-free paint of tick $tick.
     * $tick is clamped to [0, TICKS-1]; anything >= TICKS-1 returns the hold.
     */
    public function frame(int $tick): string
    {
        $tick = max(0, min(self::TICKS - 1, $tick));
        if ($this->depth === 'plain') {
            return $this->plain();
        }
        [$sub, $shade] = $this->scene($tick);
        return $this->encode($sub, $shade);
    }

    /** Final held frame as a single paint. */
    public function render(): string
    {
        return $this->frame(self::TICKS - 1);
    }

    /**
     * Full one-shot stream: hide cursor, paint every tick joined by a
     * cursor-up overwrite, leave the final frame held, show cursor.
     */
    public function play(): string
    {
        if ($this->depth === 'plain') {
            return $this->plain();
        }
        $frames = [];
        for ($t = 0; $t < self::TICKS; $t++) {
            $frames[] = $this->frame($t);
        }
        return "\e[?25l" . implode("\e[" . self::ROWS . "A\r", $frames) . "\e[?25h";
    }

    /** Plain-text fallback: the settled wordmark, '#' blocks, ZERO escapes. */
    public function plain(): string
    {
        $out = '';
        for ($r = intdiv(self::LET_TOP, 2); $r <= intdiv(self::LAST, 2); $r++) {
            $line = '';
            for ($x = 0; $x < self::COLS; $x++) {
                $line .= $this->inkAt($r * 2, $x) || $this->inkAt($r * 2 + 1, $x) ? '#' : ' ';
            }
            $out .= rtrim($line) . "\n";
        }
        return $out;
    }

    // ------------------------------------------------------------- geometry --

    /**
     * Letter ink mask at sub-row $s, column $x. The wordmark sits at rows
     * 2..6 (sub-rows 4..13); font '0' bits are counters = sky through walls.
     */
    private function inkAt(int $s, int $x): bool
    {
        if ($s < self::LET_TOP || $s > self::LAST) {
            return false;
        }
        $wx = $x - self::WORD_X;
        if ($wx < 0 || $wx >= self::WORD_W) {
            return false;
        }
        $c = $wx % 5;
        if ($c > 3) {
            return false; // inter-letter gap
        }
        $li = intdiv($wx, 5);
        $r = intdiv($s - self::LET_TOP, 2);
        return self::FONT[self::WORD[$li]][$r][$c] === '1';
    }

    /** First (lowest-numbered) ink sub-row of a letter column. */
    private function colTop(int $x): int
    {
        for ($s = self::LET_TOP; $s <= self::LAST; $s++) {
            if ($this->inkAt($s, $x)) {
                return $s;
            }
        }
        return self::SUB;
    }

    /** Window pattern inside a letter stroke (warm lit windows). */
    private function winAt(int $s, int $x): bool
    {
        $wx = $x - self::WORD_X;
        $c = $wx % 5;
        $r = intdiv($s - self::LET_TOP, 2);
        if ($r % 2 === 1) {
            return $c % 2 === 0 || $r === 3;
        }
        return $r === 4 && $c === 0;
    }

    /**
     * First tick whose demolition has cut each tower's debt floors fully
     * below the gate line, derived from the SAME lerp the renderer uses so
     * the red flash stops exactly when the debris drops off.
     *
     * @return array<int, int>
     */
    private static function towerClears(): array
    {
        if (self::$clears !== null) {
            return self::$clears;
        }
        $self = new self('tc');
        $span = max(1, self::DEMO_END - (self::BASE_END + 1));
        $clears = [];
        foreach (self::DEBT as $i => $debtTop) {
            $x0 = self::WORD_X + $i * 5;
            $target = max($self->colTop($x0), $self->colTop($x0 + 1), $self->colTop($x0 + 2), $self->colTop($x0 + 3));
            $clear = self::DEMO_END;
            for ($t = self::BASE_END + 1; $t <= self::DEMO_END; $t++) {
                $u = ($t - (self::BASE_END + 1)) / $span;
                $v = self::clamp01(($u - $i * 0.07) / 0.65);
                $f = $v * $v * (3 - 2 * $v); // smoothstep
                $cur = (int) round($debtTop + ($target - $debtTop) * $f);
                if ($cur >= self::GATE) {
                    $clear = $t;
                    break;
                }
            }
            $clears[$i] = $clear;
        }
        return self::$clears = $clears;
    }

    // --------------------------------------------------------------- scene ---

    /**
     * Build the 14 x 48 sub-row colour buffer and the per-sub-row shade-glyph
     * override grid for one tick. Every cell holds a colour: nothing is ever
     * "empty", the sky is simply painted behind everything.
     *
     * @return array{list<list<array{int,int,int}>>, list<list<string>>}
     */
    private function scene(int $tick): array
    {
        $sub = [];
        $shade = [];
        $solid = [];
        for ($s = 0; $s < self::SUB; $s++) {
            $sub[$s] = [];
            $shade[$s] = array_fill(0, self::COLS, '');
            $solid[$s] = array_fill(0, self::COLS, false);
            // Sky: dusk gradient, top indigo -> horizon orange. Half-blocks
            // give it 14 stops instead of 7.
            $t = ($s / self::LAST) ** 1.6;
            $hz = self::mix(self::SKY_TOP, self::SKY_HZ, $t);
            for ($x = 0; $x < self::COLS; $x++) {
                $sub[$s][$x] = $hz;
            }
        }

        // Moon: rises late, fades in across the final phase (saturates by hold).
        if ($tick >= self::FIN_START) {
            $a = ($tick - self::FIN_START) / max(1, self::TICKS - 1 - self::FIN_START);
            $a = min(1.0, $a * $a * 0.6 + $a * 0.45);
            foreach ([0, 1] as $my) {
                foreach ($this->moonCols($my) as $mx => $bright) {
                    $sub[$my][$mx] = self::mix($sub[$my][$mx], self::MOON, $bright * $a);
                }
            }
        }

        // Demolition progress (shared clock; each tower staggers off it).
        $u = match (true) {
            $tick <= self::RISE_END => self::easeOut(self::clamp01(($tick + 1) / (self::RISE_END + 1))),
            $tick <= self::BASE_END => 0.0, // unused; roofs frozen at DEBT
            $tick >= self::FIN_START => 1.0,
            default => ($tick - (self::BASE_END + 1)) / max(1, self::DEMO_END - (self::BASE_END + 1)),
        };
        $carve = $tick <= self::BASE_END
            ? 0.0
            : self::clamp01(($u - 0.45) / 0.55); // counters dissolve into sky as the cut deepens
        $flashOn = intdiv($tick - self::BASE_END, 2) % 2 === 0;
        $clears = self::towerClears();

        foreach (self::DEBT as $i => $debtTop) {
            $x0 = self::WORD_X + $i * 5;
            $violates = $debtTop < self::GATE;

            for ($k = 0; $k < 4; $k++) {
                $x = $x0 + $k;
                $fin = $this->colTop($x);
                if ($tick <= self::BASE_END) {
                    $top = $tick <= self::RISE_END
                        ? (int) round(self::LAST + ($debtTop - self::LAST) * $u)
                        : $debtTop;
                } else {
                    $v = self::clamp01(($u - $i * 0.07) / 0.65);
                    $f = $v * $v * (3 - 2 * $v);
                    $top = (int) round($debtTop + ($fin - $debtTop) * $f);
                }

                for ($s = $top; $s <= self::LAST; $s++) {
                    $inLetter = $s >= self::LET_TOP;
                    $ink = $inLetter && $this->inkAt($s, $x);
                    if ($inLetter && !$ink && $this->rand($x * 17 + $s * 3) < $carve) {
                        continue; // carved open: counter becomes sky
                    }

                    // gate violation: red pulse on every floor above the line
                    if ($violates && $s < self::GATE && $tick < $clears[$i] && $flashOn) {
                        $sub[$s][$x] = self::RED;
                        $solid[$s][$x] = true;
                        continue;
                    }

                    // warm windows: glow while the tower stands, then light up
                    if ($tick >= self::FIN_START && $inLetter && $ink) {
                        if ($this->winAt($s, $x)) {
                            $prog = (($tick - self::FIN_START) / max(1, self::TICKS - 1 - self::FIN_START)) * 1.2;
                            $lit = ($x - self::WORD_X) <= $prog * self::WORD_W;
                            $sub[$s][$x] = $lit
                                ? $this->winTint($x, $s)
                                : self::mix(self::WIN, self::WINDIM, 0.75);
                        } else {
                            $sub[$s][$x] = $s === $fin
                                ? self::mix(self::BLD, self::SKY_HZ, 0.22)
                                : self::BLD;
                        }
                        $solid[$s][$x] = true;
                        continue;
                    }

                    $cellRow = intdiv($s, 2);
                    $isWindow = $inLetter
                        ? $ink && $this->winAt($s, $x)
                        : ($s % 2 === 0 && $x % 2 === 0 && $this->rand($x * 31 + $s) < 0.5);

                    if ($isWindow) {
                        $f = $this->rand($x * 13 + $s * 7) * 0.35;
                        $sub[$s][$x] = self::mix(self::WIN, self::WINDIM, $f);
                    } elseif (!$inLetter && $s === $top) {
                        // sunlit parapet on the roofline, visible on dark sky
                        $sub[$s][$x] = self::mix(self::BLD, self::SKY_HZ, 0.45);
                    } elseif ($inLetter && $ink && $s === $fin) {
                        $sub[$s][$x] = self::mix(self::BLD, self::SKY_HZ, 0.22);
                    } else {
                        $sub[$s][$x] = self::BLD;
                    }
                    $solid[$s][$x] = true;
                }
            }
        }

        // Dust: debris shimmer drifting down off the falling roofs.
        if ($u > 0.0 && $u < 1.0 && $tick > self::BASE_END && $tick < self::FIN_START) {
            foreach (self::DEBT as $i => $debtTop) {
                $x0 = self::WORD_X + $i * 5;
                $v = self::clamp01(($u - $i * 0.07) / 0.65);
                if ($v <= 0.0 || $v >= 1.0) {
                    continue;
                }
                for ($k = 0; $k < 4; $k++) {
                    $x = $x0 + $k;
                    $f = $v * $v * (3 - 2 * $v);
                    $top = (int) round($debtTop + ($this->colTop($x) - $debtTop) * $f);
                    for ($dy = 1; $dy <= 5; $dy++) {
                        $s = $top + $dy;
                        if ($s > self::LAST || $solid[$s][$x]) {
                            continue;
                        }
                        if ($this->rand($x * 7 + 31) > 0.45) {
                            continue;
                        }
                        if ((intdiv($tick * 3, 4) + $x + $dy) % 4 !== 0) {
                            continue;
                        }
                        $fade = (7 - $dy) / 7;
                        $sub[$s][$x] = self::mix($sub[$s][$x], self::DUST, 0.9 * $fade);
                        $shade[$s][$x] = $fade > 0.45 ? '▒' : '░';
                    }
                }
            }
        }

        // Baseline: dashed red CI gate; draws left-to-right, then persists.
        $rev = $tick <= self::BASE_END
            ? ($tick - self::RISE_END) / max(1, self::BASE_END - self::RISE_END)
            : 1.0;
        $upto = (int) ceil($rev * self::COLS);
        for ($x = 0; $x < min($upto, self::COLS); $x++) {
            if ($x % 2 === 0) {
                $sub[self::GATE][$x] = self::RED;
                $solid[self::GATE][$x] = true;
            }
        }

        return [$sub, $shade];
    }

    /** @return array<int, float> column => brightness (sub-row 0 or 1) */
    private function moonCols(int $subRow): array
    {
        return $subRow === 0
            ? [43 => 0.9, 44 => 1.0]
            : [42 => 0.8, 43 => 1.0, 44 => 0.95, 45 => 0.7];
    }

    /**
     * Per-cell warm tint: slight hue drift so windows don't read flat.
     *
     * @return array{int,int,int}
     */
    private function winTint(int $x, int $s): array
    {
        $j = $this->rand($x * 5 + $s) * 0.12;
        return [
            (int) round(253 + (255 - 253) * $j),
            (int) round(230 + (243 - 230) * $j),
            (int) round(138 + (199 - 138) * $j),
        ];
    }

    // ---------------------------------------------------------- encoding ----

    /**
     * @param list<list<array{int,int,int}>> $sub
     * @param list<list<string>>             $shade
     */
    private function encode(array $sub, array $shade): string
    {
        $out = '';
        for ($r = 0; $r < self::ROWS; $r++) {
            $fg = null;
            $bg = null;
            $line = '';
            for ($x = 0; $x < self::COLS; $x++) {
                $top = $sub[$r * 2][$x];
                $bot = $sub[$r * 2 + 1][$x];
                $sh = $shade[$r * 2][$x] !== '' ? 'top' : ($shade[$r * 2 + 1][$x] !== '' ? 'bot' : '');
                if ($sh !== '') {
                    $f = $this->sgr($sh === 'top' ? $top : $bot, 'fg');
                    $b = $this->sgr($bot, 'bg');
                    if ($f !== $fg) {
                        $line .= "\e[{$f}m";
                        $fg = $f;
                    }
                    if ($b !== $bg) {
                        $line .= "\e[{$b}m";
                        $bg = $b;
                    }
                    $line .= $shade[$r * 2][$x] !== '' ? $shade[$r * 2][$x] : $shade[$r * 2 + 1][$x];
                    continue;
                }
                if ($this->same($top, $bot)) {
                    $f = $this->sgr($top, 'fg');
                    if ($f !== $fg) {
                        $line .= "\e[{$f}m";
                        $fg = $f;
                    }
                    if ($bg !== null) {
                        $line .= "\e[49m";
                        $bg = null;
                    }
                    $line .= '█';
                    continue;
                }
                $f = $this->sgr($top, 'fg');
                $b = $this->sgr($bot, 'bg');
                if ($f !== $fg) {
                    $line .= "\e[{$f}m";
                    $fg = $f;
                }
                if ($b !== $bg) {
                    $line .= "\e[{$b}m";
                    $bg = $b;
                }
                $line .= '▀';
            }
            $out .= $line . "\e[0m\n";
        }
        return $out;
    }

    /** @param array{int,int,int} $c */
    private function sgr(array $c, string $which): string
    {
        $code = $which === 'fg' ? '38' : '48';
        if ($this->depth === '256') {
            return $code . ';5;' . self::to256($c);
        }
        return "{$code};2;{$c[0]};{$c[1]};{$c[2]}";
    }

    /**
     * @param array{int,int,int} $a
     * @param array{int,int,int} $b
     */
    private function same(array $a, array $b): bool
    {
        return $a === $b;
    }

    // -------------------------------------------------------- colour math ----

    /** Deterministic 0..1 from an integer seed (no RNG state, frame-pure). */
    private function rand(int $n): float
    {
        $n = ($n ^ 61) ^ ($n >> 16);
        $n = ($n + ($n << 3)) & 0xFFFFFFFF;
        $n ^= intdiv($n, 16);
        $n = ($n * 0x27d4eb2d) & 0xFFFFFFFF;
        $n ^= intdiv($n, 32768);
        return ($n & 0xFFFF) / 65535;
    }

    /**
     * @param array{int,int,int} $a
     * @param array{int,int,int} $b
     *
     * @return array{int,int,int}
     */
    private static function mix(array $a, array $b, float $t): array
    {
        $t = self::clamp01($t);
        return [
            (int) round($a[0] + ($b[0] - $a[0]) * $t),
            (int) round($a[1] + ($b[1] - $a[1]) * $t),
            (int) round($a[2] + ($b[2] - $a[2]) * $t),
        ];
    }

    private static function clamp01(float $v): float
    {
        return $v < 0.0 ? 0.0 : ($v > 1.0 ? 1.0 : $v);
    }

    private static function easeOut(float $t): float
    {
        return 1 - (1 - $t) * (1 - $t);
    }

    /**
     * Nearest xterm-256 palette entry for a truecolor rgb.
     *
     * @param array{int,int,int} $c
     */
    private static function to256(array $c): int
    {
        static $pal = null;
        /** @var array<string, int> $memo */
        static $memo = [];
        if ($pal === null) {
            /** @var array<int, array{int,int,int}> $pal */
            $pal = [];
            /** @var list<int> $s */
            $s = [0, 95, 135, 175, 215, 255];
            $n = 0;
            for ($r = 0; $r < 6; $r++) {
                for ($g = 0; $g < 6; $g++) {
                    for ($b = 0; $b < 6; $b++) {
                        $pal[16 + $n] = [$s[$r], $s[$g], $s[$b]];
                        $n++;
                    }
                }
            }
            for ($i = 0; $i < 24; $i++) {
                $v = 8 + $i * 10;
                $pal[232 + $i] = [$v, $v, $v];
            }
        }
        $key = "{$c[0]},{$c[1]},{$c[2]}";
        if (isset($memo[$key])) {
            return $memo[$key];
        }
        $best = 16;
        $bd = INF;
        foreach ($pal as $idx => $p) {
            $d = ($c[0] - $p[0]) ** 2 + ($c[1] - $p[1]) ** 2 + ($c[2] - $p[2]) ** 2;
            if ($d < $bd) {
                $bd = $d;
                $best = (int) $idx;
            }
        }
        return $memo[$key] = $best;
    }
}
