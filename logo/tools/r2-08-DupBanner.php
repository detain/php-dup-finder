<?php

declare(strict_types=1);

namespace Phpdup\Cli;

/**
 * DupBanner — "Jigsaw Snap": animated truecolor ANSI wordmark for phpdup.
 *
 * The wordmark "phpdup" is assembled from chunky rounded puzzle pieces.
 * Each piece is a beveled brick embossed with its letter and stamped with a
 * tiny white code-glyph rune ($ { } -> ;). Pieces fly in with a spring ease,
 * snap home with a one-cell shake, then hold.
 *
 * Interchangeable-by-rule: a piece's fill is keyed by (shape, content).
 * The three "p" bricks are the same SHAPE, but only the two carrying the
 * same rune ("$") share a fill — they read as interchangeable clones; the
 * odd one out (";") earns a different colour. Ties to phpdup's core idea:
 * parameterizable clones as interchangeable parts.
 *
 * Hard constraints honoured:
 *  - one-shot <= 1.5 s (45 ticks @ 30 fps = 1.485 s), then holds final frame;
 *  - final logo <= 7 rows x <= 56 cols (actual: 5 rows x 53 cols);
 *  - truecolor (38;2) with 256-colour (38;5) and plain (zero escapes) fallback;
 *  - pure and deterministic: frame(int $tick) never reads the clock.
 *
 * Self-contained (no vendor deps) so it can be dropped in as-is later.
 */
final class DupBanner
{
    public const FRAMES    = 45;      // ticks 0..44  (<= 45 allowed)
    public const LAST_TICK = self::FRAMES - 1;
    public const TICK_MS   = 33;      // 30 fps ceiling
    public const ROWS      = 5;       // terminal rows of the final frame
    public const COLS      = 56;      // hard column budget (content <= 53)

    public const DEPTH_TC    = 'tc';
    public const DEPTH_256   = '256';
    public const DEPTH_PLAIN = 'plain';

    /** Pixel canvas: 10 rows -> 5 cell-rows, 56 cols budget. */
    private const H = 10;
    private const W = self::COLS;

    // ---------------------------------------------------------------- palette
    private const RED    = [239, 68, 68];    // #EF4444
    private const YELLOW = [250, 204, 21];   // #FACC15
    private const BLUE   = [59, 130, 246];   // #3B82F6
    private const STUD   = [248, 250, 252];  // #F8FAFC

    /** Fill keyed by shape+content: identical bricks share a fill. */
    private const FILLS = [
        'p$' => self::BLUE,   // used twice  -> interchangeable pair
        'p;' => self::RED,    // same shape, other content -> other fill
        'h{' => self::YELLOW,
        'd}' => self::BLUE,
        'u-' => self::YELLOW,
    ];

    /**
     * Pieces, left to right: letter shape, rune content, fly-in offset,
     * entry tick, home pixel column/row. 6 pieces (cap: 12).
     * @var list<array{letter:string, mark:string, dx:int, dy:int, entry:int, hx:int, hy:int}>
     */
    private const PIECES = [
        ['letter' => 'p', 'mark' => '$', 'dx' => -20, 'dy' => 6,  'entry' => 0,  'hx' => 1,  'hy' => 1],
        ['letter' => 'h', 'mark' => '{', 'dx' => -26, 'dy' => 2,  'entry' => 6,  'hx' => 10, 'hy' => 0],
        ['letter' => 'p', 'mark' => ';', 'dx' => -32, 'dy' => -4, 'entry' => 12, 'hx' => 19, 'hy' => 1],
        ['letter' => 'd', 'mark' => '}', 'dx' => 18,  'dy' => 6,  'entry' => 18, 'hx' => 28, 'hy' => 0],
        ['letter' => 'u', 'mark' => '-', 'dx' => 6,   'dy' => -4, 'entry' => 24, 'hx' => 37, 'hy' => 1],
        ['letter' => 'p', 'mark' => '$', 'dx' => 2,   'dy' => -4, 'entry' => 30, 'hx' => 46, 'hy' => 0],
    ];

