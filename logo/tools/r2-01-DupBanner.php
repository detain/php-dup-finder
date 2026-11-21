<?php

declare(strict_types=1);

namespace Phpdup\Branding;

/**
 * "Twin Fusion" — a truecolor animated ANSI wordmark for phpdup.
 *
 * Two identical "DUP" marks (one cool blue, one violet) slide in from
 * opposite edges and collide at centre; the matching glyphs flash white,
 * then fuse into a single warm-gold wordmark with one highlight sweep.
 * Two copies becoming one = the refactor story (Clusterer finds two,
 * RefactorStage extracts one).
 *
 * Design contract (owner spec, round 2):
 *  - 24-bit SGR; falls back to 256-colour then plain text.
 *  - one-shot <= 1.5 s, <= 45 ticks, <= 30 fps, ends held on the final frame.
 *  - final logo <= 7 rows x <= 56 cols; no overflow at 80 cols.
 *  - frame(int $tick): string is PURE and seeded — byte-reproducible, no clock.
 *
 * This class is fully self-contained (no logo-kit dependency) so it drops
 * straight into a project as a single file.
 */
final class DupBanner
{
    /** Total animation ticks (<= 45 hard cap). Playback = TICKS * TICK_MS ms. */
    public const TICKS = 40;

    /** Rows of the canvas / final logo. */
    public const ROWS = 7;

    /** Columns of the canvas (<= 56 cap for the final logo). */
    public const COLS = 48;

    /** Width / height of the "DUP" mark in cells. */
    public const MARK_W = 17;
    public const MARK_H = 7;

    /** Per-tick wall-clock suggestion for the player (25 fps <= 30 fps cap). */
    public const TICK_MS = 37;

    private const X_CENTER = 15;        // intdiv(COLS - MARK_W, 2) rounded
    private const X_LEFT_START = 2;
    private const X_RIGHT_START = 29;

    // Palette (owner-specified).
    /** @var array{int,int,int} */
    private const BLUE   = [59, 130, 246];   // #3B82F6
    /** @var array{int,int,int} */
    private const VIOLET = [139, 92, 246];   // #8B5CF6
    /** @var array{int,int,int} */
    private const WHITE  = [255, 255, 255];  // flash
    /** @var array{int,int,int} */
    private const GOLD   = [245, 158, 11];   // #F59E0B fused mark
    /** @var array{int,int,int} */
    private const PALE   = [255, 240, 205];  // highlight sheen
    /** @var array{int,int,int} */
    private const BG     = [13, 11, 20];     // canvas (matches GIF renderer)

    // Phase boundaries (inclusive last tick of each phase).
    private const SLIDE_END = 15;   // 0..15   slide-in
    private const FLASH_END = 23;   // 16..23  collision flash (white pulse + glow)
    private const FUSE_END  = 31;   // 24..31  white -> gold fuse wave
    //                                    32..39 fuse hold + one highlight sweep

    /** @var array<string, list<string>> 7-row block glyphs, 5 cols each. */
    private const FONT = [
        'D' => ['11110', '10001', '10001', '10001', '10001', '10001', '11110'],
        'U' => ['10001', '10001', '10001', '10001', '10001', '10001', '01110'],
        'P' => ['11110', '10001', '10001', '11110', '10000', '10000', '10000'],
    ];

    /** @var list<list<bool>>|null MARK_H x MARK_W occupancy grid (built lazily). */
    private static ?array $mask = null;

    public function __construct(
        private readonly string $depth = 'tc',
    ) {
        if (!in_array($depth, ['tc', '256', 'plain'], true)) {
            throw new \InvalidArgumentException("Unknown colour depth: {$depth}");
        }
    }

    /**
     * Pick a depth from the environment: NO_COLOR/TERM=dumb wins for plain,
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

    /** @return list<list<bool>> */
    private static function mask(): array
    {
        if (self::$mask !== null) {
            return self::$mask;
        }
        /** @var list<list<bool>> $mask */
        $mask = [];
        for ($row = 0; $row < self::MARK_H; $row++) {
            $line = [];
            for ($col = 0; $col < self::MARK_W; $col++) {
                $line[] = false;
            }
            $mask[] = $line;
        }
        $col = 0;
        foreach (['D', 'U', 'P'] as $letter) {
            foreach (self::FONT[$letter] as $row => $bits) {
                for ($c = 0; $c < 5; $c++) {
                    if ($bits[$c] === '1') {
                        $mask[$row][$col + $c] = true;
                    }
                }
            }
            $col += 6; // 5 cols + 1 gap; the trailing gap of the last letter is unused
        }
        return self::$mask = $mask;
    }