    /** One-piece tab+white-stud protrudes right (pieces 0..4); the next
     *  piece has a matching 2x4 socket notch bitten into its left edge. */

    /** Letter masks: 7 rows x 5 cols, 1 = embossed. */
    private const LETTERS = [
        'p' => [[1, 1, 1, 1, 0], [1, 0, 0, 0, 1], [1, 0, 0, 0, 1], [1, 1, 1, 1, 0], [1, 0, 0, 0, 0], [1, 0, 0, 0, 0], [1, 0, 0, 0, 0]],
        'h' => [[1, 0, 0, 0, 0], [1, 0, 0, 0, 0], [1, 1, 1, 1, 0], [1, 0, 0, 0, 1], [1, 0, 0, 0, 1], [1, 0, 0, 0, 1], [1, 0, 0, 0, 1]],
        'd' => [[0, 0, 0, 0, 1], [0, 0, 0, 0, 1], [1, 0, 0, 1, 1], [1, 0, 1, 0, 1], [1, 0, 1, 0, 1], [1, 0, 1, 0, 1], [1, 1, 1, 1, 0]],
        'u' => [[1, 0, 0, 0, 1], [1, 0, 0, 0, 1], [1, 0, 0, 0, 1], [1, 0, 0, 0, 1], [1, 0, 0, 0, 1], [1, 0, 0, 1, 1], [0, 1, 1, 1, 0]],
    ];

    /** Rune stamps: 5 lit-cells top-down in the face's mark column. */
    private const MARKS = [
        '$' => [1, 1, 1, 1, 1],
        '{' => [1, 0, 1, 0, 1],
        '}' => [0, 1, 0, 1, 1],
        '-' => [1, 1, 1, 0, 0],   // "->" arrow, abstracted
        ';' => [1, 0, 0, 0, 1],
    ];

    /** One-cell snap shake, keyed by post-entry tick (whole cells). */
    private const SHAKE = [11 => [1, 0], 12 => [-1, 0], 13 => [0, 1]];

    private const SPRING_K = 14; // ticks from entry to settle

    // ================================================================ public

    /**
     * Pure, deterministic truecolor frame. frame(0) and frame(LAST_TICK) are
     * byte-reproducible across processes; no wall-clock, no rng.
     */
    public static function frame(int $tick): string
    {
        return self::render($tick, self::DEPTH_TC);
    }

    /** Frame at a given depth: tc | 256 | plain. */
    public static function render(int $tick, string $depth): string
    {
        if ($tick < 0 || $tick >= self::FRAMES) {
            throw new \InvalidArgumentException("DupBanner tick out of range: {$tick}");
        }
        if (!in_array($depth, [self::DEPTH_TC, self::DEPTH_256, self::DEPTH_PLAIN], true)) {
            throw new \InvalidArgumentException("DupBanner unknown depth: {$depth}");
        }
        return self::encode(self::grid($tick), $depth);
    }

    /** Fully assembled, settled hold frame. */
    public static function finalFrame(string $depth = self::DEPTH_TC): string
    {
        return self::render(self::LAST_TICK, $depth);
    }

    /** Static plain-text fallback: zero escape codes. */
    public static function text(): string
    {
        return self::finalFrame(self::DEPTH_PLAIN);
    }

    /**
     * One-shot animation byte stream: hides cursor, plays 45 frames
     * (~1.5 s at 30 fps), leaves the final frame painted, shows cursor.
     * depth null -> resolveDepth() from environment. Plain -> static text.
     */
    public static function play(?string $depth = null): string
    {
        $depth ??= self::resolveDepth();
        if ($depth === self::DEPTH_PLAIN) {
            return self::finalFrame($depth);
        }
        $parts = [];
        for ($t = 0; $t < self::FRAMES; $t++) {
            $parts[] = self::render($t, $depth);
        }
        return "\e[?25l" . implode("\e[" . self::ROWS . "A\r", $parts) . "\e[?25h";
    }