    /**
     * The single source of truth: a deterministic, clock-free paint of tick $tick.
     * $tick is clamped to [0, TICKS-1]; anything >= TICKS-1 returns the held frame.
     */
    public function frame(int $tick): string
    {
        $tick = max(0, min(self::TICKS - 1, $tick));
        if ($this->depth === 'plain') {
            return $this->plain();
        }
        return self::encode($this->grid($tick), $this->depth);
    }

    /** Final held frame as a single paint (for one-shot / static display). */
    public function render(): string
    {
        return $this->frame(self::TICKS - 1);
    }

    /**
     * Full one-shot animation stream: hide cursor, paint every tick joined by
     * a cursor-up overwrite, leave the final frame held, then show cursor.
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

    /** Plain-text fallback: the settled gold mark, ASCII '#' blocks, ZERO escapes. */
    public function plain(): string
    {
        $mask = self::mask();
        $out = '';
        for ($row = 0; $row < self::ROWS; $row++) {
            $line = '';
            for ($col = 0; $col < self::COLS; $col++) {
                $x = $col - self::X_CENTER;
                $on = $x >= 0 && $x < self::MARK_W && $mask[$row][$x];
                $line .= $on ? '#' : ' ';
            }
            $out .= rtrim($line) . "\n";
        }
        return $out;
    }

    // ---------------------------------------------------------------- painting

    /**
     * Fresh 7 x COLS grid of unpainted (null) cells.
     *
     * @return list<list<array{int,int,int}|null>>
     */
    private static function blankGrid(): array
    {
        $grid = [];
        for ($row = 0; $row < self::ROWS; $row++) {
            $line = [];
            for ($col = 0; $col < self::COLS; $col++) {
                $line[] = null;
            }
            $grid[] = $line;
        }
        return $grid;
    }

    /**
     * @return list<list<array{int,int,int}|null>>  7 x COLS cell colour grid
     */
    private function grid(int $tick): array
    {
        $grid = self::blankGrid();

        if ($tick <= self::SLIDE_END) {
            return $this->paintSlide($grid, $tick);
        }

        $mask = self::mask();

        if ($tick <= self::FLASH_END) {
            return $this->paintFlash($grid, $mask, $tick);
        }

        $colored = $tick <= self::FUSE_END ? 'fuse' : 'hold';
        for ($row = 0; $row < self::MARK_H; $row++) {
            for ($x = 0; $x < self::MARK_W; $x++) {
                if (!$mask[$row][$x]) {
                    continue;
                }
                $rgb = $colored === 'fuse'
                    ? $this->fuseColor($tick, $x)
                    : $this->holdColor($tick, $x);
                $grid[$row][self::X_CENTER + $x] = self::shade($rgb, $row);
            }
        }
        return $grid;
    }

    /**
     * @param list<list<array{int,int,int}|null>> $grid
     * @return list<list<array{int,int,int}|null>>
     */
    private function paintSlide(array $grid, int $tick): array
    {
        $mask = self::mask();
        $p = $tick / self::SLIDE_END;                       // 0..1 slide progress
        $leftX = (int) round(self::X_LEFT_START + (self::X_CENTER - self::X_LEFT_START) * $p);
        $rightX = (int) round(self::X_RIGHT_START + (self::X_CENTER - self::X_RIGHT_START) * $p);

        foreach ([[$leftX, self::BLUE], [$rightX, self::VIOLET]] as [$ox, $rgb]) {
            for ($row = 0; $row < self::MARK_H; $row++) {
                for ($x = 0; $x < self::MARK_W; $x++) {
                    if ($mask[$row][$x] && $ox + $x < self::COLS) {
                        $grid[$row][$ox + $x] = self::shade($rgb, $row);
                    }
                }
            }
        }
        return $grid;
    }

    /**
     * Collision: both marks are superimposed at centre -> the whole glyph is
     * white, with an expanding glow that fades across the flash window.
     *
     * @param list<list<array{int,int,int}|null>> $grid
     * @param list<list<bool>>                    $mask
     * @return list<list<array{int,int,int}|null>>
     */
    private function paintFlash(array $grid, array $mask, int $tick): array
    {
        $u = ($tick - (self::SLIDE_END + 1)) / max(1, self::FLASH_END - self::SLIDE_END - 1); // 0..1
        for ($row = 0; $row < self::MARK_H; $row++) {
            for ($x = 0; $x < self::MARK_W; $x++) {
                if ($mask[$row][$x]) {
                    $grid[$row][self::X_CENTER + $x] = self::shade(self::WHITE, $row);
                }
            }
        }

        // Glow halo: empty cells touching the mark, brightness fading out.
        $halo = 0.55 * (1.0 - $u);
        if ($halo <= 0.01) {
            return $grid;
        }
        $gx0 = self::X_CENTER - 1;
        $gx1 = self::X_CENTER + self::MARK_W;          // always within COLS
        for ($row = 0; $row < self::MARK_H; $row++) {
            for ($gx = $gx0; $gx <= $gx1; $gx++) {
                if ($grid[$row][$gx] !== null) {
                    continue;
                }
                if ($this->touchesMark($mask, $row, $gx - self::X_CENTER)) {
                    $grid[$row][$gx] = self::mix(self::BG, self::WHITE, $halo);
                }
            }
        }
        return $grid;
    }

    /** @param list<list<bool>> $mask */
    private function touchesMark(array $mask, int $row, int $x): bool
    {
        foreach ([[-1, 0], [1, 0], [0, -1], [0, 1]] as [$dr, $dx]) {
            $r = $row + $dr;
            $c = $x + $dx;
            if ($r >= 0 && $r < self::MARK_H && $c >= 0 && $c < self::MARK_W && $mask[$r][$c]) {
                return true;
            }
        }
        return false;
    }

    /** @return array{int,int,int} */
    private function fuseColor(int $tick, int $x): array
    {
        $span = self::FUSE_END - self::FLASH_END;                       // 8
        $w = ($tick - self::FLASH_END - 1) / $span;                     // 0..1
        $amount = self::clamp01($w * 1.7 - ($x / (self::MARK_W - 1)) * 0.7);
        return self::mix(self::WHITE, self::GOLD, $amount);
    }

    /** @return array{int,int,int} */
    private function holdColor(int $tick, int $x): array
    {
        $span = (self::TICKS - 1) - self::FUSE_END;                     // 8
        $s = ($tick - self::FUSE_END - 1) / max(1, $span - 1);          // 0..1
        $head = -2.5 + $s * (self::MARK_W + 5);                          // sweep head, exits right
        $dx = abs($x - $head);
        if ($dx < 2.2) {
            $sheen = self::clamp01((1.0 - $dx / 2.2) * 0.9);
            return self::mix(self::GOLD, self::PALE, $sheen);
        }
        return self::GOLD;
    }

    // ------------------------------------------------------------- colour math

    /**
     * @param array{int,int,int} $c
     * @return array{int,int,int}
     */
    private static function shade(array $c, int $row): array
    {
        $f = 0.82 + 0.18 * ($row / (self::MARK_H - 1));
        return [
            (int) round($c[0] * $f),
            (int) round($c[1] * $f),
            (int) round($c[2] * $f),
        ];
    }

    /**
     * @param array{int,int,int} $a
     * @param array{int,int,int} $b
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

    // --------------------------------------------------------------- encoding

    /**
     * @param list<list<array{int,int,int}|null>> $grid
     */
    private static function encode(array $grid, string $depth): string
    {
        $out = '';
        foreach ($grid as $row) {
            $curBg = '49';
            $line = '';
            foreach ($row as $cell) {
                $b = $cell === null ? '49' : self::sgr($cell, $depth);
                if ($b !== $curBg) {
                    $line .= "\e[{$b}m";
                    $curBg = $b;
                }
                $line .= ' ';
            }
            $out .= $line . "\e[0m\n";
        }
        return $out;
    }

    /** @param array{int,int,int} $c @return string 48;… SGR params (no \e[m) */
    private static function sgr(array $c, string $depth): string
    {
        if ($depth === '256') {
            return '48;5;' . self::to256($c);
        }
        return "48;2;{$c[0]};{$c[1]};{$c[2]}";
    }

    /**
     * Nearest xterm-256 palette entry (0..255) for a truecolor rgb.
     *
     * @param array{int,int,int} $c
     */
    private static function to256(array $c): int
    {
        static $pal = null;
        /** @var array<string,int> $memo */
        static $memo = [];
        if ($pal === null) {
            /** @var array<int,array{int,int,int}> $pal */
            $pal = [];
            /** @var array<int,int> $s */
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
        foreach ($pal as $n => $p) {
            $dr = $c[0] - $p[0];
            $dg = $c[1] - $p[1];
            $db = $c[2] - $p[2];
            $d = $dr * $dr + $dg * $dg + $db * $db;
            if ($d < $bd) {
                $bd = $d;
                $best = (int) $n;
            }
        }
        return $memo[$key] = $best;
    }
}