    /** Fallback chain: NO_COLOR/TERM=dumb -> plain, COLORTERM -> tc, TERM*256* -> 256, else plain. */
    public static function resolveDepth(): string
    {
        if (self::env('NO_COLOR') !== null) {
            return self::DEPTH_PLAIN;
        }
        $term = (string) (self::env('TERM') ?? '');
        if ($term === 'dumb') {
            return self::DEPTH_PLAIN;
        }
        $ct = strtolower((string) (self::env('COLORTERM') ?? ''));
        if ($ct === 'truecolor' || $ct === '24bit') {
            return self::DEPTH_TC;
        }
        if (str_contains($term, '256color')) {
            return self::DEPTH_256;
        }
        return self::DEPTH_PLAIN;
    }

    // ================================================================ geometry

    /**
     * Compose the pixel grid for a tick.
     * @return array<int, array<int, array{int,int,int}|null>>
     */
    private static function grid(int $tick): array
    {
        $px = array_fill(0, self::H, array_fill(0, self::W, null));

        $drawn = [];
        foreach (self::PIECES as $i => $piece) {
            $k = $tick - $piece['entry'];
            if ($k < 0) {
                continue;
            }
            [$ox, $oy] = self::offset($piece, $k);
            $drawn[] = [$i, $ox, $oy];
        }

        foreach ($drawn as [$i, $ox, $oy]) {
            self::paintBrick($px, $i, $ox, $oy);
        }
        foreach ($drawn as [$i, $ox, $oy]) {
            self::paintTab($px, $i, $ox, $oy);
        }
        foreach ($drawn as [$i, $ox, $oy]) {
            self::paintStud($px, $i, $ox, $oy);
        }
        return $px;
    }

    /**
     * Damped-back spring offset (easeOutBack) + discrete one-cell snap shake.
     * k >= SPRING_K -> exactly home (guarantees a clean final frame).
     * @param array{letter:string, mark:string, dx:int, dy:int, entry:int, hx:int, hy:int} $piece
     * @return array{int,int}
     */
    private static function offset(array $piece, int $k): array
    {
        if ($k >= self::SPRING_K) {
            return [0, 0];
        }
        $u = $k / self::SPRING_K;
        $c1 = 1.70158;
        $c3 = $c1 + 1.0;
        $s  = 1.0 + $c3 * ($u - 1.0) ** 3 + $c1 * ($u - 1.0) ** 2;
        $ox = (int) round($piece['dx'] * (1.0 - $s));
        $oy = (int) round($piece['dy'] * (1.0 - $s));
        if (isset(self::SHAKE[$k])) {
            $ox += self::SHAKE[$k][0] * 2;
            $oy += self::SHAKE[$k][1] * 2;
        }
        return [$ox, $oy];
    }

    /**
     * Brick: 7x9 rounded chunky piece. Face carries the embossed letter;
     * mark column carries the rune. Pieces 1.. carry a left-edge socket notch.
     * @param array<int, array<int, array{int,int,int}|null>> $px
     */
    private static function paintBrick(array &$px, int $i, int $ox, int $oy): void
    {
        $piece = self::PIECES[$i];
        $fill  = self::FILLS[$piece['letter'] . $piece['mark']];
        $glyph = self::LETTERS[$piece['letter']];
        $mark  = self::MARKS[$piece['mark']];

        for ($r = 0; $r <= 8; $r++) {
            for ($c = 0; $c <= 6; $c++) {
                $rounded = ($r === 0 || $r === 8) && ($c === 0 || $c === 6);
                if ($rounded) {
                    continue;
                }
                if ($i > 0 && $c <= 1 && $r >= 3 && $r <= 6) {
                    continue; // socket notch (transparent channel)
                }
                if ($r === 0) {
                    $col = self::shade($fill, 0.30);           // top lip
                } elseif ($r === 8) {
                    $col = self::shade($fill, -0.40);          // base shadow
                } elseif ($c === 0) {
                    $col = self::shade($fill, -0.30);          // left edge
                } elseif ($c === 6) {
                    $lit     = $r <= 5 && $mark[$r - 1] === 1;
                    $col      = $lit ? self::STUD : self::shade($fill, -0.10);
                } else {
                    $base   = self::shade($fill, 0.10 - 0.24 * (($r - 1) / 7));
                    $lr     = $r - 1;                          // letter band rows 1..7
                    $lrB    = $lr + 1;
                    $lrA    = $lr - 1;
                    $lit    = $glyph[$lr][$c - 1] === 1;
                    $below  = $lrB <= 6 && $glyph[$lrB][$c - 1] === 1;
                    $above  = $lrA >= 0 && $glyph[$lrA][$c - 1] === 1;
                    $col    = $lit ? self::shade($fill, -0.52) : ($above ? self::shade($fill, 0.28) : $base);
                }
                self::setPx($px, $piece['hx'] + $c + $ox, $piece['hy'] + $r + $oy, $col);
            }
        }
    }

    /**
     * Tab: 2-wide rounded protrusion on the right edge (all but last piece).
     * @param array<int, array<int, array{int,int,int}|null>> $px
     */
    private static function paintTab(array &$px, int $i, int $ox, int $oy): void
    {
        if ($i < 0 || $i >= count(self::PIECES) - 1) {
            return;
        }
        $piece = self::PIECES[$i];
        $fill  = self::FILLS[$piece['letter'] . $piece['mark']];
        $shape = [2 => [1], 3 => [1, 2], 4 => [1, 2], 5 => [1, 2], 6 => [1]];
        foreach ($shape as $r => $cols) {
            foreach ($cols as $dc) {
                $col = $dc === 1 ? self::shade($fill, 0.14) : self::shade($fill, -0.05);
                self::setPx($px, $piece['hx'] + 6 + $dc + $ox, $piece['hy'] + $r + $oy, $col);
            }
        }
    }

    /**
     * White stud riding the tab (drawn last so it fits into the neighbour's
     * notch even when that neighbour is still flying).
     * @param array<int, array<int, array{int,int,int}|null>> $px
     */
    private static function paintStud(array &$px, int $i, int $ox, int $oy): void
    {
        if ($i < 0 || $i >= count(self::PIECES) - 1) {
            return;
        }
        $piece = self::PIECES[$i];
        for ($r = 4; $r <= 5; $r++) {
            for ($dc = 1; $dc <= 2; $dc++) {
                self::setPx($px, $piece['hx'] + 6 + $dc + $ox, $piece['hy'] + $r + $oy, self::STUD);
            }
        }
    }

    /**
     * @param array<int, array<int, array{int,int,int}|null>> $px
     * @param array{int,int,int}|null $col
     */
    private static function setPx(array &$px, int $x, int $y, ?array $col): void
    {
        if ($x < 0 || $x >= self::W || $y < 0 || $y >= self::H || $col === null) {
            return;
        }
        $px[$y][$x] = $col;
    }

    // ================================================================ colour

    /**
     * f>0 tints toward white, f<0 toward black.
     * @param array{int,int,int} $c
     * @return array{int,int,int}
     */
    private static function shade(array $c, float $f): array
    {
        $out = [];
        foreach ($c as $i => $v) {
            $out[$i] = (int) max(0, min(255, round($f >= 0 ? $v + (255 - $v) * $f : $v * (1.0 + $f))));
        }
        /** @var array{int,int,int} $out */
        return $out;
    }

    /**
     * Nearest xterm-256 index (16..255) for an rgb, memoised.
     * @param array{int,int,int} $c
     */
    private static function to256(array $c): int
    {
        static $memo = [];
        $key = $c[0] . ',' . $c[1] . ',' . $c[2];
        if (isset($memo[$key])) {
            return $memo[$key];
        }
        $best = 16;
        $bd   = -1.0;
        $cube = [0, 95, 135, 175, 215, 255];
        for ($n = 16; $n < 232; $n++) {
            $j  = $n - 16;
            $rgb = [$cube[intdiv($j, 36)], $cube[min(5, abs(intdiv($j, 6) % 6))], $cube[$j % 6]];
            $d  = self::dist2($c, $rgb);
            if ($bd < 0 || $d < $bd) {
                $bd  = $d;
                $best = $n;
            }
        }
        for ($i = 0; $i < 24; $i++) {
            $v   = 8 + $i * 10;
            $d   = self::dist2($c, [$v, $v, $v]);
            if ($d < $bd) {
                $bd   = $d;
                $best = 232 + $i;
            }
        }
        return $memo[$key] = $best;
    }

    /**
     * Redmean squared colour distance.
     * @param array{int,int,int} $a
     * @param array{int,int,int} $b
     */
    private static function dist2(array $a, array $b): float
    {
        $rm = ($a[0] + $b[0]) / 2;
        $dr = $a[0] - $b[0];
        $dg = $a[1] - $b[1];
        $db = $a[2] - $b[2];
        return (2 + $rm / 256) * $dr * $dr + 4 * $dg * $dg + (2 + (255 - $rm) / 256) * $db * $db;
    }

    // ================================================================ encode

    /**
     * Pixel grid -> ANSI cell text (half-block glyphs, fg-only where the
     * partner pixel is empty). tc/256 lines end with \e[0m; plain is bare.
     * @param array<int, array<int, array{int,int,int}|null>> $px
     */
    private static function encode(array $px, string $depth): string
    {
        $out = '';
        for ($y = 0; $y < self::ROWS; $y++) {
            $line  = '';
            $curFg = '39';
            $curBg = '49';
            for ($x = 0; $x < self::W; $x++) {
                $t = $px[2 * $y][$x];
                $b = $px[2 * $y + 1][$x];

                if ($t === null && $b === null) {
                    $line .= ' ';
                    continue;
                }
                if ($depth === self::DEPTH_PLAIN) {
                    $line .= match (true) {
                        $t !== null && $b !== null => $t === $b ? '#' : '%',
                        $t !== null => '-',
                        default => '_',
                    };
                    continue;
                }
                if ($t !== null && $b !== null && $t === $b) {
                    $glyph = '█';
                    $bg    = null;
                } elseif ($b === null) {
                    $glyph = '▀';
                    $bg    = null;
                } elseif ($t === null) {
                    $glyph = '▄';
                    $bg    = null;
                    $t     = $b;
                } else {
                    $glyph = '▀';
                    $bg    = $b;
                }
                $fgCode = self::sgr($t, false, $depth);
                if ($fgCode !== $curFg) {
                    $line .= "\e[{$fgCode}m";
                    $curFg = $fgCode;
                }
                if ($bg !== null) {
                    $bgCode = self::sgr($bg, true, $depth);
                    if ($bgCode !== $curBg) {
                        $line .= "\e[{$bgCode}m";
                        $curBg = $bgCode;
                    }
                } elseif ($curBg !== '49') {
                    $line .= "\e[49m";
                    $curBg = '49';
                }
                $line .= $glyph;
            }
            $out .= rtrim($line, ' ') . ($depth === self::DEPTH_PLAIN ? '' : "\e[0m") . "\n";
        }
        return $out;
    }

    /** @param array{int,int,int} $c */
    private static function sgr(array $c, bool $bg, string $depth): string
    {
        return match ($depth) {
            self::DEPTH_TC   => ($bg ? '48' : '38') . ";2;{$c[0]};{$c[1]};{$c[2]}",
            self::DEPTH_256  => ($bg ? '48' : '38') . ';5;' . self::to256($c),
            default          => throw new \LogicException('plain has no SGR'),
        };
    }

    private static function env(string $key): ?string
    {
        $v = $_ENV[$key] ?? getenv($key);
        return $v === false || $v === '' ? null : (string) $v;
    }
}
